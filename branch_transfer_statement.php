<?php
// transfer_statement.php — Inter-branch Transfer & Payment Ledger (per counter-branch)
// Perspective: selected Branch. Positive balance => Counter-branch owes us (we shipped out more than paid).
// Negative balance => We owe them (we received more than we paid).

require_once 'includes/header.php';
checkRole(['admin','manager','cashier']);

/* -------------------- tiny utils -------------------- */
function t_table_exists(PDO $pdo, string $t): bool {
  try { $pdo->query("SELECT 1 FROM `$t` LIMIT 1"); return true; } catch(Throwable $e){ return false; }
}
function t_col_exists(PDO $pdo, string $t, string $c): bool {
  try { $st=$pdo->prepare("SHOW COLUMNS FROM `$t` LIKE ?"); $st->execute([$c]); return (bool)$st->fetch(PDO::FETCH_ASSOC); }
  catch(Throwable $e){ return false; }
}
function fmt($n){ return 'Rs. ' . number_format((float)$n,2); }

/* -------------------- branch scoping (same style as your other pages) -------------------- */
$is_admin = ($user['role_name'] ?? '') === 'admin';

$branch_id = 0;
if ($is_admin) {
  if (isset($_GET['branch_id']) && ctype_digit($_GET['branch_id'])) {
    $branch_id = (int)$_GET['branch_id'];
    $_SESSION['sale_branch_id'] = $branch_id;
  } elseif (isset($_SESSION['sale_branch_id'])) {
    $branch_id = (int)$_SESSION['sale_branch_id'];
  } elseif (!empty($user['branch_id'])) {
    $branch_id = (int)$user['branch_id'];
  }
} else {
  $branch_id = (int)($user['branch_id'] ?? 0);
}

if ($branch_id <= 0) {
  include 'includes/nav.php';
  echo "<div class='container mt-4'><div class='alert alert-warning'>No branch selected.</div></div>";
  exit;
}

/* -------------------- filters: date + counter branch -------------------- */
$today = new DateTime();
$first = (clone $today)->modify('first day of this month')->format('Y-m-d');
$last  = (clone $today)->modify('last day of this month')->format('Y-m-d');

