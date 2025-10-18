<?php
// cash_statement.php — Cash ledger/statement: ID | Date | Description | Credit | Debit | Balance
require_once 'includes/header.php';
checkRole(['admin','manager','cashier']);

/* ---------- helpers ---------- */
function column_exists(PDO $pdo, string $table, string $col): bool {
  try { $st=$pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?"); $st->execute([$col]); return (bool)$st->fetch(PDO::FETCH_ASSOC); }
  catch(Throwable $e){ return false; }
}
function table_exists(PDO $pdo, string $table): bool {
  try { $pdo->query("SELECT 1 FROM `$table` LIMIT 1"); return true; }
  catch(Throwable $e){ return false; }
}
function first_col(PDO $pdo, string $table, array $cands, string $fallback=''): string {
  foreach ($cands as $c) if (column_exists($pdo,$table,$c)) return $c;
  return $fallback;
}
function fmt($n){ return 'Rs. '.number_format((float)$n,2); }

/* ---------- branch resolve ---------- */
$branch_id = 0;
if (($user['role_name']??'')==='admin') {
  if (isset($_GET['branch_id']) && ctype_digit($_GET['branch_id'])) {
    $branch_id=(int)$_GET['branch_id']; $_SESSION['sale_branch_id']=$branch_id;
  } elseif (isset($_SESSION['sale_branch_id'])) {
    $branch_id=(int)$_SESSION['sale_branch_id'];
  } elseif (!empty($user['branch_id'])) {
    $branch_id=(int)$user['branch_id'];
  }
} else {
  $branch_id = (int)($user['branch_id'] ?? 0);
}
if ($branch_id<=0){ include 'includes/nav.php'; echo "<div class='container mt-4'><div class='alert alert-warning'>No branch selected.</div></div>"; exit; }

/* ---------- dates (default = current month) ---------- */
$today = new DateTime();
$from = (isset($_GET['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/',$_GET['from'])) ? $_GET['from'] : $today->format('Y-m-01');
$to   = (isset($_GET['to'])   && preg_match('/^\d{4}-\d{2}-\d{2}$/',$_GET['to']))   ? $_GET['to']   : $today->format('Y-m-t');
$fromDT=$from.' 00:00:00'; $toDT=$to.' 23:59:59';

/* ---------- branch label ---------- */
$branches=[]; $branch_name='';
try{
  if (($user['role_name']??'')==='admin'){
    $branches=$pdo->query("SELECT id,name FROM branches WHERE is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
  }
  $st=$pdo->prepare("SELECT name FROM branches WHERE id=?"); $st->execute([$branch_id]); $branch_name=(string)($st->fetchColumn()?:'');
}catch(Throwable $e){}
if (!$branch_name && !empty($user['branch_name'])) $branch_name=$user['branch_name'];

/* ---------- feature flags & common columns ---------- */
$warn = [];

$hasSales  = table_exists($pdo,'sales');
$hasSP     = table_exists($pdo,'sale_payments');
$hasSR     = table_exists($pdo,'sale_returns'); // for cash refunds
$hasEXP    = table_exists($pdo,'expenses');
$hasPP     = table_exists($pdo,'purchase_payments');
$hasPurch  = table_exists($pdo,'purchases');
$hasWP     = table_exists($pdo,'worker_payments');
$hasWorkers= table_exists($pdo,'workers');
$hasJE     = table_exists($pdo,'journal_entries');
$hasCO     = table_exists($pdo,'cash_openings');
$hasCargoP = table_exists($pdo,'cargo_payments');

$sp_date   = $hasSales ? 's.sale_date' : ''; // joined to sales
$sp_amt    = 'sp.amount';

$sr_datecol = $hasSR ? first_col($pdo,'sale_returns',['return_date','created_at','date'],'') : '';
$sr_has_type= $hasSR && column_exists($pdo,'sale_returns','return_type');

$exp_date  = $hasEXP ? first_col($pdo,'expenses',['expense_date','date','created_at'],'') : '';
$exp_amt   = $hasEXP ? first_col($pdo,'expenses',['amount','total','total_amount'],'amount') : '';
$exp_pmeth = $hasEXP && column_exists($pdo,'expenses','payment_method');

$pp_date   = $hasPP ? first_col($pdo,'purchase_payments',['paid_at','created_at','date'],'') : '';
$pp_amt    = 'pp.amount';
$pp_method = $hasPP && column_exists($pdo,'purchase_payments','method');
$pp_has_sup= $hasPP && column_exists($pdo,'purchase_payments','supplier_id'); // not required
$pur_br    = $hasPurch && column_exists($pdo,'purchases','branch_id');

$wp_date   = $hasWP ? first_col($pdo,'worker_payments',['payment_date','date','created_at'],'') : '';
$wp_amt    = 'wp.amount';
$wp_method = $hasWP && column_exists($pdo,'worker_payments','method');
$wrk_br    = $hasWorkers && column_exists($pdo,'workers','branch_id');

$je_date   = $hasJE ? first_col($pdo,'journal_entries',['entry_date','date','created_at'],'') : '';
$je_amt    = 'je.amount';

$co_date   = $hasCO ? first_col($pdo,'cash_openings',['open_date','opening_date','date','opened_on','created_at'],'') : '';
$co_amt    = $hasCO ? first_col($pdo,'cash_openings',['opening_amount','amount','opening','opening_balance'],'') : '';

$cg_date   = $hasCargoP ? first_col($pdo,'cargo_payments',['paid_at','created_at','date'],'') : '';
$cg_amt    = $hasCargoP ? 'cp.amount' : '';
$cg_method = $hasCargoP && column_exists($pdo,'cargo_payments','method');
$cg_br     = $hasCargoP && column_exists($pdo,'cargo_payments','branch_id');

if ($hasEXP && (!$exp_date || !$exp_amt)) $warn[]="expenses: missing date or amount column; cash expenses may be hidden.";
if ($hasPP && !$pp_date) $warn[]="purchase_payments: no paid_at/created_at; date filter may be inaccurate.";
if ($hasWP && !$wp_date) $warn[]="worker_payments: missing payment date; date filter may be inaccurate.";
if ($hasJE && !$je_date) $warn[]="journal_entries: missing entry date; date filter may be inaccurate.";
if ($hasCO && (!$co_amt)) $warn[]="cash_openings: missing opening amount; openings will be skipped.";
if ($hasSR && !$sr_datecol) $warn[]="sale_returns: missing return date; cash refunds may be hidden.";

/* ---------- compute opening balance (include prior transactions up to day before $from) ---------- */
$opening = 0.0;
// Cash Opening entries prior to date range
if ($hasCO && $co_amt) {
  try {
    $sql = "SELECT COALESCE(SUM($co_amt),0) FROM cash_openings WHERE 1=1";
    $params = [];
    // only include rows strictly before the start date
    if ($co_date) { $sql .= " AND $co_date < :f"; $params[':f'] = $from; }
    if (column_exists($pdo,'cash_openings','branch_id')) { $sql .= " AND branch_id=:b"; $params[':b'] = $branch_id; }
    $st = $pdo->prepare($sql); $st->execute($params);
    $opening += (float)$st->fetchColumn();
  } catch (Throwable $e) {}
}

// Sales collected in Cash prior to date range (credit)
if ($hasSP && $hasSales) {
  try {
    $sql = "SELECT COALESCE(SUM(sp.amount),0) FROM sale_payments sp JOIN sales s ON s.id=sp.sale_id WHERE s.branch_id=:b AND s.sale_date < :f AND sp.method='cash'";
    $st = $pdo->prepare($sql);
    $st->execute([':b'=>$branch_id, ':f'=>$fromDT]);
    $opening += (float)$st->fetchColumn();
  } catch (Throwable $e) {}
}

// Sale returns refunded in Cash prior to date range (debit)
if ($hasSR && $sr_datecol) {
  try {
    $sql = "SELECT COALESCE(SUM(sr.total_refund),0) FROM sale_returns sr JOIN sales s ON s.id=sr.sale_id WHERE s.branch_id=:b AND $sr_datecol < :f";
    $params = [':b'=>$branch_id, ':f'=>$fromDT];
    if ($sr_has_type) { $sql .= " AND sr.return_type='cash'"; }
    $st = $pdo->prepare($sql); $st->execute($params);
    $opening -= (float)$st->fetchColumn();
  } catch (Throwable $e) {}
}

// Expenses paid by cash prior to date range (debit)
if ($hasEXP && $exp_date && $exp_amt) {
  try {
    $sql = "SELECT COALESCE(SUM($exp_amt),0) FROM expenses WHERE $exp_date < :f";
    $params = [':f'=>$from];
    if (column_exists($pdo,'expenses','branch_id')) { $sql .= " AND branch_id=:b"; $params[':b']=$branch_id; }
    if ($exp_pmeth) { $sql .= " AND payment_method='cash'"; }
    $st = $pdo->prepare($sql); $st->execute($params);
    $opening -= (float)$st->fetchColumn();
  } catch (Throwable $e) {}
}

// Supplier purchase payments (cash) prior to date range (debit)
if ($hasPP) {
  try {
    if ($pp_method) {
      $sql = "SELECT COALESCE(SUM(pp.amount),0) FROM purchase_payments pp JOIN purchases p ON p.id=pp.purchase_id WHERE 1=1";
      $where = [];
      $params = [];
      // date condition: use paid_at if available, else no date
      if ($pp_date) { $where[] = "pp.$pp_date < :f"; $params[':f'] = $fromDT; }
      if ($pp_method) { $where[] = "(pp.method='cash' OR pp.method IS NULL)"; }
      if ($pur_br) { $where[] = "p.branch_id=:b"; $params[':b']=$branch_id; }
      if ($where) { $sql .= ' AND '.implode(' AND ', $where); }
      $st = $pdo->prepare($sql);
      $st->execute($params);
      $opening -= (float)$st->fetchColumn();
    } else {
      // no method column; treat all as cash-like
      $sql = "SELECT COALESCE(SUM(pp.amount),0) FROM purchase_payments pp JOIN purchases p ON p.id=pp.purchase_id WHERE 1=1";
      $where = [];
      $params = [];
      if ($pp_date) { $where[] = "pp.$pp_date < :f"; $params[':f']=$fromDT; }
      if ($pur_br) { $where[] = "p.branch_id=:b"; $params[':b']=$branch_id; }
      if ($where) { $sql .= ' AND '.implode(' AND ', $where); }
      $st = $pdo->prepare($sql);
      $st->execute($params);
      $opening -= (float)$st->fetchColumn();
    }
  } catch (Throwable $e) {}
}

// Worker payments (cash) prior to date range (debit)
if ($hasWP) {
  try {
    $sql = "SELECT COALESCE(SUM(wp.amount),0) FROM worker_payments wp";
    $where = [];
    $params = [];
    if ($wp_date) { $where[] = "wp.$wp_date < :f"; $params[':f']=$from; }
    if ($wp_method) { $where[] = "wp.method='cash'"; }
    if ($hasWorkers && $wrk_br) {
      $sql .= " JOIN workers w ON w.id=wp.worker_id";
      $where[] = "w.branch_id=:b";
      $params[':b'] = $branch_id;
    }
    if ($where) { $sql .= ' WHERE '.implode(' AND ', $where); }
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $opening -= (float)$st->fetchColumn();
  } catch (Throwable $e) {}
}

// Cargo payments (cash) prior to date range (debit)
if ($hasCargoP && $cg_date) {
  try {
    $sql = "SELECT COALESCE(SUM($cg_amt),0) FROM cargo_payments cp WHERE $cg_date < :f";
    $params = [':f'=>$fromDT];
    if ($cg_method) { $sql .= " AND cp.method='cash'"; }
    if ($cg_br) { $sql .= " AND cp.branch_id=:b"; $params[':b']=$branch_id; }
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $opening -= (float)$st->fetchColumn();
  } catch (Throwable $e) {}
}

// Cash journal entries prior to date range (±)
if ($hasJE && $je_date) {
  try {
    $sql = "SELECT COALESCE(SUM(je.amount),0) FROM journal_entries je WHERE je.account_type='cash' AND $je_date < :f";
    $params = [':f'=>$from];
    if (column_exists($pdo,'journal_entries','branch_id')) { $sql .= " AND je.branch_id=:b"; $params[':b'] = $branch_id; }
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $opening += (float)$st->fetchColumn();
  } catch (Throwable $e) {}
}

/* ---------- collect rows ---------- */
$rows=[];

/* Cash Opening entries (credit) */
if ($hasCO && $co_amt){
  try{
    $sql="SELECT id, ".($co_date ? "$co_date" : "DATE(:f)")." AS d, $co_amt AS amt FROM cash_openings WHERE 1=1";
    $params=[];
    if ($co_date){ $sql.=" AND $co_date BETWEEN :f AND :t"; $params=[':f'=>$from, ':t'=>$to]; }
    if (column_exists($pdo,'cash_openings','branch_id')){ $sql.=" AND branch_id=:b"; $params[':b']=$branch_id; }
    $sql.=" ORDER BY d,id";
    $st=$pdo->prepare($sql); $st->execute($params);
    while($r=$st->fetch(PDO::FETCH_ASSOC)){
      $rows[]=['id'=>'CO#'.(int)$r['id'],'ts'=>$r['d'].' 00:00:00','desc'=>'Cash Opening','credit'=>(float)$r['amt'],'debit'=>0.0];
    }
  }catch(Throwable $e){}
}

/* Sales collected in Cash (credit) */
if ($hasSP && $hasSales){
  try{
    $sql="SELECT sp.id, s.id AS sale_id, s.sale_date AS d, sp.amount
          FROM sale_payments sp JOIN sales s ON s.id=sp.sale_id
          WHERE s.branch_id=:b AND s.sale_date BETWEEN :f AND :t AND sp.method='cash'
          ORDER BY s.sale_date, sp.id";
    $st=$pdo->prepare($sql); $st->execute([':b'=>$branch_id, ':f'=>$fromDT, ':t'=>$toDT]);
    while($r=$st->fetch(PDO::FETCH_ASSOC)){
      $rows[]=['id'=>'SP#'.(int)$r['id'],'ts'=>$r['d'],'desc'=>'Sale #'.(int)$r['sale_id'].' (Cash)','credit'=>(float)$r['amount'],'debit'=>0.0];
    }
  }catch(Throwable $e){}
}

/* Sale returns refunded in Cash (debit) */
if ($hasSR && $sr_datecol){
  try{
    $sql="SELECT sr.id, $sr_datecol AS d, sr.total_refund AS amt
          FROM sale_returns sr
          JOIN sales s ON s.id=sr.sale_id
          WHERE s.branch_id=:b AND $sr_datecol BETWEEN :f AND :t";
    $params=[':b'=>$branch_id, ':f'=>$fromDT, ':t'=>$toDT];
    if ($sr_has_type) { $sql.=" AND sr.return_type='cash'"; }
    $sql.=" ORDER BY d, sr.id";
    $st=$pdo->prepare($sql); $st->execute($params);
    while($r=$st->fetch(PDO::FETCH_ASSOC)){
      $rows[]=['id'=>'SR#'.(int)$r['id'],'ts'=>$r['d'],'desc'=>'Sale Return (Cash)','credit'=>0.0,'debit'=>(float)$r['amt']];
    }
  }catch(Throwable $e){}
}

/* Expenses paid by Cash (debit) */
if ($hasEXP && $exp_date && $exp_amt){
  try{
    $sql="SELECT id, $exp_date AS d, $exp_amt AS amt, description FROM expenses
          WHERE $exp_date BETWEEN :f AND :t";
    $params=[':f'=>$from, ':t'=>$to];
    if (column_exists($pdo,'expenses','branch_id')){ $sql.=" AND branch_id=:b"; $params[':b']=$branch_id; }
    if ($exp_pmeth){ $sql.=" AND payment_method='cash'"; }
    $sql.=" ORDER BY d, id";
    $st=$pdo->prepare($sql); $st->execute($params);
    while($r=$st->fetch(PDO::FETCH_ASSOC)){
      $rows[]=['id'=>'EXP#'.(int)$r['id'],'ts'=>$r['d'].' 00:00:00','desc'=>(string)$r['description'],'credit'=>0.0,'debit'=>(float)$r['amt']];
    }
  }catch(Throwable $e){}
}

/* Supplier purchase payments (cash) (debit) */
if ($hasPP){
  try{
    if ($pp_method){
      $sql="SELECT pp.id, ".($pp_date ? "pp.$pp_date" : "NOW()")." AS d, pp.amount, pp.method
            FROM purchase_payments pp
            JOIN purchases p ON p.id=pp.purchase_id
            WHERE ".($pp_date ? "pp.$pp_date BETWEEN :f AND :t AND " : "")." (pp.method='cash' OR pp.method IS NULL)";
      $params = [];
      if ($pp_date){ $params=[':f'=>$fromDT, ':t'=>$toDT]; }
      if ($pur_br){ $sql.=" AND p.branch_id=:b"; $params[':b']=$branch_id; }
    } else {
      // No method column — treat all as cash-like for cash page? Safer: only include NULL method.
      $sql="SELECT pp.id, ".($pp_date ? "pp.$pp_date" : "p.purchase_date")." AS d, pp.amount
            FROM purchase_payments pp
            JOIN purchases p ON p.id=pp.purchase_id
            WHERE ".($pp_date ? "pp.$pp_date" : "p.purchase_date")." BETWEEN :f AND :t";
      $params=[':f'=>$fromDT, ':t'=>$toDT];
      if ($pur_br){ $sql.=" AND p.branch_id=:b"; $params[':b']=$branch_id; }
    }
    $sql.=" ORDER BY d, pp.id";
    $st=$pdo->prepare($sql); $st->execute($params);
    while($r=$st->fetch(PDO::FETCH_ASSOC)){
      $rows[]=['id'=>'PP#'.(int)$r['id'],'ts'=>$r['d'],'desc'=>'Supplier Payment (Cash)','credit'=>0.0,'debit'=>(float)$r['amount']];
    }
  }catch(Throwable $e){}
}

/* Worker payments (cash) (debit) */
if ($hasWP){
  try{
    $sql="SELECT wp.id, ".($wp_date ? "wp.$wp_date" : "NOW()")." AS d, wp.amount
          FROM worker_payments wp";
    $where=[]; $params=[];
    if ($wp_date){ $where[]="wp.$wp_date BETWEEN :f AND :t"; $params[':f']=$from; $params[':t']=$to; }
    if ($wp_method){ $where[]="wp.method='cash'"; }
    if ($hasWorkers && $wrk_br){ $sql.=" JOIN workers w ON w.id=wp.worker_id"; $where[]="w.branch_id=:b"; $params[':b']=$branch_id; }
    if ($where) $sql.=" WHERE ".implode(' AND ',$where);
    $sql.=" ORDER BY d, wp.id";
    $st=$pdo->prepare($sql); $st->execute($params);
    while($r=$st->fetch(PDO::FETCH_ASSOC)){
      $rows[]=['id'=>'WP#'.(int)$r['id'],'ts'=>$r['d'].' 00:00:00','desc'=>'Worker Payment (Cash)','credit'=>0.0,'debit'=>(float)$r['amount']];
    }
  }catch(Throwable $e){}
}

/* Cargo payments (cash) (debit) */
if ($hasCargoP && $cg_date){
  try{
    $sql="SELECT cp.id, $cg_date AS d, $cg_amt AS amt FROM cargo_payments cp WHERE $cg_date BETWEEN :f AND :t";
    $params=[':f'=>$fromDT, ':t'=>$toDT];
    if ($cg_method) { $sql.=" AND cp.method='cash'"; }
    if ($cg_br) { $sql.=" AND cp.branch_id=:b"; $params[':b']=$branch_id; }
    $sql.=" ORDER BY d, cp.id";
    $st=$pdo->prepare($sql); $st->execute($params);
    while($r=$st->fetch(PDO::FETCH_ASSOC)){
      $rows[]=['id'=>'CG#'.(int)$r['id'],'ts'=>$r['d'],'desc'=>'Cargo Payment (Cash)','credit'=>0.0,'debit'=>(float)$r['amt']];
    }
  }catch(Throwable $e){}
}

/* Cash journal entries (±) */
if ($hasJE && $je_date){
  try{
    $sql="SELECT id, $je_date AS d, description, amount FROM journal_entries
          WHERE account_type='cash' AND $je_date BETWEEN :f AND :t";
    $params=[':f'=>$from, ':t'=>$to];
    if (column_exists($pdo,'journal_entries','branch_id')){ $sql.=" AND branch_id=:b"; $params[':b']=$branch_id; }
    $sql.=" ORDER BY d, id";
    $st=$pdo->prepare($sql); $st->execute($params);
    while($r=$st->fetch(PDO::FETCH_ASSOC)){
      $amt=(float)$r['amount'];
      $rows[]=['id'=>'JE#'.(int)$r['id'],'ts'=>$r['d'].' 00:00:00','desc'=>(string)$r['description'],
               'credit'=>$amt>0?$amt:0.0,'debit'=>$amt<0?abs($amt):0.0];
    }
  }catch(Throwable $e){}
}

/* ---------- sort & running ---------- */
// Sort rows chronologically (id as secondary key)
usort($rows,function($a,$b){ $c=strcmp($a['ts'],$b['ts']); return $c!==0?$c:strcmp((string)$a['id'],(string)$b['id']); });

// Prepend an opening balance row to show carry-forward from prior transactions
$openingRow = [
  'id'   => 'OPEN',
  'ts'   => $from.' 00:00:00',
  'desc' => 'Opening Balance',
  'credit' => 0.0,
  'debit'  => 0.0,
  'balance'=> $opening
];
$rows = array_merge([$openingRow], $rows);

// Compute running balance starting from opening
$running = $opening;
$totCr  = 0.0;
$totDr  = 0.0;
foreach ($rows as &$r) {
  // Skip updating running for the opening row (no credit/debit)
  if ($r['id'] !== 'OPEN') {
    $running += $r['credit'];
    $running -= $r['debit'];
    $totCr  += $r['credit'];
    $totDr  += $r['debit'];
  }
  $r['balance'] = $running;
}
unset($r);

/* ---------- UI ---------- */
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Cash Statement</title>
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
  <?php foreach ($warn as $w): ?>
    <div class="alert alert-warning py-2"><?= htmlspecialchars($w) ?></div>
  <?php endforeach; ?>

  <div class="d-flex flex-wrap align-items-end gap-3 mb-3">
    <div>
      <div class="form-text mb-1">Filters</div>
      <form method="get" class="row gy-2 gx-2">
        <?php if (($user['role_name']??'')==='admin' && !empty($branches)): ?>
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
        <div class="col-auto"><input type="date" class="form-control" name="from" value="<?= htmlspecialchars($from) ?>"></div>
        <div class="col-auto"><input type="date" class="form-control" name="to"   value="<?= htmlspecialchars($to) ?>"></div>
        <div class="col-auto"><button class="btn btn-primary">Apply</button></div>
      </form>
    </div>
    <div class="ms-auto text-end small text-muted">
      Branch: <strong><?= htmlspecialchars($branch_name) ?></strong><br>
      Range: <strong><?= htmlspecialchars($from) ?></strong> to <strong><?= htmlspecialchars($to) ?></strong>
    </div>
  </div>

  <!-- Summary -->
  <div class="row g-3 mb-3">
    <div class="col-md-4">
      <div class="sheet-card p-3">
        <div class="text-muted">Total Cash In</div>
        <div class="h5 mb-0"><?= fmt($totCr) ?></div>
      </div>
    </div>
    <div class="col-md-4">
      <div class="sheet-card p-3">
        <div class="text-muted">Total Cash Out</div>
        <div class="h5 mb-0"><?= fmt($totDr) ?></div>
      </div>
    </div>
    <div class="col-md-4">
      <div class="sheet-card p-3">
        <div class="text-muted">Net Movement</div>
        <div class="h5 mb-0"><?= fmt($totCr - $totDr) ?></div>
      </div>
    </div>
  </div>

  <!-- Ledger -->
  <div class="sheet-card p-3">
    <div class="table-responsive">
      <table class="table table-bordered mb-0">
        <thead class="gs-head">
          <tr>
            <th style="width:12%">ID</th>
            <th style="width:16%">Date</th>
            <th>Description</th>
            <th style="width:14%">Credit</th>
            <th style="width:14%">Debit</th>
            <th style="width:14%">Balance</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($rows)): ?>
            <tr class="gs-row">
              <td colspan="6" class="text-center text-muted">No cash transactions for the selected period.</td>
            </tr>
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
      *Running balance includes the opening balance from transactions before the selected range.
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
