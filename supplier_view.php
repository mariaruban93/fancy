<?php
/**
 * supplier_view.php — NET-BASED OUTSTANDING (FINAL)
 * Outstanding (row) = (Purchase Cost - per-purchase Cargo Transfers) - Returns - Payments
 * = Net Purchase - Payments - Returns
 *
 * - If cargo_services.purchase_id exists, cargo is attributed per purchase.
 * - All caps (transfer / pay) use this same net-based outstanding.
 * - Prevent paying beyond available Cash/Bank/Wallet balance.
 * - Works with or without purchase_payments.method / bank_account_id columns.
 */

require_once 'includes/header.php';
checkRole(['admin','manager']);


/* ---------- utils ---------- */
function col_exists(PDO $pdo, string $table, string $col): bool {
  try { $st=$pdo->query("SHOW COLUMNS FROM `$table` LIKE ".$pdo->quote($col)); return (bool)$st->fetch(); }
  catch(Throwable $e){ return false; }
}
function _wa($cur){ return $cur==='' ? ' WHERE ' : ' AND '; }

/* ---------- context ---------- */
$supplier_id = (isset($_GET['id']) && ctype_digit($_GET['id'])) ? (int)$_GET['id'] : 0;
if ($supplier_id<=0){ echo "<p class='text-danger'>Invalid supplier ID.</p>"; exit; }

$role    = $user['role_name'] ?? '';
$userBid = (int)($user['branch_id'] ?? 0);

$start_date = isset($_GET['start_date']) ? trim($_GET['start_date']) : '';
$end_date   = isset($_GET['end_date'])   ? trim($_GET['end_date'])   : '';

$branch_filter = null;
if ($role==='manager') {
  $branch_filter = $userBid;
} elseif ($role==='admin' && isset($_GET['branch_id']) && $_GET['branch_id']!=='' && ctype_digit($_GET['branch_id'])) {
  $branch_filter = (int)$_GET['branch_id'];
}

/* ---------- supplier ---------- */
try{ $st=$pdo->prepare("SELECT * FROM suppliers WHERE id=?"); $st->execute([$supplier_id]); $supplier=$st->fetch(PDO::FETCH_ASSOC); }
catch(Throwable $e){ $supplier=null; }
if(!$supplier){ echo "<p class='text-danger'>Supplier not found.</p>"; exit; }

// Restrict access: if suppliers table has branch_id column, ensure supplier belongs to current user's branch
try {
    $supBranchCheck = $pdo->prepare("SHOW COLUMNS FROM suppliers LIKE 'branch_id'");
    $supBranchCheck->execute();
    $hasSupBranch = (bool)$supBranchCheck->fetch();
    if ($hasSupBranch) {
        $supplierBranchId = isset($supplier['branch_id']) ? (int)$supplier['branch_id'] : null;
        $currentBranchId  = (int)($user['branch_id'] ?? 0);
        if (!is_null($supplierBranchId) && $supplierBranchId !== $currentBranchId) {
            echo "<p class='text-danger'>Access denied. Supplier belongs to another branch.</p>";
            exit;
        }
    }
} catch (Throwable $e) {
    // ignore
}