$from = (isset($_GET['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'])) ? $_GET['from'] : $first;
$to   = (isset($_GET['to'])   && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to']))   ? $_GET['to']   : $last;
$fromDT = $from.' 00:00:00';
$toDT   = $to.' 23:59:59';

$counter_id = (isset($_GET['counter_id']) && ctype_digit($_GET['counter_id'])) ? (int)$_GET['counter_id'] : 0;

/* -------------------- lookups -------------------- */
$branches = [];
$branch_name = '';
try {
  $branches = $pdo->query("SELECT id,name FROM branches WHERE is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
  $st = $pdo->prepare("SELECT name FROM branches WHERE id=?"); $st->execute([$branch_id]); $branch_name = (string)$st->fetchColumn();
} catch(Throwable $e){}

/* -------------------- schema flags -------------------- */
$hasST  = t_table_exists($pdo,'stock_transfers');       // id, from_branch_id, to_branch_id, transferred_at  (:contentReference[oaicite:2]{index=2})
$hasSTI = t_table_exists($pdo,'stock_transfer_items');  // transfer_id, batch_id, quantity                    (:contentReference[oaicite:3]{index=3})
$hasSB  = t_table_exists($pdo,'stock_batches');         // id, cost_price, ...
$hasTP  = t_table_exists($pdo,'transfer_payments');     // schema may vary across installs
$w = []; // warnings

// try detect common columns in transfer_payments
$tp_cols = [
  'id'           => t_col_exists($pdo,'transfer_payments','id'),
  'transfer_id'  => t_col_exists($pdo,'transfer_payments','transfer_id'),
  'amount'       => t_col_exists($pdo,'transfer_payments','amount'),
  'paid_at'      => t_col_exists($pdo,'transfer_payments','paid_at') || t_col_exists($pdo,'transfer_payments','payment_date') || t_col_exists($pdo,'transfer_payments','created_at'),
  'from_branch'  => t_col_exists($pdo,'transfer_payments','from_branch_id'),
  'to_branch'    => t_col_exists($pdo,'transfer_payments','to_branch_id'),
  'method'       => t_col_exists($pdo,'transfer_payments','method'),
];

if (!$hasST || !$hasSTI || !$hasSB) {
  $w[] = "Required tables missing: need stock_transfers, stock_transfer_items, stock_batches.";
}

/* -------------------- build per-bank-like ledger rows -------------------- */
/*
   Unified row:
   [
     id    => string,
     ts    => 'YYYY-mm-dd HH:ii:ss',
     desc  => string,
     debit => float,   // money out from US / reduces liability / increases receivable
     credit=> float,   // money in to US   / reduces receivable / increases liability
   ]
   Balance rule (perspective of selected Branch):
   + When WE SEND goods (stock_transfers.from_branch_id = our branch):
       => Counter owes us (receivable). We treat as DEBIT (increase positive balance).
   + When WE RECEIVE goods (to_branch_id = our branch):
       => We owe them (payable). We treat as CREDIT (decrease balance).
   + Payments:
       * If WE receive a payment from them (for shipments we made) => CREDIT (reduce receivable).
       * If WE pay them (for shipments we received) => DEBIT (reduce payable / push balance up).
   Running balance = sum(debit - credit). Positive => they owe us; Negative => we owe them.
*/
$rows = [];

// Map of branch id->name for dropdown
$branchMap = [];
foreach ($branches as $b) $branchMap[(int)$b['id']] = $b['name'];

// If no counter selected, pick the first other active branch
if ($counter_id <= 0) {
  foreach ($branches as $b) {
    if ((int)$b['id'] !== $branch_id) { $counter_id = (int)$b['id']; break; }
  }
}

/* -------------------- transfers valuation -------------------- */
if ($hasST && $hasSTI && $hasSB) {
  // Outgoing (we are from_branch) => receivable increase (DEBIT)
  try {
    $sql = "
      SELECT st.id, st.transferred_at AS ts,
             COALESCE(SUM(sti.quantity * sb.cost_price),0) AS amount
      FROM stock_transfers st
      JOIN stock_transfer_items sti ON sti.transfer_id = st.id
      JOIN stock_batches sb         ON sb.id = sti.batch_id
      WHERE st.from_branch_id = :me
        AND st.to_branch_id   = :counter
        AND st.transferred_at BETWEEN :f AND :t
      GROUP BY st.id, st.transferred_at
      ORDER BY st.transferred_at, st.id
    ";
    $st=$pdo->prepare($sql);
    $st->execute([':me'=>$branch_id, ':counter'=>$counter_id, ':f'=>$fromDT, ':t'=>$toDT]);
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
      $rows[] = [
        'id'     => 'TFR#'.$r['id'],
        'ts'     => $r['ts'],
        'desc'   => 'Transfer OUT to '.$branchMap[$counter_id] ?? ('Branch #'.$counter_id),
        'debit'  => (float)$r['amount'],
        'credit' => 0.0
      ];
    }
  } catch (Throwable $e) {}

  // Incoming (we are to_branch) => payable increase (CREDIT)
  try {
    $sql = "
      SELECT st.id, st.transferred_at AS ts,
             COALESCE(SUM(sti.quantity * sb.cost_price),0) AS amount
      FROM stock_transfers st
      JOIN stock_transfer_items sti ON sti.transfer_id = st.id
      JOIN stock_batches sb         ON sb.id = sti.batch_id
      WHERE st.to_branch_id   = :me
        AND st.from_branch_id = :counter
        AND st.transferred_at BETWEEN :f AND :t
      GROUP BY st.id, st.transferred_at
      ORDER BY st.transferred_at, st.id
    ";
    $st=$pdo->prepare($sql);
    $st->execute([':me'=>$branch_id, ':counter'=>$counter_id, ':f'=>$fromDT, ':t'=>$toDT]);
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
      $rows[] = [
        'id'     => 'TFR#'.$r['id'],
        'ts'     => $r['ts'],
        'desc'   => 'Transfer IN from '.$branchMap[$counter_id] ?? ('Branch #'.$counter_id),
        'debit'  => 0.0,
        'credit' => (float)$r['amount']
      ];
    }
  } catch (Throwable $e) {}
}

