<?php
// supplier_statement.php — Simple per-supplier ledger: ID | Date | Description | Credit | Debit | Balance
require_once 'includes/header.php';
checkRole(['admin','manager']);

function column_exists(PDO $pdo, string $table, string $col): bool {
  try { $st=$pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?"); $st->execute([$col]); return (bool)$st->fetch(PDO::FETCH_ASSOC); }
  catch(Throwable $e){ return false; }
}
function table_exists(PDO $pdo, string $table): bool {
  try { $pdo->query("SELECT 1 FROM `$table` LIMIT 1"); return true; }
  catch(Throwable $e){ return false; }
}
function fmt($n){ return 'Rs. '.number_format((float)$n,2); }

/* branch resolve */
$branch_id=0;
if ($user['role_name']==='admin'){
  if (isset($_GET['branch_id']) && ctype_digit($_GET['branch_id'])){ $branch_id=(int)$_GET['branch_id']; $_SESSION['sale_branch_id']=$branch_id; }
  elseif (isset($_SESSION['sale_branch_id'])){ $branch_id=(int)$_SESSION['sale_branch_id']; }
  elseif (!empty($user['branch_id'])){ $branch_id=(int)$user['branch_id']; }
}else{ $branch_id=(int)($user['branch_id']??0); }
if ($branch_id<=0){ include 'includes/nav.php'; echo "<div class='container mt-4'><div class='alert alert-warning'>No branch selected.</div></div>"; exit; }

/* dates */
$today=new DateTime();
$from = isset($_GET['from'])&&preg_match('/^\d{4}-\d{2}-\d{2}$/',$_GET['from']) ? $_GET['from'] : $today->format('Y-m-01');
$to   = isset($_GET['to'])  &&preg_match('/^\d{4}-\d{2}-\d{2}$/',$_GET['to'])   ? $_GET['to']   : $today->format('Y-m-t');
$fromDT=$from.' 00:00:00'; $toDT=$to.' 23:59:59';

/* branch label */
$branches=[]; $branch_name='';
try{
  if ($user['role_name']==='admin') $branches=$pdo->query("SELECT id,name FROM branches WHERE is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
  $st=$pdo->prepare("SELECT name FROM branches WHERE id=?"); $st->execute([$branch_id]); $branch_name=(string)($st->fetchColumn()?:'');
}catch(Throwable $e){}
if (!$branch_name && !empty($user['branch_name'])) $branch_name=$user['branch_name'];

/* suppliers dropdown (schema safe) */
$suppliers=[];
$hasSupBranch = column_exists($pdo,'suppliers','branch_id');
$hasSupActive = column_exists($pdo,'suppliers','is_active');

$sql="SELECT id,name FROM suppliers WHERE 1=1";
$params=[];
if ($hasSupActive) $sql.=" AND is_active=1";
if ($hasSupBranch){ $sql.=" AND (branch_id=:b OR branch_id IS NULL OR branch_id=0)"; $params[':b']=$branch_id; }
$sql.=" ORDER BY name";
try{
  $st=$pdo->prepare($sql); $st->execute($params); $suppliers=$st->fetchAll(PDO::FETCH_ASSOC);
}catch(Throwable $e){
  try{ $suppliers=$pdo->query("SELECT id,name FROM suppliers ORDER BY name")->fetchAll(PDO::FETCH_ASSOC); }catch(Throwable $e2){ $suppliers=[]; }
}
if (empty($suppliers)) { include 'includes/nav.php'; echo "<div class='container mt-4'><div class='alert alert-danger'>No suppliers found.</div></div>"; exit; }

$supplier_id=(isset($_GET['supplier_id']) && ctype_digit($_GET['supplier_id'])) ? (int)$_GET['supplier_id'] : (int)$suppliers[0]['id'];
$supplierMap=[]; foreach($suppliers as $s) $supplierMap[(int)$s['id']]=$s;
if (!isset($supplierMap[$supplier_id])) $supplier_id=(int)$suppliers[0]['id'];

/* flags */
$hasPurch   = table_exists($pdo,'purchases');
$hasPP      = table_exists($pdo,'purchase_payments');
$hasPR      = table_exists($pdo,'purchase_returns');
$hasJE      = table_exists($pdo,'journal_entries');

$hasPurchSup = $hasPurch && column_exists($pdo,'purchases','supplier_id');
$hasPurchBr  = $hasPurch && column_exists($pdo,'purchases','branch_id');
$hasPRBr     = $hasPR    && column_exists($pdo,'purchase_returns','branch_id');
$hasJESup    = $hasJE    && column_exists($pdo,'journal_entries','supplier_id');
$hasJEBr     = $hasJE    && column_exists($pdo,'journal_entries','branch_id');

/* rows */
/* ---------- compute opening balance (carry-forward prior to selected range) ---------- */
$opening = 0.0;

// Purchases before from date (increase payable)
if ($hasPurch && $hasPurchSup) {
  try {
    $sql = "SELECT COALESCE(SUM(total_amount),0) FROM purchases WHERE supplier_id=:sid AND purchase_date < :f";
    $params = [':sid'=>$supplier_id, ':f'=>$fromDT];
    if ($hasPurchBr) { $sql .= " AND branch_id=:b"; $params[':b']=$branch_id; }
    $st = $pdo->prepare($sql); $st->execute($params);
    $opening += (float)$st->fetchColumn();
  } catch (Throwable $e) {}
}

// Purchase payments before from date (reduce payable)
if ($hasPP) {
  try {
    $has_pp_sup = column_exists($pdo,'purchase_payments','supplier_id');
    if ($has_pp_sup) {
      $datecol = column_exists($pdo,'purchase_payments','paid_at') ? 'paid_at' : 'created_at';
      $sql = "SELECT COALESCE(SUM(amount),0) FROM purchase_payments WHERE supplier_id=:sid AND $datecol < :f";
      $params = [':sid'=>$supplier_id, ':f'=>$fromDT];
    } else {
      $datecol = column_exists($pdo,'purchase_payments','paid_at') ? 'pp.paid_at' : 'p.purchase_date';
      $sql = "SELECT COALESCE(SUM(pp.amount),0) FROM purchase_payments pp JOIN purchases p ON p.id=pp.purchase_id WHERE p.supplier_id=:sid AND $datecol < :f";
      $params = [':sid'=>$supplier_id, ':f'=>$fromDT];
      if ($hasPurchBr) { $sql .= " AND p.branch_id=:b"; $params[':b']=$branch_id; }
    }
    $st = $pdo->prepare($sql); $st->execute($params);
    $opening -= (float)$st->fetchColumn();
  } catch (Throwable $e) {}
}

// Purchase returns before from date (reduce payable)
if ($hasPR) {
  try {
    $datecol = column_exists($pdo,'purchase_returns','return_date') ? 'return_date' : 'created_at';
    $amtcol  = column_exists($pdo,'purchase_returns','total_refund') ? 'total_refund' : 'amount';
    $sql = "SELECT COALESCE(SUM(pr.$amtcol),0) FROM purchase_returns pr JOIN purchases p ON p.id=pr.purchase_id WHERE p.supplier_id=:sid AND pr.$datecol < :f";
    $params = [':sid'=>$supplier_id, ':f'=>$fromDT];
    if ($hasPurchBr) { $sql .= " AND p.branch_id=:b"; $params[':b']=$branch_id; }
    $st = $pdo->prepare($sql); $st->execute($params);
    $opening -= (float)$st->fetchColumn();
  } catch (Throwable $e) {}
}

// Supplier journal entries before from date
if ($hasJE && $hasJESup) {
  try {
    $sql = "SELECT COALESCE(SUM(CASE WHEN amount<0 THEN -amount ELSE 0 END),0) AS credit, COALESCE(SUM(CASE WHEN amount>0 THEN amount ELSE 0 END),0) AS debit FROM journal_entries WHERE supplier_id=:sid AND account_type='supplier' AND entry_date < :f";
    $params = [':sid'=>$supplier_id, ':f'=>$from];
    if ($hasJEBr) { $sql .= " AND branch_id=:b"; $params[':b']=$branch_id; }
    $st = $pdo->prepare($sql); $st->execute($params);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: ['credit'=>0,'debit'=>0];
    // For suppliers: negative amount (credit) increases payable; positive amount (debit) reduces payable
    $opening += (float)$row['credit'];
    $opening -= (float)$row['debit'];
  } catch (Throwable $e) {}
}

/*
   Convention (A/P):
   - Purchases (invoice) => CREDIT (we owe more)
   - Payments / Purchase Return => DEBIT (reduces payable)
   - Supplier journal (account_type='supplier'): amount>0 => DEBIT (payment/credit), amount<0 => CREDIT
*/
$rows=[];

/* Purchases */
if ($hasPurch && $hasPurchSup) {
  try{
    $sql="SELECT id, purchase_date AS ts, total_amount FROM purchases
          WHERE supplier_id=:sid AND purchase_date BETWEEN :f AND :t";
    $params=[':sid'=>$supplier_id, ':f'=>$fromDT, ':t'=>$toDT];
    if ($hasPurchBr){ $sql.=" AND branch_id=:b"; $params[':b']=$branch_id; }
    $sql.=" ORDER BY purchase_date,id";
    $st=$pdo->prepare($sql); $st->execute($params);
    while($r=$st->fetch(PDO::FETCH_ASSOC)){
      $rows[]=[
        'id'=>'P#'.(int)$r['id'],
        'ts'=>$r['ts'],
        'desc'=>'Purchase #'.(int)$r['id'],
        'credit'=>(float)$r['total_amount'],
        'debit'=>0.0,
      ];
    }
  }catch(Throwable $e){}
}

/* Purchase Payments */
if ($hasPP) {
  try{
    $has_pp_sup = column_exists($pdo,'purchase_payments','supplier_id');
    if ($has_pp_sup){
      $datecol = column_exists($pdo,'purchase_payments','paid_at') ? 'paid_at':'created_at';
      $sql="SELECT id, $datecol AS ts, amount, method
            FROM purchase_payments
            WHERE supplier_id=:sid AND $datecol BETWEEN :f AND :t";
      $params=[':sid'=>$supplier_id, ':f'=>$fromDT, ':t'=>$toDT];
    }else{
      $datecol = column_exists($pdo,'purchase_payments','paid_at') ? 'pp.paid_at':'p.purchase_date';
      $sql="SELECT pp.id, $datecol AS ts, pp.amount, pp.method
            FROM purchase_payments pp
            JOIN purchases p ON p.id=pp.purchase_id
            WHERE p.supplier_id=:sid AND $datecol BETWEEN :f AND :t";
      $params=[':sid'=>$supplier_id, ':f'=>$fromDT, ':t'=>$toDT];
      if ($hasPurchBr){ $sql.=" AND p.branch_id=:b"; $params[':b']=$branch_id; }
    }
    $sql.=" ORDER BY ts,id";
    $st=$pdo->prepare($sql); $st->execute($params);
    while($r=$st->fetch(PDO::FETCH_ASSOC)){
      $rows[]=[
        'id'=>'PP#'.(int)$r['id'],
        'ts'=>$r['ts'],
        'desc'=>'Payment ('.strtoupper((string)$r['method']).')',
        'credit'=>0.0,
        'debit'=>(float)$r['amount'],
      ];
    }
  }catch(Throwable $e){}
}

/* Purchase Returns (reduce payable) */
if ($hasPR) {
  try{
    $datecol = column_exists($pdo,'purchase_returns','return_date') ? 'return_date':'created_at';
    $amtcol  = column_exists($pdo,'purchase_returns','total_refund') ? 'total_refund':'amount';
    $sql="SELECT pr.id, pr.$datecol AS ts, pr.$amtcol AS amt
          FROM purchase_returns pr
          JOIN purchases p ON p.id=pr.purchase_id
          WHERE p.supplier_id=:sid AND pr.$datecol BETWEEN :f AND :t";
    $params=[':sid'=>$supplier_id, ':f'=>$fromDT, ':t'=>$toDT];
    if ($hasPurchBr){ $sql.=" AND p.branch_id=:b"; $params[':b']=$branch_id; }
    $sql.=" ORDER BY pr.$datecol, pr.id";
    $st=$pdo->prepare($sql); $st->execute($params);
    while($r=$st->fetch(PDO::FETCH_ASSOC)){
      $rows[]=[
        'id'=>'PR#'.(int)$r['id'],
        'ts'=>$r['ts'],
        'desc'=>'Purchase Return #'.(int)$r['id'],
        'credit'=>0.0,
        'debit'=>(float)$r['amt'],
      ];
    }
  }catch(Throwable $e){}
}

/* Supplier Journals */
if ($hasJE && $hasJESup) {
  try{
    $sql="SELECT id, entry_date AS d, description, amount
          FROM journal_entries
          WHERE supplier_id=:sid AND account_type='supplier' AND entry_date BETWEEN :f AND :t";
    $params=[':sid'=>$supplier_id, ':f'=>$from, ':t'=>$to];
    if ($hasJEBr){ $sql.=" AND branch_id=:b"; $params[':b']=$branch_id; }
    $sql.=" ORDER BY entry_date,id";
    $st=$pdo->prepare($sql); $st->execute($params);
    while($r=$st->fetch(PDO::FETCH_ASSOC)){
      $amt=(float)$r['amount'];
      // amount>0 => DEBIT (reduces payable), amount<0 => CREDIT (increase payable)
      $rows[]=[
        'id'=>'JE#'.(int)$r['id'],
        'ts'=>$r['d'].' 00:00:00',
        'desc'=>(string)$r['description'],
        'credit'=>$amt<0?abs($amt):0.0,
        'debit' =>$amt>0?$amt:0.0,
      ];
    }
  }catch(Throwable $e){}
}

/* sort & running (A/P running: positive = we owe supplier (CR), negative = advance (DR)) */
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

// Compute running balance starting from opening (positive = we owe supplier)
$running = $opening;
foreach($rows as &$r){
  if ($r['id'] !== 'OPEN') {
    $running += $r['credit'];
    $running -= $r['debit'];
  }
  $r['balance']=$running;
}
unset($r);

/* UI */
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Supplier Statement</title>
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
          <select class="form-select" name="supplier_id">
            <?php foreach($suppliers as $s): ?>
              <option value="<?= (int)$s['id'] ?>" <?= ((int)$s['id']===$supplier_id?'selected':'') ?>><?= htmlspecialchars($s['name']) ?></option>
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
      Supplier: <strong><?= htmlspecialchars(($supplierMap[$supplier_id]['name']??('#'.$supplier_id))) ?></strong>
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
            <th style="width:14%">Credit (Invoice)</th>
            <th style="width:14%">Debit (Paid/Return)</th>
            <th style="width:14%">Balance (CR)</th>
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
      *Balance includes the opening balance from transactions before the chosen range. Positive = we owe supplier (CR), negative = advance/overpayment (DR).
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
