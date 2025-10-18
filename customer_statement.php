<?php
// customer_statement.php — Simple per-customer ledger: ID | Date | Description | Credit | Debit | Balance
require_once 'includes/header.php';
checkRole(['admin','manager']);

/* ---------- helpers ---------- */
function column_exists(PDO $pdo, string $table, string $col): bool {
  try { $st=$pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?"); $st->execute([$col]); return (bool)$st->fetch(PDO::FETCH_ASSOC); }
  catch(Throwable $e){ return false; }
}
function table_exists(PDO $pdo, string $table): bool {
  try { $pdo->query("SELECT 1 FROM `$table` LIMIT 1"); return true; }
  catch(Throwable $e){ return false; }
}
function fmt($n){ return 'Rs. '.number_format((float)$n,2); }

/* ---------- branch resolve (same logic you use elsewhere) ---------- */
$branch_id = 0;
if ($user['role_name'] === 'admin') {
  if (isset($_GET['branch_id']) && ctype_digit($_GET['branch_id'])) {
    $branch_id = (int)$_GET['branch_id']; $_SESSION['sale_branch_id']=$branch_id;
  } elseif (isset($_SESSION['sale_branch_id'])) { $branch_id=(int)$_SESSION['sale_branch_id']; }
  elseif (!empty($user['branch_id'])) { $branch_id=(int)$user['branch_id']; }
} else { $branch_id = (int)($user['branch_id'] ?? 0); }
if ($branch_id<=0) { include 'includes/nav.php'; echo "<div class='container mt-4'><div class='alert alert-warning'>No branch selected.</div></div>"; exit; }

/* ---------- date defaults ---------- */
$today=new DateTime();
$from = isset($_GET['from'])&&preg_match('/^\d{4}-\d{2}-\d{2}$/',$_GET['from']) ? $_GET['from'] : $today->format('Y-m-01');
$to   = isset($_GET['to'])  &&preg_match('/^\d{4}-\d{2}-\d{2}$/',$_GET['to'])   ? $_GET['to']   : $today->format('Y-m-t');
$fromDT=$from.' 00:00:00'; $toDT=$to.' 23:59:59';

/* ---------- load branch name ---------- */
$branches=[]; $branch_name='';
try{
  if ($user['role_name']==='admin') $branches=$pdo->query("SELECT id,name FROM branches WHERE is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
  $st=$pdo->prepare("SELECT name FROM branches WHERE id=?"); $st->execute([$branch_id]); $branch_name=(string)($st->fetchColumn()?:'');
}catch(Throwable $e){}
if (!$branch_name && !empty($user['branch_name'])) $branch_name=$user['branch_name'];

/* ---------- build customer list (schema safe) ---------- */
$customers=[];
$hasCusBranch = column_exists($pdo,'customers','branch_id');
$hasCusActive = column_exists($pdo,'customers','is_active');

$sql="SELECT id,name FROM customers WHERE 1=1";
$params=[];
if ($hasCusActive) $sql.=" AND is_active=1";
if ($hasCusBranch) { $sql.=" AND (branch_id=:b OR branch_id IS NULL OR branch_id=0)"; $params[':b']=$branch_id; }
$sql.=" ORDER BY name";
try{
  $st=$pdo->prepare($sql); $st->execute($params); $customers=$st->fetchAll(PDO::FETCH_ASSOC);
}catch(Throwable $e){
  try{ $customers=$pdo->query("SELECT id,name FROM customers ORDER BY name")->fetchAll(PDO::FETCH_ASSOC); }catch(Throwable $e2){ $customers=[]; }
}
if (empty($customers)) { include 'includes/nav.php'; echo "<div class='container mt-4'><div class='alert alert-danger'>No customers found.</div></div>"; exit; }

$customer_id = (isset($_GET['customer_id']) && ctype_digit($_GET['customer_id'])) ? (int)$_GET['customer_id'] : (int)$customers[0]['id'];
$customerMap=[]; foreach($customers as $c) $customerMap[(int)$c['id']]=$c;
if (!isset($customerMap[$customer_id])) $customer_id=(int)$customers[0]['id'];

/* ---------- feature flags ---------- */
$hasSales   = table_exists($pdo,'sales');
$hasSP      = table_exists($pdo,'sale_payments');
$hasSR      = table_exists($pdo,'sale_returns');
$hasSRI     = table_exists($pdo,'sale_return_items'); // optional if you later want item details
$hasJE      = table_exists($pdo,'journal_entries');

$hasSalesCus = $hasSales && column_exists($pdo,'sales','customer_id');
$hasSBranch  = $hasSales && column_exists($pdo,'sales','branch_id');
$hasSPCus    = $hasSP    && column_exists($pdo,'sale_payments','customer_id'); // some schemas store only sale_id
$hasSPBranch = $hasSP    && column_exists($pdo,'sale_payments','branch_id');   // optional
$hasSRCus    = $hasSR    && column_exists($pdo,'sale_returns','customer_id');
$hasSRBranch = $hasSR    && column_exists($pdo,'sale_returns','branch_id');
$hasJECus    = $hasJE    && column_exists($pdo,'journal_entries','customer_id');
$hasJEBranch = $hasJE    && column_exists($pdo,'journal_entries','branch_id');

/* ---------- rows ---------- */
/* ---------- compute opening balance (carry-forward prior to selected range) ---------- */
$opening = 0.0;

// Sales before from date (increase receivable)
if ($hasSales && $hasSalesCus) {
  try {
    $sql = "SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE customer_id=:cid AND sale_date < :f";
    $params = [':cid'=>$customer_id, ':f'=>$fromDT];
    if ($hasSBranch) { $sql .= " AND branch_id=:b"; $params[':b']=$branch_id; }
    $st = $pdo->prepare($sql); $st->execute($params);
    $opening += (float)$st->fetchColumn();
  } catch (Throwable $e) {}
}

// Payments before from date (reduce receivable)
if ($hasSP) {
  try {
    if ($hasSPCus) {
      $datecol = column_exists($pdo,'sale_payments','paid_at') ? 'paid_at' : 'created_at';
      $sql = "SELECT COALESCE(SUM(amount),0) FROM sale_payments WHERE customer_id=:cid AND $datecol < :f";
      $params = [':cid'=>$customer_id, ':f'=>$fromDT];
      if ($hasSPBranch) { $sql .= " AND branch_id=:b"; $params[':b']=$branch_id; }
    } else {
      $datecol = column_exists($pdo,'sale_payments','paid_at') ? 'sp.paid_at' : 's.sale_date';
      $sql = "SELECT COALESCE(SUM(sp.amount),0) FROM sale_payments sp JOIN sales s ON s.id=sp.sale_id WHERE s.customer_id=:cid AND $datecol < :f";
      $params = [':cid'=>$customer_id, ':f'=>$fromDT];
      if ($hasSBranch) { $sql .= " AND s.branch_id=:b"; $params[':b']=$branch_id; }
    }
    $st = $pdo->prepare($sql); $st->execute($params);
    $opening -= (float)$st->fetchColumn();
  } catch (Throwable $e) {}
}

// Sale Returns before from date (reduce receivable)
if ($hasSR) {
  try {
    $datecol = column_exists($pdo,'sale_returns','return_date') ? 'return_date':'created_at';
    $amtcol  = column_exists($pdo,'sale_returns','total_refund') ? 'total_refund':'amount';
    $sql = "SELECT COALESCE(SUM(sr.$amtcol),0) FROM sale_returns sr JOIN sales s ON s.id=sr.sale_id WHERE s.customer_id=:cid AND sr.$datecol < :f";
    $params = [':cid'=>$customer_id, ':f'=>$fromDT];
    if ($hasSBranch) { $sql .= " AND s.branch_id=:b"; $params[':b']=$branch_id; }
    $st = $pdo->prepare($sql); $st->execute($params);
    $opening -= (float)$st->fetchColumn();
  } catch (Throwable $e) {}
}

// Customer journal entries before from date
if ($hasJE && $hasJECus) {
  try {
    $sql = "SELECT COALESCE(SUM(CASE WHEN amount<0 THEN -amount ELSE 0 END),0) AS debit, COALESCE(SUM(CASE WHEN amount>0 THEN amount ELSE 0 END),0) AS credit FROM journal_entries WHERE customer_id=:cid AND account_type='customer' AND entry_date < :f";
    $params = [':cid'=>$customer_id, ':f'=>$from];
    if ($hasJEBranch) { $sql .= " AND branch_id=:b"; $params[':b']=$branch_id; }
    $st = $pdo->prepare($sql); $st->execute($params);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: ['debit'=>0,'credit'=>0];
    $opening += (float)$row['debit'];
    $opening -= (float)$row['credit'];
  } catch (Throwable $e) {}
}

/*
   Convention used here (typical A/R):
   - Sales (invoice) => DEBIT (customer owes more)
   - Payments / Return (money/credit back) => CREDIT (reduce receivable)
   - Journal (account_type='customer'): amount>0 => CREDIT, amount<0 => DEBIT
*/
$rows=[];

/* Sales */
if ($hasSales && $hasSalesCus) {
  try{
    $sql="SELECT id, sale_date AS ts, total_amount FROM sales
          WHERE customer_id=:cid AND sale_date BETWEEN :f AND :t";
    $params=[':cid'=>$customer_id, ':f'=>$fromDT, ':t'=>$toDT];
    if ($hasSBranch) { $sql.=" AND branch_id=:b"; $params[':b']=$branch_id; }
    $sql.=" ORDER BY sale_date,id";
    $st=$pdo->prepare($sql); $st->execute($params);
    while($r=$st->fetch(PDO::FETCH_ASSOC)){
      $rows[]=[
        'id'=>'S#'.(int)$r['id'],
        'ts'=>$r['ts'],
        'desc'=>'Sale #'.(int)$r['id'],
        'credit'=>0.0,
        'debit'=>(float)$r['total_amount'],
      ];
    }
  }catch(Throwable $e){}
}

/* Payments (if table stores customer_id use it; else join sales) */
if ($hasSP) {
  try{
    if ($hasSPCus){
      $sql="SELECT id, paid_at AS ts, amount, method FROM sale_payments
            WHERE customer_id=:cid AND paid_at BETWEEN :f AND :t";
      $params=[':cid'=>$customer_id, ':f'=>$fromDT, ':t'=>$toDT];
      if ($hasSPBranch){ $sql.=" AND branch_id=:b"; $params[':b']=$branch_id; }
    }else{
      // join to sales to reach customer + branch
      $has_paid_at = column_exists($pdo,'sale_payments','paid_at');
      $datecol = $has_paid_at ? 'sp.paid_at' : 's.sale_date';
      $sql="SELECT sp.id, $datecol AS ts, sp.amount, sp.method
            FROM sale_payments sp
            JOIN sales s ON s.id=sp.sale_id
            WHERE s.customer_id=:cid AND $datecol BETWEEN :f AND :t";
      $params=[':cid'=>$customer_id, ':f'=>$fromDT, ':t'=>$toDT];
      if ($hasSBranch){ $sql.=" AND s.branch_id=:b"; $params[':b']=$branch_id; }
    }
    $sql.=" ORDER BY ts, id";
    $st=$pdo->prepare($sql); $st->execute($params);
    while($r=$st->fetch(PDO::FETCH_ASSOC)){
      $rows[]=[
        'id'=>'SP#'.(int)$r['id'],
        'ts'=>$r['ts'],
        'desc'=>'Payment ('.strtoupper((string)$r['method']).')',
        'credit'=>(float)$r['amount'],
        'debit'=>0.0,
      ];
    }
  }catch(Throwable $e){}
}

/* Sale Returns (reduce receivable) */
if ($hasSR) {
  try{
    $datecol = column_exists($pdo,'sale_returns','return_date') ? 'return_date':'created_at';
    $amtcol  = column_exists($pdo,'sale_returns','total_refund') ? 'total_refund':'amount';
    $sql="SELECT sr.id, sr.$datecol AS ts, sr.$amtcol AS amt
          FROM sale_returns sr
          JOIN sales s ON s.id=sr.sale_id
          WHERE s.customer_id=:cid AND sr.$datecol BETWEEN :f AND :t";
    $params=[':cid'=>$customer_id, ':f'=>$fromDT, ':t'=>$toDT];
    if ($hasSBranch) { $sql.=" AND s.branch_id=:b"; $params[':b']=$branch_id; }
    $sql.=" ORDER BY sr.$datecol, sr.id";
    $st=$pdo->prepare($sql); $st->execute($params);
    while($r=$st->fetch(PDO::FETCH_ASSOC)){
      $rows[]=[
        'id'=>'SR#'.(int)$r['id'],
        'ts'=>$r['ts'],
        'desc'=>'Sale Return #'.(int)$r['id'],
        'credit'=>(float)$r['amt'],
        'debit'=>0.0,
      ];
    }
  }catch(Throwable $e){}
}

/* Customer Journals */
if ($hasJE && $hasJECus) {
  try{
    $sql="SELECT id, entry_date AS d, description, amount
          FROM journal_entries
          WHERE customer_id=:cid AND account_type='customer' AND entry_date BETWEEN :f AND :t";
    $params=[':cid'=>$customer_id, ':f'=>$from, ':t'=>$to];
    if ($hasJEBranch){ $sql.=" AND branch_id=:b"; $params[':b']=$branch_id; }
    $sql.=" ORDER BY entry_date,id";
    $st=$pdo->prepare($sql); $st->execute($params);
    while($r=$st->fetch(PDO::FETCH_ASSOC)){
      $amt=(float)$r['amount'];
      $rows[]=[
        'id'=>'JE#'.(int)$r['id'],
        'ts'=>$r['d'].' 00:00:00',
        'desc'=>(string)$r['description'],
        'credit'=>$amt>0?$amt:0.0,
        'debit' =>$amt<0?abs($amt):0.0,
      ];
    }
  }catch(Throwable $e){}
}

/* sort and running */
// Sort rows chronologically before computing balances
usort($rows,function($a,$b){ $c=strcmp($a['ts'],$b['ts']); return $c!==0?$c:strcmp((string)$a['id'],(string)$b['id']); });

// Prepend opening balance row
$openingRow = [
  'id'   => 'OPEN',
  'ts'   => $from.' 00:00:00',
  'desc' => 'Opening Balance',
  'credit'=>0.0,
  'debit' =>0.0,
  'balance'=>$opening,
];
$rows = array_merge([$openingRow], $rows);

// Compute running balance starting from opening
$running = $opening;
foreach($rows as &$r){
  if ($r['id'] !== 'OPEN') {
    // In A/R convention: debit increases receivable, credit reduces
    $running += $r['debit'];
    $running -= $r['credit'];
  }
  $r['balance']=$running;
}
unset($r);

/* ---------- UI ---------- */
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Customer Statement</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  body{background:#f6f7fb}
  .sheet-card{background:#fff;border:1px solid #eaeaea;border-radius:12px;box-shadow:0 3px 10px rgba(0,0,0,.05)}
  .gs-head{background:#e9b000;color:#000;font-weight:600}
  .gs-head th{padding:.6rem .75rem;border-right:1px solid #cfa100}
  .gs-head th:last-child{border-right:none}
  .gs-row td{padding:.55rem .75rem;vertical-align:middle}
  .gs-row td.num{text-align:right}
</style>
</head>
<body>
<?php include 'includes/nav.php'; ?>
<div class="container-fluid mt-4">
  <div class="d-flex flex-wrap align-items-end gap-3 mb-3">
    <div>
      <div class="form-text mb-1">Filters</div>
      <form method="get" class="row gy-2 gx-2">
        <?php if ($user['role_name']==='admin' && !empty($branches)): ?>
          <div class="col-auto">
            <select class="form-select" name="branch_id">
              <?php foreach($branches as $b): ?>
                <option value="<?= (int)$b['id'] ?>" <?= ((int)$b['id']===$branch_id?'selected':'') ?>><?= htmlspecialchars($b['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        <?php else: ?>
          <input type="hidden" name="branch_id" value="<?= (int)$branch_id ?>">
        <?php endif; ?>

        <div class="col-auto">
          <select class="form-select" name="customer_id">
            <?php foreach($customers as $c): ?>
              <option value="<?= (int)$c['id'] ?>" <?= ((int)$c['id']===$customer_id?'selected':'') ?>><?= htmlspecialchars($c['name']) ?></option>
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
      Customer: <strong><?= htmlspecialchars(($customerMap[$customer_id]['name']??('#'.$customer_id))) ?></strong>
    </div>
  </div>

  <div class="sheet-card p-3">
    <div class="table-responsive">
      <table class="table table-bordered mb-0">
        <thead class="gs-head">
          <tr>
            <th style="width:12%">ID</th>
            <th style="width:16%">Date</th>
            <th>Description</th>
            <th style="width:14%">Credit (Paid)</th>
            <th style="width:14%">Debit (Invoice)</th>
            <th style="width:14%">Balance (DR)</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($rows)): ?>
            <tr class="gs-row"><td colspan="6" class="text-muted text-center">No transactions for the selected period.</td></tr>
          <?php else: foreach($rows as $r): ?>
            <tr class="gs-row">
              <td><?= htmlspecialchars($r['id']) ?></td>
              <td><?= htmlspecialchars($r['ts']) ?></td>
              <td><?= htmlspecialchars($r['desc']) ?></td>
              <td class="num"><?= $r['credit']?fmt($r['credit']):'' ?></td>
              <td class="num"><?= $r['debit'] ?fmt($r['debit']) :'' ?></td>
              <td class="num"><strong><?= fmt($r['balance']) ?></strong></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    <div class="small text-muted mt-2">
      *Balance includes opening balance from transactions before the chosen date range. Positive = customer owes (DR), negative = advance (CR).
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