/* -------------------- payments between branches -------------------- */
if ($hasTP) {
  // try to project a sensible select despite schema variations
  // prefer paid_at, else payment_date, else created_at (date-only fallback)
  $tp_date_col = null;
  foreach (['paid_at','payment_date','created_at'] as $c) { if (t_col_exists($pdo,'transfer_payments',$c)) { $tp_date_col = $c; break; } }
  $tp_amt_col  = t_col_exists($pdo,'transfer_payments','amount') ? 'amount' : null;
  $tp_from_col = t_col_exists($pdo,'transfer_payments','from_branch_id') ? 'from_branch_id' : null;
  $tp_to_col   = t_col_exists($pdo,'transfer_payments','to_branch_id')   ? 'to_branch_id'   : null;

  if (!$tp_amt_col) $w[] = "transfer_payments: missing amount column.";
  if (!$tp_date_col) $w[] = "transfer_payments: missing paid_at/payment_date/created_at column.";

  if ($tp_amt_col && $tp_date_col && $tp_from_col && $tp_to_col) {
    try {
      $sql = "
        SELECT id, $tp_date_col AS ts, $tp_amt_col AS amt, $tp_from_col AS from_b, $tp_to_col AS to_b,
               ".(t_col_exists($pdo,'transfer_payments','method') ? "method" : "NULL AS method")."
        FROM transfer_payments
        WHERE ($tp_from_col=:me AND $tp_to_col=:counter)
           OR ($tp_from_col=:counter AND $tp_to_col=:me)
          AND $tp_date_col BETWEEN :f AND :t
        ORDER BY $tp_date_col, id
      ";
      $st=$pdo->prepare($sql);
      $st->execute([':me'=>$branch_id, ':counter'=>$counter_id, ':f'=>$fromDT, ':t'=>$toDT]);
      while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $amt = (float)$r['amt'];
        $isWePaid   = ((int)$r['from_b'] === $branch_id);   // we sent payment -> DEBIT (we pay them)
        $isWeGot    = ((int)$r['to_b']   === $branch_id);   // we received payment -> CREDIT
        $desc = 'Transfer Payment';
        if (!empty($r['method'])) $desc .= ' ('.ucfirst($r['method']).')';
        $desc .= $isWePaid ? (' to '.$branchMap[$counter_id] ?? ('Branch #'.$counter_id))
                           : (' from '.$branchMap[$counter_id] ?? ('Branch #'.$counter_id));
        $rows[] = [
          'id'     => 'TP#'.$r['id'],
          'ts'     => (strpos($r['ts'],' ')===false ? ($r['ts'].' 00:00:00') : $r['ts']),
          'desc'   => $desc,
          'debit'  => $isWePaid ? $amt : 0.0,
          'credit' => $isWeGot  ? $amt : 0.0
        ];
      }
    } catch (Throwable $e) {}
  } else {
    $w[] = "transfer_payments: missing from_branch_id/to_branch_id columns; cannot attribute direction.";
  }
}

/* -------------------- Opening balance, sort & running balance -------------------- */
// To display a meaningful running balance, include transactions prior to the selected
// date range. We compute an opening balance representing the net amount owed (or due)
// between this branch and the counter-branch up to the day before the filter start.
$opening = 0.0;