/* ---------- lookups ---------- */
try { $providers = $pdo->query("SELECT id,name FROM cargo_providers WHERE is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC); }
catch(Throwable $e){ $providers=[]; }

try {
  if ($role==='admin') {
    if (!is_null($branch_filter)) {
      $s=$pdo->prepare("SELECT id,name,account_no FROM bank_accounts WHERE branch_id=? ORDER BY name");
      $s->execute([$branch_filter]); $bankAccounts=$s->fetchAll(PDO::FETCH_ASSOC);
    } else {
      $bankAccounts=$pdo->query("SELECT id,name,account_no FROM bank_accounts ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    }
  } else {
    $s=$pdo->prepare("SELECT id,name,account_no FROM bank_accounts WHERE branch_id=? ORDER BY name");
    $s->execute([$userBid]); $bankAccounts=$s->fetchAll(PDO::FETCH_ASSOC);
  }
} catch(Throwable $e){ $bankAccounts=[]; }

/* ---------- WHERE fragments (positional bindings) ---------- */
$W_PURCH=''; $B_PURCH=[];
$W_PAY='';   $B_PAY=[];
$W_RET='';   $B_RET=[];
$W_CARGO=''; $B_CARGO=[];

$W_PURCH .= _wa($W_PURCH)."p.supplier_id=?";  $B_PURCH[]=$supplier_id;
$W_PAY   .= _wa($W_PAY)  ."p.supplier_id=?";  $B_PAY[]  =$supplier_id;
$W_RET   .= _wa($W_RET)  ."p.supplier_id=?";  $B_RET[]  =$supplier_id;
$W_CARGO .= _wa($W_CARGO)."cs.supplier_id=?"; $B_CARGO[]=$supplier_id;

if (!is_null($branch_filter)) {
  $W_PURCH .= _wa($W_PURCH)."p.branch_id=?";  $B_PURCH[]=$branch_filter;
  $W_PAY   .= _wa($W_PAY)  ."p.branch_id=?";  $B_PAY[]  =$branch_filter;
  $W_RET   .= _wa($W_RET)  ."p.branch_id=?";  $B_RET[]  =$branch_filter;
  $W_CARGO .= _wa($W_CARGO)."cs.branch_id=?"; $B_CARGO[]=$branch_filter;
}
if ($start_date!=='' && $end_date!=='') {
  $W_PURCH .= _wa($W_PURCH)."DATE(p.purchase_date) BETWEEN ? AND ?"; $B_PURCH[]=$start_date; $B_PURCH[]=$end_date;
  $W_PAY   .= _wa($W_PAY)  ."DATE(p.purchase_date) BETWEEN ? AND ?"; $B_PAY[]  =$start_date; $B_PAY[]  =$end_date;
  $W_RET   .= _wa($W_RET)  ."DATE(pr.return_date) BETWEEN ? AND ?";  $B_RET[]  =$start_date; $B_RET[]  =$end_date;
  $W_CARGO .= _wa($W_CARGO)."DATE(cs.transfer_date) BETWEEN ? AND ?";$B_CARGO[]=$start_date; $B_CARGO[]=$end_date;
}

/* ---------- schema probes ---------- */
$has_cs_purchase = col_exists($pdo,'cargo_services','purchase_id');
$pp_has_method   = col_exists($pdo,'purchase_payments','method');
$pp_has_bank_col = col_exists($pdo,'purchase_payments','bank_account_id');

// Detect optional cheque detail columns on purchase_payments.  These mirror sale_payments.
$pp_has_cheque_no     = col_exists($pdo,'purchase_payments','cheque_number');
$pp_has_bank_name_col = col_exists($pdo,'purchase_payments','bank_name');
$pp_has_branch_col    = col_exists($pdo,'purchase_payments','bank_branch');
$pp_has_deposit_col   = col_exists($pdo,'purchase_payments','deposit_date');
$pp_has_deposit_bank  = col_exists($pdo,'purchase_payments','deposit_bank_id');

/* ---------- header totals (supplier-level) ---------- */
try{
  $st=$pdo->prepare("SELECT COALESCE(SUM(pi.quantity*pi.cost_price),0) FROM purchases p JOIN purchase_items pi ON pi.purchase_id=p.id $W_PURCH");
  $st->execute($B_PURCH); $purchases_total=(float)$st->fetchColumn();

  $st=$pdo->prepare("SELECT COALESCE(SUM(pp.amount),0) FROM purchases p JOIN purchase_payments pp ON pp.purchase_id=p.id $W_PAY");
  $st->execute($B_PAY); $payments_total=(float)$st->fetchColumn();

  $st=$pdo->prepare("SELECT COALESCE(SUM(pr.total_refund),0) FROM purchase_returns pr JOIN purchases p ON pr.purchase_id=p.id $W_RET");
  $st->execute($B_RET); $returns_total=(float)$st->fetchColumn();

  $st=$pdo->prepare("SELECT COALESCE(SUM(cs.amount),0) FROM cargo_services cs $W_CARGO");
  $st->execute($B_CARGO); $cargo_total=(float)$st->fetchColumn();
}catch(Throwable $e){ $purchases_total=$payments_total=$returns_total=$cargo_total=0.0; }

$supplier_balance = max($purchases_total - $cargo_total - $returns_total - $payments_total, 0.0);

/* ---------- branch balances (for payment caps) ---------- */
function branch_cash_balance(PDO $pdo, ?int $branch_id): float {
  $sum=0.0;
  $st=$pdo->prepare("SELECT COALESCE(SUM(opening_amount),0) FROM cash_openings".(!is_null($branch_id)?" WHERE branch_id=?":""));
  $st->execute(!is_null($branch_id)?[$branch_id]:[]); $sum+=(float)$st->fetchColumn();

  $sql="SELECT COALESCE(SUM(sp.amount),0) FROM sale_payments sp JOIN sales s ON s.id=sp.sale_id WHERE sp.method='cash'";
  if(!is_null($branch_id)) $sql.=" AND s.branch_id=?";
  $st=$pdo->prepare($sql); $st->execute(!is_null($branch_id)?[$branch_id]:[]); $sum+=(float)$st->fetchColumn();

  $sql="SELECT COALESCE(SUM(sr.total_refund),0) FROM sale_returns sr JOIN sales s ON s.id=sr.sale_id WHERE sr.return_type='cash'";
  if(!is_null($branch_id)) $sql.=" AND s.branch_id=?";
  $st=$pdo->prepare($sql); $st->execute(!is_null($branch_id)?[$branch_id]:[]); $sum-=(float)$st->fetchColumn();

  $sql="SELECT COALESCE(SUM(e.amount),0) FROM expenses e"; if(!is_null($branch_id)) $sql.=" WHERE e.branch_id=?";
  $st=$pdo->prepare($sql); $st->execute(!is_null($branch_id)?[$branch_id]:[]); $sum-=(float)$st->fetchColumn();

  // assume all purchase payments are cash if no method column
  $hasMethod=false; try{$c=$pdo->query("SHOW COLUMNS FROM purchase_payments LIKE 'method'"); $hasMethod=(bool)$c->fetch();}catch(Throwable $e){}
  if($hasMethod){
    $sql="SELECT COALESCE(SUM(pp.amount),0) FROM purchase_payments pp JOIN purchases p ON p.id=pp.purchase_id WHERE pp.method='cash'";
    if(!is_null($branch_id)) $sql.=" AND p.branch_id=?";
  }else{
    $sql="SELECT COALESCE(SUM(pp.amount),0) FROM purchase_payments pp JOIN purchases p ON p.id=pp.purchase_id";
    if(!is_null($branch_id)) $sql.=" WHERE p.branch_id=?";
  }
  $st=$pdo->prepare($sql); $st->execute(!is_null($branch_id)?[$branch_id]:[]); $sum-=(float)$st->fetchColumn();

  $sql="SELECT COALESCE(SUM(amount),0) FROM journal_entries WHERE account_type='cash'";
  if(!is_null($branch_id)) $sql.=" AND branch_id=?";
  $st=$pdo->prepare($sql); $st->execute(!is_null($branch_id)?[$branch_id]:[]); $sum+=(float)$st->fetchColumn();

  return max($sum,0.0);
}
function branch_bank_balance(PDO $pdo, ?int $branch_id): float {
  $st=$pdo->prepare("SELECT COALESCE(SUM(opening_balance),0) FROM bank_accounts".(!is_null($branch_id)?" WHERE branch_id=?":""));
  $st->execute(!is_null($branch_id)?[$branch_id]:[]); $open=(float)$st->fetchColumn();
  $sql="SELECT COALESCE(SUM(amount),0) FROM journal_entries WHERE account_type='bank'"; if(!is_null($branch_id)) $sql.=" AND branch_id=?";
  $st=$pdo->prepare($sql); $st->execute(!is_null($branch_id)?[$branch_id]:[]); $j=(float)$st->fetchColumn();
  return max($open+$j,0.0);
}
function branch_wallet_balance(PDO $pdo, ?int $branch_id): float {
  $sql="SELECT COALESCE(SUM(amount),0) FROM journal_entries WHERE account_type='wallet'"; if(!is_null($branch_id)) $sql.=" AND branch_id=?";
  $st=$pdo->prepare($sql); $st->execute(!is_null($branch_id)?[$branch_id]:[]); return max((float)$st->fetchColumn(),0.0);
}
$branchForBalance = $branch_filter ?? $userBid;
$cash_avail   = branch_cash_balance($pdo, $branchForBalance);
$bank_avail   = branch_bank_balance($pdo, $branchForBalance);
$wallet_avail = branch_wallet_balance($pdo, $branchForBalance);

/* ---------- ACTION: transfer to cargo ---------- */
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['transfer_to_cargo'])){
  $purchase_id=(int)($_POST['purchase_id']??0);
  $provider_id=(int)($_POST['provider_id']??0);
  $amount_req=(float)($_POST['amount']??0);
  $reference =trim($_POST['reference_no']??'');
  $notes     =trim($_POST['description']??'');

  try{
    if($purchase_id<=0 || $provider_id<=0 || $amount_req<=0) throw new Exception('Invalid transfer data.');

    // compute row cargo (if supported) and net-based outstanding BEFORE new transfer
    $bind=[$purchase_id,$supplier_id]; $w=" WHERE p.id=? AND p.supplier_id=? ";
    if(!is_null($branch_filter)){ $w.=" AND p.branch_id=? "; $bind[]=$branch_filter; }
    if($start_date!=='' && $end_date!==''){ $w.=" AND DATE(p.purchase_date) BETWEEN ? AND ? "; $bind[]=$start_date; $bind[]=$end_date; }

    $rowCargo=0.0;
    if ($has_cs_purchase) {
      $csql="SELECT COALESCE(SUM(cs.amount),0) FROM cargo_services cs WHERE cs.purchase_id=?";
      $cbind=[$purchase_id];
      if(!is_null($branch_filter)){ $csql.=" AND cs.branch_id=?"; $cbind[]=$branch_filter; }
      if($start_date!=='' && $end_date!==''){ $csql.=" AND DATE(cs.transfer_date) BETWEEN ? AND ?"; $cbind[]=$start_date; $cbind[]=$end_date; }
    }

    $sql="SELECT
            COALESCE(SUM(pi.quantity*pi.cost_price),0) AS total_cost,
            COALESCE((SELECT SUM(pp.amount) FROM purchase_payments pp WHERE pp.purchase_id=p.id),0) AS total_paid,
            COALESCE((SELECT SUM(pr.total_refund) FROM purchase_returns pr WHERE pr.purchase_id=p.id),0) AS total_return
          FROM purchases p JOIN purchase_items pi ON pi.purchase_id=p.id $w GROUP BY p.id";
    $st=$pdo->prepare($sql); $st->execute($bind); $row=$st->fetch(PDO::FETCH_ASSOC);
    if(!$row) throw new Exception('Purchase not found.');
    if($has_cs_purchase){
      $rst=$pdo->prepare($csql); $rst->execute($cbind); $rowCargo=(float)$rst->fetchColumn();
    }

    $cost=(float)$row['total_cost']; $paid=(float)$row['total_paid']; $ret=(float)$row['total_return'];
    $netRow = $has_cs_purchase ? max($cost-$rowCargo,0.0) : $cost; // if we can’t attribute cargo per purchase, net==cost
    $p_out  = max($netRow - $ret - $paid, 0.0); // **NET-BASED outstanding**

    // supplier balance now (already net of cargo at supplier level)
    $st=$pdo->prepare("SELECT COALESCE(SUM(pi.quantity*pi.cost_price),0) FROM purchases p JOIN purchase_items pi ON pi.purchase_id=p.id $W_PURCH");
    $st->execute($B_PURCH); $purchTotNow=(float)$st->fetchColumn();
    $st=$pdo->prepare("SELECT COALESCE(SUM(pp.amount),0) FROM purchases p JOIN purchase_payments pp ON pp.purchase_id=p.id $W_PAY");
    $st->execute($B_PAY); $paidTotNow=(float)$st->fetchColumn();
    $st=$pdo->prepare("SELECT COALESCE(SUM(pr.total_refund),0) FROM purchase_returns pr JOIN purchases p ON pr.purchase_id=p.id $W_RET");
    $st->execute($B_RET); $retTotNow=(float)$st->fetchColumn();
    $st=$pdo->prepare("SELECT COALESCE(SUM(cs.amount),0) FROM cargo_services cs $W_CARGO");
    $st->execute($B_CARGO); $cargoTotNow=(float)$st->fetchColumn();
    $supplierBalNow=max($purchTotNow-$cargoTotNow-$retTotNow-$paidTotNow,0.0);

    $maxAllowed=min($p_out,$supplierBalNow);
    if($maxAllowed<=0) throw new Exception('Nothing to transfer.');
    if($amount_req>$maxAllowed) $amount_req=$maxAllowed;

    if($has_cs_purchase){
      $ins=$pdo->prepare("INSERT INTO cargo_services
        (branch_id,supplier_id,purchase_id,cargo_provider_id,amount,reference_no,description,transfer_date,created_by)
        VALUES (?,?,?,?,?,?,?,?,?)");
      $ins->execute([$branchForBalance,$supplier_id,$purchase_id,$provider_id,$amount_req,$reference,$notes,date('Y-m-d H:i:s'),$user['id']]);
    }else{
      $ins=$pdo->prepare("INSERT INTO cargo_services
        (branch_id,supplier_id,cargo_provider_id,amount,reference_no,description,transfer_date,created_by)
        VALUES (?,?,?,?,?,?,?,?)");
      $ins->execute([$branchForBalance,$supplier_id,$provider_id,$amount_req,$reference,$notes,date('Y-m-d H:i:s'),$user['id']]);
    }

    $_SESSION['flash']=['type'=>'success','msg'=>'Transferred to cargo: Rs. '.number_format($amount_req,2)];
  }catch(Throwable $e){
    $_SESSION['flash']=['type'=>'danger','msg'=>'Error saving transfer: '.htmlspecialchars($e->getMessage())];
  }
  $qs=http_build_query(['id'=>$supplier_id,'branch_id'=>$branch_filter,'start_date'=>$start_date,'end_date'=>$end_date]);
  header("Location: supplier_view.php?".$qs); exit;
}

/* ---------- ACTION: pay (uses net-based outstanding) ---------- */
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['pay_purchase'])){
  $purchase_id=(int)($_POST['purchase_id']??0);
  $amount_req =(float)($_POST['amount']??0);
  $method     = $_POST['method'] ?? 'cash';
  $bank_id    = (isset($_POST['bank_account_id']) && ctype_digit($_POST['bank_account_id'])) ? (int)$_POST['bank_account_id'] : null;

  try{
    if($purchase_id<=0 || $amount_req<=0) throw new Exception('Invalid payment data.');
    if(!in_array($method,['cash','bank','wallet','cheque'])) $method='cash';

    // per-purchase cargo (if supported) and net-based outstanding
    $bind=[$purchase_id,$supplier_id]; $w=" WHERE p.id=? AND p.supplier_id=? ";
    if(!is_null($branch_filter)){ $w.=" AND p.branch_id=? "; $bind[]=$branch_filter; }
    if($start_date!=='' && $end_date!==''){ $w.=" AND DATE(p.purchase_date) BETWEEN ? AND ? "; $bind[]=$start_date; $bind[]=$end_date; }

    $rowCargo=0.0;
    if ($has_cs_purchase) {
      $csql="SELECT COALESCE(SUM(cs.amount),0) FROM cargo_services cs WHERE cs.purchase_id=?";
      $cbind=[$purchase_id];
      if(!is_null($branch_filter)){ $csql.=" AND cs.branch_id=?"; $cbind[]=$branch_filter; }
      if($start_date!=='' && $end_date!==''){ $csql.=" AND DATE(cs.transfer_date) BETWEEN ? AND ?"; $cbind[]=$start_date; $cbind[]=$end_date; }
    }

    $sql="SELECT
            COALESCE(SUM(pi.quantity*pi.cost_price),0) AS total_cost,
            COALESCE((SELECT SUM(pp.amount) FROM purchase_payments pp WHERE pp.purchase_id=p.id),0) AS total_paid,
            COALESCE((SELECT SUM(pr.total_refund) FROM purchase_returns pr WHERE pr.purchase_id=p.id),0) AS total_return
          FROM purchases p JOIN purchase_items pi ON pi.purchase_id=p.id $w GROUP BY p.id";
    $st=$pdo->prepare($sql); $st->execute($bind); $row=$st->fetch(PDO::FETCH_ASSOC);
    if(!$row) throw new Exception('Purchase not found.');
    if($has_cs_purchase){ $rst=$pdo->prepare($csql); $rst->execute($cbind); $rowCargo=(float)$rst->fetchColumn(); }

    $cost=(float)$row['total_cost']; $paid=(float)$row['total_paid']; $ret=(float)$row['total_return'];
    $netRow = $has_cs_purchase ? max($cost-$rowCargo,0.0) : $cost;
    $p_out  = max($netRow - $ret - $paid, 0.0); // **NET-BASED outstanding**

    // method balances
    $cash_avail   = branch_cash_balance($pdo, $branchForBalance);
    $bank_avail   = branch_bank_balance($pdo, $branchForBalance);
    $wallet_avail = branch_wallet_balance($pdo, $branchForBalance);
    $avail = ($method==='cash') ? $cash_avail : (($method==='bank') ? $bank_avail : (($method==='wallet') ? $wallet_avail : 9e15));
    if ($avail <= 0 && $method!=='cheque') throw new Exception('No available balance in the selected method.');

    // supplier balance now
    $st=$pdo->prepare("SELECT COALESCE(SUM(pi.quantity*pi.cost_price),0) FROM purchases p JOIN purchase_items pi ON pi.purchase_id=p.id $W_PURCH");
    $st->execute($B_PURCH); $purchTotNow=(float)$st->fetchColumn();
    $st=$pdo->prepare("SELECT COALESCE(SUM(pp.amount),0) FROM purchases p JOIN purchase_payments pp ON pp.purchase_id=p.id $W_PAY");
    $st->execute($B_PAY); $paidTotNow=(float)$st->fetchColumn();
    $st=$pdo->prepare("SELECT COALESCE(SUM(pr.total_refund),0) FROM purchase_returns pr JOIN purchases p ON pr.purchase_id=p.id $W_RET");
    $st->execute($B_RET); $retTotNow=(float)$st->fetchColumn();
    $st=$pdo->prepare("SELECT COALESCE(SUM(cs.amount),0) FROM cargo_services cs $W_CARGO");
    $st->execute($B_CARGO); $cargoTotNow=(float)$st->fetchColumn();
    $supplierBalNow=max($purchTotNow-$cargoTotNow-$retTotNow-$paidTotNow,0.0);

    $maxAllowed = min($p_out, $supplierBalNow, ($method==='cheque'?9e15:$avail));
    if($maxAllowed<=0) throw new Exception('Insufficient balance or nothing due.');
    if($amount_req>$maxAllowed) $amount_req=$maxAllowed;

    // Insert payment (adapts to schema and captures cheque details)
    // Capture cheque detail fields if provided.  These will only be used when method='cheque'.
    $cheque_no     = isset($_POST['cheque_number']) ? trim($_POST['cheque_number']) : '';
    $cheque_bname  = isset($_POST['cheque_bank_name']) ? trim($_POST['cheque_bank_name']) : '';
    $cheque_branch = isset($_POST['cheque_bank_branch']) ? trim($_POST['cheque_bank_branch']) : '';
    $deposit_date  = isset($_POST['deposit_date']) && $_POST['deposit_date'] !== '' ? $_POST['deposit_date'] : null;

    // Build dynamic insert fields and params
    $cols = ['purchase_id','amount'];
    $params = [$purchase_id,$amount_req];
    if ($pp_has_method) {
      $cols[] = 'method';
      $params[] = $method;
    }
    // Store bank_account_id only when method=bank and column exists
    if ($pp_has_bank_col && $method === 'bank') {
      $cols[] = 'bank_account_id';
      $params[] = $bank_id;
    }
    // Store cheque specific fields when method=cheque and columns exist
    if ($method === 'cheque') {
      if ($pp_has_cheque_no)     { $cols[] = 'cheque_number';    $params[] = $cheque_no; }
      if ($pp_has_bank_name_col) { $cols[] = 'bank_name';        $params[] = $cheque_bname; }
      if ($pp_has_branch_col)    { $cols[] = 'bank_branch';      $params[] = $cheque_branch; }
      if ($pp_has_deposit_col)   { $cols[] = 'deposit_date';     $params[] = $deposit_date; }
    }
    $cols[] = 'paid_by';
    $params[] = $user['id'];
    // Build columns string and placeholder for params; paid_at uses NOW()
    $colsStr = implode(', ', $cols) . ', paid_at';
    $placeholders = implode(', ', array_fill(0, count($params), '?')) . ', NOW()';
    $sqlIns = "INSERT INTO purchase_payments ($colsStr) VALUES ($placeholders)";
    $ins=$pdo->prepare($sqlIns);
    $ins->execute($params);

    $_SESSION['flash']=['type'=>'success','msg'=>'Payment recorded: Rs. '.number_format($amount_req,2).($pp_has_method?(' via '.htmlspecialchars($method)):'')];
  }catch(Throwable $e){
    $_SESSION['flash']=['type'=>'danger','msg'=>'Error recording payment: '.htmlspecialchars($e->getMessage())];
  }
  $qs=http_build_query(['id'=>$supplier_id,'branch_id'=>$branch_filter,'start_date'=>$start_date,'end_date'=>$end_date]);
  header("Location: supplier_view.php?".$qs); exit;
}

/* ---------- table data (with per-purchase cargo if supported) ---------- */
$params=[$supplier_id]; $w=" WHERE p.supplier_id=? ";
if(!is_null($branch_filter)){ $w.=" AND p.branch_id=? "; $params[]=$branch_filter; }
if($start_date!=='' && $end_date!==''){ $w.=" AND DATE(p.purchase_date) BETWEEN ? AND ? "; $params[]=$start_date; $params[]=$end_date; }

$sql="SELECT p.id,p.purchase_date,p.branch_id,b.name AS branch_name,
             SUM(pi.quantity*pi.cost_price) AS total_cost,
             COALESCE((SELECT SUM(pp.amount) FROM purchase_payments pp WHERE pp.purchase_id=p.id),0) AS total_paid,
             COALESCE((SELECT SUM(pr.total_refund) FROM purchase_returns pr WHERE pr.purchase_id=p.id),0) AS total_return
      FROM purchases p
      JOIN purchase_items pi ON pi.purchase_id=p.id
      JOIN branches b ON b.id=p.branch_id
      $w
      GROUP BY p.id
      ORDER BY p.purchase_date DESC,p.id DESC";
$st=$pdo->prepare($sql); $st->execute($params); $purchases=$st->fetchAll(PDO::FETCH_ASSOC);

/* cargo per purchase */
$csStmt=null; $csBindBase=[];
if($has_cs_purchase){
  $csSql="SELECT COALESCE(SUM(amount),0) FROM cargo_services WHERE purchase_id=?";
  if(!is_null($branch_filter)){ $csSql.=" AND branch_id=?"; $csBindBase[]=$branch_filter; }
  if($start_date!=='' && $end_date!==''){ $csSql.=" AND DATE(transfer_date) BETWEEN ? AND ?"; $csBindBase[]=$start_date; $csBindBase[]=$end_date; }
  $csStmt=$pdo->prepare($csSql);
}

/* items loader */
function load_purchase_items(PDO $pdo,int $purchase_id):array{
  try{
    $sql="SELECT pi.product_id,pi.quantity,pi.cost_price,COALESCE(p.name,CONCAT('Product #',pi.product_id)) AS product_name
          FROM purchase_items pi LEFT JOIN products p ON p.id=pi.product_id WHERE pi.purchase_id=?";
    $st=$pdo->prepare($sql); $st->execute([$purchase_id]); return $st->fetchAll(PDO::FETCH_ASSOC);
  }catch(Throwable $e){ return []; }
}

/* compute row values & subset totals (NET-BASED) */
$totalCost=0; $totalPaid=0; $totalReturn=0;
for($i=0;$i<count($purchases);$i++){
  $c=(float)$purchases[$i]['total_cost'];
  $p=(float)$purchases[$i]['total_paid'];
  $r=(float)$purchases[$i]['total_return'];

  $rowCargo=0.0;
  if($has_cs_purchase && $csStmt){
    $bind=array_merge([ (int)$purchases[$i]['id'] ], $csBindBase);
    $csStmt->execute($bind);
    $rowCargo=(float)$csStmt->fetchColumn();
  }

  $netRow = $has_cs_purchase ? max($c-$rowCargo,0.0) : $c;
  $out    = max($netRow - $r - $p, 0.0); // **THIS is shown & used for caps**

  $purchases[$i]['row_cargo']=$rowCargo;
  $purchases[$i]['net_purchase']=$netRow;
  $purchases[$i]['outstanding']=$out;

  $totalCost+=$c; $totalPaid+=$p; $totalReturn+=$r;
}

$flash=$_SESSION['flash'] ?? null; unset($_SESSION['flash']);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Supplier Details</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
  <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
  <style>.table td,.table th{vertical-align:middle}</style>
</head>
<body>
<?php include 'includes/nav.php'; ?>
<div class="container my-4">
  <h3 class="mb-3">Supplier Details</h3>

  <?php if($flash): ?>
    <div class="alert alert-<?= htmlspecialchars($flash['type']) ?>"><?= htmlspecialchars($flash['msg']) ?></div>
  <?php endif; ?>

  <div class="card mb-3">
    <div class="card-header">Supplier & Balances</div>
    <div class="card-body">
      <div class="row g-3">
        <div class="col-md-6">
          <p class="mb-1"><strong>Name:</strong> <?= htmlspecialchars($supplier['name']); ?></p>
          <?php if (!empty($supplier['phone'])): ?><p class="mb-1"><strong>Phone:</strong> <?= htmlspecialchars($supplier['phone']); ?></p><?php endif; ?>
          <?php if (!empty($supplier['address'])): ?><p class="mb-1"><strong>Address:</strong> <?= htmlspecialchars($supplier['address']); ?></p><?php endif; ?>
        </div>
        <div class="col-md-6">
          <div class="alert alert-secondary mb-0">
            <div>Purchases: <strong>Rs. <?= number_format($purchases_total,2); ?></strong></div>
            <div>Cargo Transfers: <strong>Rs. <?= number_format($cargo_total,2); ?></strong></div>
            <div>Returns: <strong>Rs. <?= number_format($returns_total,2); ?></strong></div>
            <div>Payments: <strong>Rs. <?= number_format($payments_total,2); ?></strong></div>
            <hr class="my-2">
            <div class="fw-bold">Supplier Balance (after cargo): Rs. <?= number_format($supplier_balance,2); ?></div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Filters -->
  <form method="get" class="row g-3 align-items-end mb-3">
    <input type="hidden" name="id" value="<?= (int)$supplier_id; ?>">
    <?php if ($role==='admin'): ?>
      <div class="col-md-3">
        <label class="form-label">Branch</label>
        <select name="branch_id" class="form-select">
          <option value="">All</option>
          <?php
          if ($role==='admin'){
            $bs=$pdo->query("SELECT id,name FROM branches ORDER BY name");
            foreach(($bs?$bs->fetchAll(PDO::FETCH_ASSOC):[]) as $b){
              $sel = (!is_null($branch_filter) && (int)$branch_filter===(int)$b['id'])?'selected':'';
              echo '<option value="'.(int)$b['id'].'" '.$sel.'>'.htmlspecialchars($b['name']).'</option>';
            }
          }
          ?>
        </select>
      </div>
    <?php endif; ?>
    <div class="col-md-3"><label class="form-label">Start Date</label>
      <input type="date" name="start_date" class="form-control" value="<?= htmlspecialchars($start_date); ?>"></div>
    <div class="col-md-3"><label class="form-label">End Date</label>
      <input type="date" name="end_date" class="form-control" value="<?= htmlspecialchars($end_date); ?>"></div>
    <div class="col-md-2"><button class="btn btn-primary w-100" type="submit">Filter</button></div>
    <div class="col-md-1"><a class="btn btn-outline-secondary w-100" href="supplier_view.php?id=<?= (int)$supplier_id; ?>">Clear</a></div>
  </form>

  <?php $tableOutstanding=max($totalCost-$totalReturn-$totalPaid,0.0); ?>
  <div class="alert alert-light border">
    <strong>Listed Purchases (summary of raw columns):</strong>
    Cost Rs. <?= number_format($totalCost,2); ?> |
    Returns Rs. <?= number_format($totalReturn,2); ?> |
    Paid Rs. <?= number_format($totalPaid,2); ?> |
    <em>Per-row Outstanding uses NET (cost - cargo) − returns − payments.</em>
  </div>

  <div class="table-responsive">
    <table id="purchasesTable" class="table table-striped table-bordered align-middle">
      <thead>
        <tr>
          <th>Purchases - ID</th>
          <th class="text-end">Purchase Cost (Rs.)</th>
          <th class="text-end">Cargo Transfers (Rs.)</th>
          <th class="text-end">Net Purchases (Rs.)</th>
          <th class="text-end">Payments (Rs.)</th>
          <th class="text-end">Outstanding (Rs.)</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
      <?php
      $modals='';
      foreach($purchases as $p):
        $pid=(int)$p['id'];
        $cost=(float)$p['total_cost'];
        $paid=(float)$p['total_paid'];
        $ret =(float)$p['total_return'];
        $rowCargo=(float)($p['row_cargo'] ?? 0.0);
        $netRow = $has_cs_purchase ? max($cost-$rowCargo,0.0) : $cost;
        $out    = max($netRow - $ret - $paid, 0.0);

        $canAct = ($role==='admin') || ($role==='manager' && (int)$p['branch_id']===$userBid);
        $maxTransfer=min($out,$supplier_balance); // can’t transfer more than net outstanding or supplier balance
        $maxPay=min($out,$supplier_balance);
      ?>
        <tr>
          <td>
            #<?= $pid ?><br>
            <small class="text-muted"><?= htmlspecialchars(date('d-M-Y', strtotime($p['purchase_date']))) ?></small>
            <?php if($role==='admin'): ?><div class="small text-muted"><?= htmlspecialchars($p['branch_name']) ?></div><?php endif; ?>
          </td>
          <td class="text-end"><?= number_format($cost,2) ?></td>
          <td class="text-end"><?= number_format($rowCargo,2) ?></td>
          <td class="text-end fw-semibold"><?= number_format($netRow,2) ?></td>
          <td class="text-end"><?= number_format($paid,2) ?></td>
          <td class="text-end fw-bold"><?= number_format($out,2) ?></td>
          <td class="text-nowrap">
            <button class="btn btn-sm btn-outline-info" data-bs-toggle="modal" data-bs-target="#viewItems<?= $pid ?>">View</button>
            <?php if($canAct && $maxTransfer>0.009): ?>
              <button class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#transferCargo<?= $pid ?>">Transfer to Cargo</button>
            <?php endif; ?>
            <?php if($canAct && $maxPay>0.009): ?>
              <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#payPurchase<?= $pid ?>">Pay</button>
            <?php endif; ?>
            <?php if($maxTransfer<=0.009 && $maxPay<=0.009): ?><span class="text-muted small">N/A</span><?php endif; ?>
          </td>
        </tr>
      <?php
        /* modals content */
        ob_start();
        $items = load_purchase_items($pdo,$pid); $sub=0.0; foreach($items as $it){ $sub += (float)$it['quantity']*(float)$it['cost_price']; }
      ?>
        <!-- View Items -->
        <div class="modal fade" id="viewItems<?= $pid ?>" tabindex="-1" aria-hidden="true">
          <div class="modal-dialog modal-lg"><div class="modal-content">
            <div class="modal-header"><h5 class="modal-title">Purchase #<?= $pid ?> — Items</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
              <div class="table-responsive">
                <table class="table table-sm table-striped">
                  <thead><tr><th>#</th><th>Product</th><th class="text-end">Qty</th><th class="text-end">Cost</th><th class="text-end">Line Total</th></tr></thead>
                  <tbody>
                    <?php if(empty($items)): ?><tr><td colspan="5" class="text-center text-muted">No items</td></tr>
                    <?php else: $i=1; foreach($items as $it): $line=(float)$it['quantity']*(float)$it['cost_price']; ?>
                      <tr>
                        <td><?= $i++ ?></td>
                        <td><?= htmlspecialchars($it['product_name']) ?></td>
                        <td class="text-end"><?= number_format((float)$it['quantity'],2) ?></td>
                        <td class="text-end"><?= number_format((float)$it['cost_price'],2) ?></td>
                        <td class="text-end"><?= number_format($line,2) ?></td>
                      </tr>
                    <?php endforeach; endif; ?>
                  </tbody>
                  <tfoot><tr class="table-light"><th colspan="4" class="text-end">Subtotal</th><th class="text-end"><?= number_format($sub,2) ?></th></tr></tfoot>
                </table>
              </div>
            </div>
            <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div>
          </div></div>
        </div>

        <!-- Transfer to Cargo -->
        <div class="modal fade" id="transferCargo<?= $pid ?>" tabindex="-1" aria-hidden="true">
          <div class="modal-dialog"><form class="modal-content" method="post">
            <div class="modal-header"><h5 class="modal-title">Transfer to Cargo — Purchase #<?= $pid ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
              <div class="mb-2">
                <label class="form-label">Cargo Provider</label>
                <select name="provider_id" class="form-select" required>
                  <option value="">-- Select --</option>
                  <?php foreach($providers as $pr): ?>
                    <option value="<?= (int)$pr['id'] ?>"><?= htmlspecialchars($pr['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="row">
                <div class="col-md-6 mb-2">
                  <label class="form-label">Amount (Rs.)</label>
                  <input type="number" class="form-control" name="amount" step="0.01" min="0.01"
                         max="<?= htmlspecialchars(number_format($maxTransfer,2,'.','')) ?>" required>
                  <div class="form-text">Max: Rs. <?= number_format($maxTransfer,2) ?></div>
                </div>
                <div class="col-md-6 mb-2">
                  <label class="form-label">Reference</label>
                  <input type="text" class="form-control" name="reference_no" placeholder="Optional">
                </div>
              </div>
              <div class="mb-2">
                <label class="form-label">Notes</label>
                <input type="text" class="form-control" name="description" placeholder="Optional">
              </div>
              <input type="hidden" name="purchase_id" value="<?= $pid ?>">
              <input type="hidden" name="transfer_to_cargo" value="1">
            </div>
            <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-warning" type="submit">Transfer</button></div>
          </form></div>
        </div>

        <!-- Pay -->
        <div class="modal fade" id="payPurchase<?= $pid ?>" tabindex="-1" aria-hidden="true">
          <div class="modal-dialog"><form class="modal-content" method="post">
            <div class="modal-header"><h5 class="modal-title">Pay — Purchase #<?= $pid ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
              <p class="mb-2">
                Purchase Cost: <strong>Rs. <?= number_format($cost,2) ?></strong><br>
                Cargo Transfers: <strong>Rs. <?= number_format($rowCargo,2) ?></strong><br>
                Net Purchase: <strong>Rs. <?= number_format($netRow,2) ?></strong><br>
                Returns: <strong>Rs. <?= number_format($ret,2) ?></strong><br>
                Paid: <strong>Rs. <?= number_format($paid,2) ?></strong><br>
                <span class="text-primary">Outstanding (Net − Paid − Returns): <strong>Rs. <?= number_format($out,2) ?></strong></span><br>
                Supplier Balance (after cargo): <strong>Rs. <?= number_format($supplier_balance,2) ?></strong>
              </p>
              <div class="mb-3">
                <label class="form-label">Method <?= $pp_has_method ? '' : '(not stored)' ?></label>
                <select name="method" id="methodSel<?= $pid ?>" class="form-select" required>
                  <option value="cash">Cash (Avail: Rs. <?= number_format($cash_avail,2) ?>)</option>
                  <option value="bank">Bank (Avail: Rs. <?= number_format($bank_avail,2) ?>)</option>
                  <option value="wallet">Wallet (Avail: Rs. <?= number_format($wallet_avail,2) ?>)</option>
                  <option value="cheque">Cheque</option>
                </select>
              </div>
              <div class="mb-3" id="bankBox<?= $pid ?>" style="display:none;">
                <label class="form-label">Bank Account <?= $pp_has_bank_col ? '' : '(not stored)' ?></label>
                <select name="bank_account_id" class="form-select">
                  <option value="">-- Select --</option>
                  <?php foreach($bankAccounts as $ba): ?>
                    <option value="<?= (int)$ba['id'] ?>"><?= htmlspecialchars($ba['name'].' ('.$ba['account_no'].')') ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <!-- Cheque details (shown when method='cheque') -->
              <div id="chequeBox<?= $pid ?>" style="display:none;">
                <div class="mb-2">
                  <label class="form-label">Cheque Number</label>
                  <input type="text" class="form-control" name="cheque_number" placeholder="Cheque No." />
                </div>
                <div class="mb-2">
                  <label class="form-label">Bank Name</label>
                  <input type="text" class="form-control" name="cheque_bank_name" placeholder="Bank Name" />
                </div>
                <div class="mb-2">
                  <label class="form-label">Bank Branch</label>
                  <input type="text" class="form-control" name="cheque_bank_branch" placeholder="Bank Branch" />
                </div>
                <div class="mb-3">
                  <label class="form-label">Deposit Date</label>
                  <input type="date" class="form-control" name="deposit_date" />
                  <div class="form-text">Date the cheque is expected to be deposited</div>
                </div>
              </div>
              <?php
                $maxPayOverall = min($maxPay, max($cash_avail,$bank_avail,$wallet_avail,0)); // initial cap shown
              ?>
              <div class="mb-2">
                <label class="form-label">Amount (Rs.)</label>
                <input type="number" class="form-control" id="amt<?= $pid ?>" name="amount" step="0.01" min="0.01"
                       max="<?= htmlspecialchars(number_format($maxPayOverall,2,'.','')) ?>" required>
                <div class="form-text" id="capMsg<?= $pid ?>">Max allowed depends on Outstanding, Supplier Balance and selected Method balance.</div>
              </div>
              <input type="hidden" name="purchase_id" value="<?= $pid ?>">
              <input type="hidden" name="pay_purchase" value="1">
            </div>
            <div class="modal-footer">
              <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
              <button id="submitPay<?= $pid ?>" class="btn btn-primary" type="submit">Pay</button>
            </div>
          </form></div>
        </div>

        <script>
          (function(){
            const sel=document.getElementById('methodSel<?= $pid ?>');
            const bankBox=document.getElementById('bankBox<?= $pid ?>');
            const chequeBox=document.getElementById('chequeBox<?= $pid ?>');
            const amt=document.getElementById('amt<?= $pid ?>');
            const cap=document.getElementById('capMsg<?= $pid ?>');
            const btn=document.getElementById('submitPay<?= $pid ?>');
            const caps = {
              cash:   <?= json_encode(min($maxPay, $cash_avail)) ?>,
              bank:   <?= json_encode(min($maxPay, $bank_avail)) ?>,
              wallet: <?= json_encode(min($maxPay, $wallet_avail)) ?>,
              cheque: <?= json_encode($maxPay) ?>
            };
            function upd(){
              const m = sel.value;
              bankBox.style.display   = (m==='bank') ? 'block' : 'none';
              chequeBox.style.display = (m==='cheque') ? 'block' : 'none';
              const capVal = (caps[m]||0);
              amt.max = capVal.toFixed(2);
              if (!amt.value || parseFloat(amt.value) > capVal) amt.value = (capVal>0?capVal.toFixed(2):'');
              btn.disabled = capVal <= 0.0001;
              cap.textContent = (capVal>0)
                ? ('Max allowed: Rs. ' + capVal.toFixed(2))
                : 'No balance available for this method.';
            }
            if(sel){ sel.addEventListener('change',upd); upd(); }
          })();
        </script>
      <?php
        $modals .= ob_get_clean();
      endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- modals mount -->
  <div id="modals"><?= $modals ?></div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script>$(function(){ $('#purchasesTable').DataTable({ pageLength:25 }); });</script>
</body>
</html>
