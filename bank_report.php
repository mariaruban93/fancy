<?php
// bank_report.php — Simple per-bank ledger with All/Unassigned fallback + Opening balance

require_once 'includes/header.php';
checkRole(['admin', 'manager', 'cashier']);

/* -------------------- helpers -------------------- */
function column_exists(PDO $pdo, string $table, string $col): bool {
  try { $st = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?"); $st->execute([$col]); return (bool)$st->fetch(PDO::FETCH_ASSOC); }
  catch (Throwable $e) { return false; }
}
function table_exists(PDO $pdo, string $table): bool {
  try { $pdo->query("SELECT 1 FROM `$table` LIMIT 1"); return true; }
  catch (Throwable $e) { return false; }
}
function fmt($n){ return 'Rs. ' . number_format((float)$n, 2); }

/* -------------------- branch resolve -------------------- */
$branch_id = 0;
if ($user['role_name'] === 'admin') {
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
  echo "<div class='container mt-4'><div class='alert alert-warning'>No branch selected.</div></div>";
  exit;
}

/* -------------------- date defaults -------------------- */
$today = new DateTime();
$firstDay = (clone $today)->modify('first day of this month')->format('Y-m-d');
$lastDay  = (clone $today)->modify('last day of this month')->format('Y-m-d');

$from = (isset($_GET['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'])) ? $_GET['from'] : $firstDay;
$to   = (isset($_GET['to'])   && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to']))   ? $_GET['to']   : $lastDay;

$fromDT = $from . ' 00:00:00';
$toDT   = $to   . ' 23:59:59';

/* -------------------- banks list for this branch -------------------- */
$branches = [];
$branch_name = '';
try {
  if ($user['role_name'] === 'admin') {
    $branches = $pdo->query("SELECT id,name FROM branches WHERE is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
  }
  $nm = $pdo->prepare("SELECT name FROM branches WHERE id=?");
  $nm->execute([$branch_id]); $branch_name = (string)($nm->fetchColumn() ?: '');
} catch (Throwable $e) {}
if (!$branch_name && !empty($user['branch_name'])) $branch_name = $user['branch_name'];

$bankAccounts = [];
try {
  $st = $pdo->prepare("SELECT id, name, account_no, opening_balance FROM bank_accounts WHERE branch_id = ? ORDER BY name");
  $st->execute([$branch_id]);
  $bankAccounts = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

if (empty($bankAccounts)) {
  include 'includes/nav.php';
  echo "<div class='container mt-4'><div class='alert alert-danger'>No bank accounts configured for branch <strong>"
       . htmlspecialchars($branch_name) . "</strong>.</div></div>";
  exit;
}

// Map
$bankMap = [];
foreach ($bankAccounts as $ba) $bankMap[(int)$ba['id']] = $ba;

// Selected bank; now supports 0 = All
$bank_id = (isset($_GET['bank_id']) && ctype_digit($_GET['bank_id'])) ? (int)$_GET['bank_id'] : 0;
if ($bank_id !== 0 && !isset($bankMap[$bank_id])) $bank_id = 0;

/* -------------------- schema feature flags -------------------- */
$hasSP   = table_exists($pdo, 'sale_payments');
$hasSales= table_exists($pdo, 'sales');
$hasEXP  = table_exists($pdo, 'expenses');
$hasJE   = table_exists($pdo, 'journal_entries');

$hasSP_bank = $hasSP && column_exists($pdo, 'sale_payments', 'bank_account_id');
$hasEXP_bank= $hasEXP && column_exists($pdo, 'expenses', 'bank_account_id');
$hasJE_bank = $hasJE && column_exists($pdo, 'journal_entries', 'bank_account_id');

/* -------------------- Opening balance (simple) -------------------- */
/*
   opening = bank_accounts.opening_balance
           + inflows (<= from-1s)
           - outflows (<= from-1s)
   Only counted per-bank if attribution exists; otherwise included only when viewing All.
*/
$opening = 0.0;
if ($bank_id > 0) {
  $opening += (float)($bankMap[$bank_id]['opening_balance'] ?? 0);
} else {
  // All banks: sum all opening balances
  foreach ($bankAccounts as $ba) $opening += (float)($ba['opening_balance'] ?? 0);
}

// helper to add/sub movements up to fromDT
function add_opening_movements(PDO $pdo, int $branch_id, string $cutoffDT, int $bank_id, bool $hasCol, string $table, string $sqlBase, bool $isCredit){
  $sum = 0.0;
  try {
    $params = [':branch'=>$branch_id, ':to'=>$cutoffDT];
    $sql = $sqlBase;
    if ($bank_id>0) {
      if ($hasCol) { $sql .= " AND bank_account_id = :bank "; $params[':bank'] = $bank_id; }
      else { return 0.0; } // cannot attribute to a single bank if column missing
    }
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $sum = (float)$st->fetchColumn();
  } catch (Throwable $e) {}
  return $isCredit ? $sum : -$sum;
}

// Sales inflows to bank (non-cash methods incl cheque regardless of deposit here)
if ($hasSP && $hasSales) {
  $sql = "
    SELECT COALESCE(SUM(sp.amount),0)
    FROM sale_payments sp
    JOIN sales s ON s.id = sp.sale_id
    WHERE s.branch_id = :branch
      AND s.sale_date < :to
      AND sp.method IN ('card','upi','bank_transfer','cheque')
  ";
  $opening += add_opening_movements($pdo,$branch_id,$fromDT,$bank_id,$hasSP_bank,'sale_payments',$sql,true);
}

// Bank expenses (outflow)
if ($hasEXP) {
  $sql = "
    SELECT COALESCE(SUM(e.amount),0)
    FROM expenses e
    WHERE e.branch_id = :branch
      AND e.expense_date < DATE(:to)
      AND e.payment_method = 'bank'
  ";
  $opening += add_opening_movements($pdo,$branch_id,$fromDT,$bank_id,$hasEXP_bank,'expenses',$sql,false);
}

// Bank journals (±)
if ($hasJE) {
  // positive = credit to bank, negative = debit from bank
  $sqlPlus  = "SELECT COALESCE(SUM(CASE WHEN je.amount>0 THEN je.amount ELSE 0 END),0)
               FROM journal_entries je
               WHERE je.branch_id=:branch AND je.entry_date < DATE(:to) AND je.account_type='bank'";
  $sqlMinus = "SELECT COALESCE(SUM(CASE WHEN je.amount<0 THEN -je.amount ELSE 0 END),0)
               FROM journal_entries je
               WHERE je.branch_id=:branch AND je.entry_date < DATE(:to) AND je.account_type='bank'";
  $opening += add_opening_movements($pdo,$branch_id,$fromDT,$bank_id,$hasJE_bank,'journal_entries',$sqlPlus,true);
  $opening += add_opening_movements($pdo,$branch_id,$fromDT,$bank_id,$hasJE_bank,'journal_entries',$sqlMinus,false);
}

/* -------------------- collect rows for selected scope -------------------- */
$rows = [];

/* ---- Inflows from sale_payments (card, upi, bank_transfer, cheque) ---- */
if ($hasSP && $hasSales) {
  try {
    $sql = "
      SELECT sp.id AS sp_id, s.id AS sale_id, s.sale_date AS ts, sp.method, sp.amount
      ".($hasSP_bank ? ", sp.bank_account_id" : "")."
      FROM sale_payments sp
      JOIN sales s ON s.id = sp.sale_id
      WHERE s.branch_id = :branch
        AND s.sale_date BETWEEN :from AND :to
        AND sp.method IN ('card','upi','bank_transfer','cheque')
    ";
    $params = [':branch'=>$branch_id, ':from'=>$fromDT, ':to'=>$toDT];
    if ($bank_id>0 && $hasSP_bank) { $sql .= " AND sp.bank_account_id = :bank"; $params[':bank'] = $bank_id; }
    $sql .= " ORDER BY s.sale_date ASC, sp.id ASC";
    $st = $pdo->prepare($sql); $st->execute($params);

    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
      // If user selected a specific bank but there’s no column to attribute, skip
      if ($bank_id>0 && !$hasSP_bank) continue;

      // If viewing All (bank_id==0) and column exists but value is empty, still include (Unassigned)
      $rows[] = [
        'id'     => 'SP#' . (int)$r['sp_id'],
        'ts'     => $r['ts'],
        'desc'   => 'Sale #' . (int)$r['sale_id'] . ' (' . ucfirst($r['method']) . ')',
        'credit' => (float)$r['amount'],
        'debit'  => 0.0,
        'bank_id'=> $hasSP_bank ? (int)($r['bank_account_id'] ?? 0) : 0,
      ];
    }
  } catch (Throwable $e) {}
}

/* ---- Outflows from expenses (payment_method = bank) ---- */
if ($hasEXP) {
  try {
    $sql = "
      SELECT e.id, e.expense_date AS d, e.description, e.amount
      ".($hasEXP_bank ? ", e.bank_account_id" : "")."
      FROM expenses e
      WHERE e.branch_id = :branch
        AND e.expense_date BETWEEN :fromD AND :toD
        AND e.payment_method = 'bank'
    ";
    $params = [':branch'=>$branch_id, ':fromD'=>$from, ':toD'=>$to];
    if ($bank_id>0 && $hasEXP_bank) { $sql .= " AND e.bank_account_id = :bank"; $params[':bank'] = $bank_id; }
    $sql .= " ORDER BY e.expense_date ASC, e.id ASC";
    $st = $pdo->prepare($sql); $st->execute($params);

    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
      if ($bank_id>0 && !$hasEXP_bank) continue;
      $rows[] = [
        'id'     => 'EXP#' . (int)$r['id'],
        'ts'     => $r['d'] . ' 00:00:00',
        'desc'   => (string)$r['description'],
        'credit' => 0.0,
        'debit'  => (float)$r['amount'],
        'bank_id'=> $hasEXP_bank ? (int)($r['bank_account_id'] ?? 0) : 0,
      ];
    }
  } catch (Throwable $e) {}
}

/* ---- Journal entries (account_type = bank) ---- */
if ($hasJE) {
  try {
    $sql = "
      SELECT je.id, je.entry_date AS d, je.description, je.amount
      ".($hasJE_bank ? ", je.bank_account_id" : "")."
      FROM journal_entries je
      WHERE je.branch_id = :branch
        AND je.entry_date BETWEEN :fromD AND :toD
        AND je.account_type = 'bank'
    ";
    $params = [':branch'=>$branch_id, ':fromD'=>$from, ':toD'=>$to];
    if ($bank_id>0 && $hasJE_bank) { $sql .= " AND je.bank_account_id = :bank"; $params[':bank'] = $bank_id; }
    $sql .= " ORDER BY je.entry_date ASC, je.id ASC";
    $st = $pdo->prepare($sql); $st->execute($params);

    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
      if ($bank_id>0 && !$hasJE_bank) continue;
      $amt = (float)$r['amount'];
      $rows[] = [
        'id'     => 'JE#' . (int)$r['id'],
        'ts'     => $r['d'] . ' 00:00:00',
        'desc'   => (string)$r['description'],
        'credit' => $amt > 0 ? $amt : 0.0,
        'debit'  => $amt < 0 ? abs($amt) : 0.0,
        'bank_id'=> $hasJE_bank ? (int)($r['bank_account_id'] ?? 0) : 0,
      ];
    }
  } catch (Throwable $e) {}
}

/* -------------------- sort & compute running -------------------- */
usort($rows, function($a,$b){
  $c = strcmp($a['ts'], $b['ts']);
  if ($c !== 0) return $c;
  return strcmp((string)$a['id'], (string)$b['id']);
});

// Start with opening as running
$running = $opening;
$displayRows = [];

// Add an Opening row
$displayRows[] = [
  'id' => '—', 'ts' => $from.' 00:00:00', 'desc' => 'Opening', 'credit' => 0.0, 'debit' => 0.0, 'balance' => $running
];

foreach ($rows as $r) {
  $running += $r['credit'];
  $running -= $r['debit'];
  $r['balance'] = $running;
  $displayRows[] = $r;
}

/* -------------------- UI -------------------- */
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Bank Report (Simple Ledger)</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  body{background:#f6f7fb}
  .sheet-card{background:#fff;border:1px solid #eaeaea;border-radius:12px;box-shadow:0 3px 10px rgba(0,0,0,.05)}
  .gs-head{background:#e9b000;color:#000;font-weight:600}
  .gs-head th{padding:.6rem .75rem;border-right:1px solid #cfa100}
  .gs-head th:last-child{border-right:none}
  .gs-row td{padding:.55rem .75rem;vertical-align:middle}
  .gs-row td.num{text-align:right}
  .muted{color:#6c757d}
</style>
</head>
<body>
<?php include 'includes/nav.php'; ?>

<div class="container-fluid mt-4">
  <div class="d-flex flex-wrap align-items-end gap-3 mb-3">
    <div>
      <div class="form-text mb-1">Filters</div>
      <form method="get" class="row gy-2 gx-2">
        <?php if ($user['role_name'] === 'admin' && !empty($branches)): ?>
          <div class="col-auto">
            <select class="form-select" name="branch_id">
              <?php foreach ($branches as $b): ?>
                <option value="<?= (int)$b['id'] ?>" <?= ((int)$b['id']===$branch_id?'selected':'') ?>>
                  <?= htmlspecialchars($b['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        <?php else: ?>
          <input type="hidden" name="branch_id" value="<?= (int)$branch_id ?>">
        <?php endif; ?>

        <div class="col-auto">
          <select class="form-select" name="bank_id">
            <option value="0" <?= $bank_id===0?'selected':'' ?>>All Bank Accounts</option>
            <?php foreach ($bankAccounts as $ba): ?>
              <option value="<?= (int)$ba['id'] ?>" <?= ((int)$ba['id']===$bank_id?'selected':'') ?>>
                <?= htmlspecialchars($ba['name'] . (empty($ba['account_no']) ? '' : ' — '.$ba['account_no'])) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-auto"><input type="date" class="form-control" name="from" value="<?= htmlspecialchars($from) ?>"></div>
        <div class="col-auto"><input type="date" class="form-control" name="to"   value="<?= htmlspecialchars($to) ?>"></div>
        <div class="col-auto"><button class="btn btn-primary">Apply</button></div>
      </form>
    </div>
    <div class="ms-auto text-end small text-muted">
      Branch: <strong><?= htmlspecialchars($branch_name) ?></strong><br>
      Range: <strong><?= htmlspecialchars($from) ?></strong> to <strong><?= htmlspecialchars($to) ?></strong><br>
      Bank: <strong>
        <?php
          echo $bank_id===0 ? 'All Bank Accounts' : htmlspecialchars(($bankMap[$bank_id]['name'] ?? '#'.$bank_id));
        ?>
      </strong>
    </div>
  </div>

  <div class="sheet-card p-3">
    <div class="table-responsive">
      <table class="table table-bordered mb-0">
        <thead class="gs-head">
          <tr>
            <th style="width:10%">ID</th>
            <th style="width:14%">Date</th>
            <th>Description</th>
            <th style="width:14%">Credit</th>
            <th style="width:14%">Debit</th>
            <th style="width:14%">Balance</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($displayRows)): ?>
            <tr class="gs-row"><td colspan="6" class="text-muted text-center">No transactions for the selected period.</td></tr>
          <?php else: foreach ($displayRows as $r): ?>
            <tr class="gs-row">
              <td><?= htmlspecialchars($r['id']) ?></td>
              <td><?= htmlspecialchars($r['ts']) ?></td>
              <td>
                <?= htmlspecialchars($r['desc']) ?>
                <?php if (($r['id']!=='—') && isset($r['bank_id']) && $bank_id===0): ?>
                  <span class="muted"> — <?= $r['bank_id']>0 ? htmlspecialchars($bankMap[$r['bank_id']]['name'] ?? ('#'.$r['bank_id'])) : 'Unassigned' ?></span>
                <?php endif; ?>
              </td>
              <td class="num"><?= !empty($r['credit']) ? fmt($r['credit']) : '' ?></td>
              <td class="num"><?= !empty($r['debit'])  ? fmt($r['debit'])  : '' ?></td>
              <td class="num"><strong><?= fmt($r['balance']) ?></strong></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    <div class="small text-muted mt-2">
      *Opening = Opening balance + net movements before the selected From date (when attribution is possible).<br>
      *If your tables don’t have <code>bank_account_id</code> or it’s 0/NULL, rows appear under <em>Unassigned</em> only when “All Bank Accounts” is selected.
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