// Compute transfers before the range
if ($hasST && $hasSTI && $hasSB) {
  // Outgoing transfers (we sent goods) increase receivable (debit)
  try {
    $sqlOpen = "SELECT COALESCE(SUM(sti.quantity * sb.cost_price),0) FROM stock_transfers st
                 JOIN stock_transfer_items sti ON sti.transfer_id = st.id
                 JOIN stock_batches sb         ON sb.id = sti.batch_id
                 WHERE st.from_branch_id = :me AND st.to_branch_id = :counter AND st.transferred_at < :f";
    $stOpen  = $pdo->prepare($sqlOpen);
    $stOpen->execute([':me'=>$branch_id, ':counter'=>$counter_id, ':f'=>$fromDT]);
    $opening += (float)$stOpen->fetchColumn();
  } catch (Throwable $e) {}
  // Incoming transfers (we received goods) increase payable (credit)
  try {
    $sqlOpen = "SELECT COALESCE(SUM(sti.quantity * sb.cost_price),0) FROM stock_transfers st
                 JOIN stock_transfer_items sti ON sti.transfer_id = st.id
                 JOIN stock_batches sb         ON sb.id = sti.batch_id
                 WHERE st.to_branch_id = :me AND st.from_branch_id = :counter AND st.transferred_at < :f";
    $stOpen  = $pdo->prepare($sqlOpen);
    $stOpen->execute([':me'=>$branch_id, ':counter'=>$counter_id, ':f'=>$fromDT]);
    $opening -= (float)$stOpen->fetchColumn();
  } catch (Throwable $e) {}
}

// Compute payments before the range
if ($hasTP) {
  // Determine column names dynamically; fallback to nulls if missing
  $tp_date_col = null;
  foreach (['paid_at','payment_date','created_at'] as $c) { if (t_col_exists($pdo,'transfer_payments',$c)) { $tp_date_col = $c; break; } }
  $tp_amt_col  = t_col_exists($pdo,'transfer_payments','amount') ? 'amount' : null;
  $tp_from_col = t_col_exists($pdo,'transfer_payments','from_branch_id') ? 'from_branch_id' : null;
  $tp_to_col   = t_col_exists($pdo,'transfer_payments','to_branch_id')   ? 'to_branch_id'   : null;
  if ($tp_amt_col && $tp_date_col && $tp_from_col && $tp_to_col) {
    try {
      $sqlOpen = "SELECT $tp_amt_col AS amt, $tp_from_col AS from_b, $tp_to_col AS to_b
                  FROM transfer_payments
                  WHERE (($tp_from_col = :me AND $tp_to_col = :counter) OR ($tp_from_col = :counter AND $tp_to_col = :me))
                    AND $tp_date_col < :f";
      $stOpen  = $pdo->prepare($sqlOpen);
      $stOpen->execute([':me'=>$branch_id, ':counter'=>$counter_id, ':f'=>$fromDT]);
      while ($pr = $stOpen->fetch(PDO::FETCH_ASSOC)) {
        $amt  = (float)($pr['amt'] ?? 0);
        $isWePaid = ((int)$pr['from_b'] === $branch_id);
        $isWeGot  = ((int)$pr['to_b']   === $branch_id);
        if ($isWePaid)   { $opening += $amt; }
        elseif ($isWeGot){ $opening -= $amt; }
      }
    } catch (Throwable $e) {}
  }
}

// Sort the transaction rows chronologically then by ID
usort($rows, function($a,$b) {
  $c = strcmp($a['ts'], $b['ts']);
  if ($c !== 0) return $c;
  return strcmp((string)$a['id'], (string)$b['id']);
});

// Prepend opening balance row so ledger shows carry-forward amount
$openingRow = [
  'id'     => 'OPEN',
  'ts'     => $fromDT,
  'desc'   => 'Opening Balance',
  'debit'  => 0.0,
  'credit' => 0.0,
  'bal'    => $opening,
];
$rows = array_merge([$openingRow], $rows);

// Compute running balance starting from opening
$running = $opening;
foreach ($rows as &$r) {
  if ($r['id'] !== 'OPEN') {
    $running += (float)$r['debit'];
    $running -= (float)$r['credit'];
    $r['bal'] = $running;
  }
}
unset($r);

/* -------------------- UI -------------------- */
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Branch Transfer Payments Statement</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  body{background:#f6f7fb}
  .cardx{background:#fff;border:1px solid #eaeaea;border-radius:12px;box-shadow:0 3px 10px rgba(0,0,0,.05)}
  .gs-head{background:#e9b000;color:#000;font-weight:600}
  .gs-head th{padding:.6rem .75rem;border-right:1px solid #cfa100}
  .gs-head th:last-child{border-right:none}
  .gs-row td{padding:.55rem .75rem;vertical-align:middle}
  .num{text-align:right}
  .badge-ghost{background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;padding:4px 8px;color:#374151}
</style>
</head>
<body>
<?php include 'includes/nav.php'; ?>

<div class="container-fluid mt-3">
  <div class="d-flex align-items-center gap-2 mb-2">
    <h4 class="mb-0">Branch Transfer Payments Statement</h4>
    <span class="badge-ghost">Branch: <?= htmlspecialchars($branch_name ?: ('#'.$branch_id)) ?></span>
    <span class="badge-ghost">Counter: <?= htmlspecialchars($branchMap[$counter_id] ?? ('#'.$counter_id)) ?></span>
    <span class="badge-ghost"><?= htmlspecialchars($from) ?> → <?= htmlspecialchars($to) ?></span>
  </div>

  <?php if (!empty($w)): ?>
    <div class="alert alert-warning py-2">
      <div class="fw-bold mb-1">Schema notes</div>
      <ul class="mb-0">
        <?php foreach ($w as $msg): ?><li><?= htmlspecialchars($msg) ?></li><?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <div class="cardx p-3 mb-3">
    <form method="get" class="row gy-2 gx-2 align-items-end">
      <?php if ($is_admin): ?>
        <div class="col-auto">
          <label class="form-label mb-1">Branch</label>
          <select class="form-select" name="branch_id">
            <?php foreach ($branches as $b): ?>
              <option value="<?= (int)$b['id'] ?>" <?= ((int)$b['id']===$branch_id?'selected':'') ?>><?= htmlspecialchars($b['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      <?php else: ?>
        <input type="hidden" name="branch_id" value="<?= (int)$branch_id ?>">
      <?php endif; ?>
      <div class="col-auto">
        <label class="form-label mb-1">Counter-branch</label>
        <select class="form-select" name="counter_id">
          <?php foreach ($branches as $b): if ((int)$b['id']===$branch_id) continue; ?>
            <option value="<?= (int)$b['id'] ?>" <?= ((int)$b['id']===$counter_id?'selected':'') ?>><?= htmlspecialchars($b['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-auto">
        <label class="form-label mb-1">From</label>
        <input type="date" class="form-control" name="from" value="<?= htmlspecialchars($from) ?>">
      </div>
      <div class="col-auto">
        <label class="form-label mb-1">To</label>
        <input type="date" class="form-control" name="to" value="<?= htmlspecialchars($to) ?>">
      </div>
      <div class="col-auto"><button class="btn btn-primary">Apply</button></div>
    </form>
  </div>

  <div class="cardx p-3">
    <div class="table-responsive">
      <table class="table table-bordered mb-0">
        <thead class="gs-head">
          <tr>
            <th style="width:12%">ID</th>
            <th style="width:16%">Date/Time</th>
            <th>Description</th>
            <th style="width:14%">Debit</th>
            <th style="width:14%">Credit</th>
            <th style="width:14%">Balance</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($rows)): ?>
            <tr class="gs-row"><td colspan="6" class="text-center text-muted">No transactions for the selected range.</td></tr>
          <?php else: foreach ($rows as $r): ?>
            <tr class="gs-row">
              <td><?= htmlspecialchars($r['id']) ?></td>
              <td><?= htmlspecialchars($r['ts']) ?></td>
              <td><?= htmlspecialchars($r['desc']) ?></td>
              <td class="num"><?= $r['debit']  ? fmt($r['debit'])  : '' ?></td>
              <td class="num"><?= $r['credit'] ? fmt($r['credit']) : '' ?></td>
              <td class="num"><strong><?= fmt($r['bal']) ?></strong></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    <div class="small text-muted mt-2">
      Balance rule: positive = counter-branch owes you; negative = you owe them. Transfer valuations use batch cost_price × quantity. Running balance includes an Opening Balance computed from transactions prior to the selected date range. (Schemas: stock_transfers, stock_transfer_items.)
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
