<?php
// transaction_summary.php — Collapsible "totals → breakdowns" dashboard (PHP 7.0+ friendly)
require_once 'includes/header.php';
checkRole(['admin','manager']);

function col_exists(PDO $pdo, $table, $col){
  try { return (bool)$pdo->query("SHOW COLUMNS FROM `$table` LIKE ".$pdo->quote($col))->fetch(PDO::FETCH_ASSOC); }
  catch(Throwable $e){ return false; }
}
function table_exists(PDO $pdo, string $table): bool {
  try { $q=$pdo->query("SHOW TABLES LIKE ".$pdo->quote($table)); return (bool)($q && $q->fetchColumn()); }
  catch(Throwable $e){ return false; }
}
function in_list_qmarks(array $arr){ return implode(',', array_fill(0, count($arr), '?')); }
function sumOne(PDO $pdo, $sql, array $params){
  try { $st=$pdo->prepare($sql); $st->execute($params); return (float)$st->fetchColumn(); }
  catch(Throwable $e){ return 0.0; }
}

$today = date('Y-m-d');
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : $today;
$end_date   = isset($_GET['end_date'])   ? $_GET['end_date']   : $today;
$supplier_q = trim(isset($_GET['supplier']) ? $_GET['supplier'] : '');

$is_admin = (isset($user['role_name']) && $user['role_name'] === 'admin');
$incoming_branch_id = isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : 0;
$branch_id = $is_admin ? $incoming_branch_id : (int)(isset($user['branch_id']) ? $user['branch_id'] : 0);

/* ---------- Branch list for admin filter ---------- */
$branches = [];
if ($is_admin) {
  try { $branches = $pdo->query("SELECT id,name FROM branches ORDER BY name")->fetchAll(PDO::FETCH_ASSOC); } catch(Throwable $e){}
}
$branch_label = 'All Branches';
if ($is_admin && $branch_id) {
  $branch_label = "Branch #$branch_id";
  foreach ($branches as $b) {
    if ((int)$b['id'] === $branch_id) { $branch_label = $b['name']; break; }
  }
} elseif (!$is_admin && !empty($user['branch_name'])) {
  $branch_label = $user['branch_name'];
}

/* ---------- Method groups ---------- */
$CHEQUE_SYNS = ['cheque','chq','cheq','check','cq'];
$ONLINE_SYNS = ['upi','bank','online'];
$WALLET_SYNS = ['wallet'];

/* ---------- SALES & PAYMENTS ---------- */
// Total Sales (invoice totals in period)
$params = [$start_date.' 00:00:00', $end_date.' 23:59:59'];
$sql = "SELECT COALESCE(SUM(s.total_amount),0)
        FROM sales s
        WHERE s.sale_date BETWEEN ? AND ?";
if (!$is_admin || $branch_id) { $sql .= " AND s.branch_id=?"; $params[]=$branch_id; }
$total_sales = sumOne($pdo,$sql,$params);

// Sale payments by method
function salePaySum(PDO $pdo, array $methods, $start_date, $end_date, $branch_id, $is_admin){
  if (empty($methods)) return 0.0;
  $sql = "SELECT COALESCE(SUM(sp.amount),0)
          FROM sale_payments sp
          JOIN sales s ON s.id = sp.sale_id
          WHERE LOWER(sp.method) IN (".in_list_qmarks($methods).")
            AND s.sale_date BETWEEN ? AND ?";
  $params = array_map('strval',$methods);
  $params[] = $start_date.' 00:00:00';
  $params[] = $end_date.' 23:59:59';
  if (!$is_admin || $branch_id) { $sql .= " AND s.branch_id=?"; $params[]=$branch_id; }
  return sumOne($pdo,$sql,$params);
}
$cash_sales    = salePaySum($pdo, ['cash'], $start_date, $end_date, $branch_id, $is_admin);
$cheque_sales  = salePaySum($pdo, $CHEQUE_SYNS, $start_date,$end_date,$branch_id,$is_admin);
$card_sales    = salePaySum($pdo, ['card'], $start_date, $end_date, $branch_id, $is_admin);
$online_sales  = salePaySum($pdo, $ONLINE_SYNS, $start_date,$end_date,$branch_id,$is_admin);
$wallet_sales  = salePaySum($pdo, $WALLET_SYNS, $start_date,$end_date,$branch_id,$is_admin);

// “Credit Sales (Invoice)” fallback
$credit_sales = max($total_sales - ($cash_sales + $cheque_sales + $card_sales + $online_sales + $wallet_sales), 0);

// Placeholders; wire to your schema if you track them
$exchange_sales = 0.0;
$redeemed_sales = 0.0;

/* ---------- SALES RETURNS ---------- */
$has_return_type = col_exists($pdo,'sale_returns','return_type');
$params = [$start_date.' 00:00:00', $end_date.' 23:59:59'];
$sql = "SELECT COALESCE(SUM(sr.total_refund),0)
        FROM sale_returns sr
        JOIN sales s ON s.id = sr.sale_id
        WHERE sr.return_date BETWEEN ? AND ?";
if (!$is_admin || $branch_id) { $sql .= " AND s.branch_id=?"; $params[]=$branch_id; }
$total_sales_return = sumOne($pdo,$sql,$params);

$sales_ret_cash   = 0.0;
$sales_ret_cheque = 0.0;
$sales_ret_card   = 0.0;
$sales_ret_exch   = 0.0;
if ($has_return_type) {
  // cash
  $sql = "SELECT COALESCE(SUM(sr.total_refund),0)
          FROM sale_returns sr
          JOIN sales s ON s.id = sr.sale_id
          WHERE LOWER(sr.return_type) IN (?)
            AND sr.return_date BETWEEN ? AND ?";
  $params = ['cash', $start_date.' 00:00:00', $end_date.' 23:59:59'];
  if (!$is_admin || $branch_id) { $sql.=" AND s.branch_id=?"; $params[]=$branch_id; }
  $sales_ret_cash = sumOne($pdo,$sql,$params);

  // cheque
  $sql = "SELECT COALESCE(SUM(sr.total_refund),0)
          FROM sale_returns sr
          JOIN sales s ON s.id = sr.sale_id
          WHERE LOWER(sr.return_type) IN (".in_list_qmarks($CHEQUE_SYNS).")
            AND sr.return_date BETWEEN ? AND ?";
  $params = array_merge($CHEQUE_SYNS, [$start_date.' 00:00:00', $end_date.' 23:59:59']);
  if (!$is_admin || $branch_id) { $sql.=" AND s.branch_id=?"; $params[]=$branch_id; }
  $sales_ret_cheque = sumOne($pdo,$sql,$params);

  // card
  $sql = "SELECT COALESCE(SUM(sr.total_refund),0)
          FROM sale_returns sr
          JOIN sales s ON s.id = sr.sale_id
          WHERE LOWER(sr.return_type) IN (?)
            AND sr.return_date BETWEEN ? AND ?";
  $params = ['card', $start_date.' 00:00:00', $end_date.' 23:59:59'];
  if (!$is_admin || $branch_id) { $sql.=" AND s.branch_id=?"; $params[]=$branch_id; }
  $sales_ret_card = sumOne($pdo,$sql,$params);

  // exchange
  $sql = "SELECT COALESCE(SUM(sr.total_refund),0)
          FROM sale_returns sr
          JOIN sales s ON s.id = sr.sale_id
          WHERE LOWER(sr.return_type) IN (?)
            AND sr.return_date BETWEEN ? AND ?";
  $params = ['exchange', $start_date.' 00:00:00', $end_date.' 23:59:59'];
  if (!$is_admin || $branch_id) { $sql.=" AND s.branch_id=?"; $params[]=$branch_id; }
  $sales_ret_exch = sumOne($pdo,$sql,$params);
}

/* ---------- PAYMENTS RECEIVED FROM CUSTOMERS (by method) ---------- */
$recv_cash   = $cash_sales;
$recv_cheque = $cheque_sales;
$recv_card   = $card_sales;
$recv_online = $online_sales;
$total_received = $recv_cash + $recv_cheque + $recv_card + $recv_online;

/* ---------- PURCHASES & SUPPLIER PAYMENTS ---------- */
// Purchase invoices (items value) in period
$params = [$start_date.' 00:00:00', $end_date.' 23:59:59'];
$sql = "SELECT COALESCE(SUM(pi.quantity*pi.cost_price),0)
        FROM purchases p
        JOIN purchase_items pi ON pi.purchase_id=p.id
        WHERE p.purchase_date BETWEEN ? AND ?";
if (!$is_admin || $branch_id) { $sql .= " AND p.branch_id=?"; $params[]=$branch_id; }
$total_purchase = sumOne($pdo,$sql,$params);

// Supplier payments (optionally filter supplier name)
$supplier_join = table_exists($pdo,'suppliers') ? " JOIN suppliers s ON s.id = p.supplier_id " : "";
$supplier_where = "";
$supplier_params = [];
if ($supplier_q !== '' && $supplier_join !== "") { $supplier_where = " AND s.name LIKE ? "; $supplier_params[] = "%".$supplier_q."%"; }

function purchPaySum(PDO $pdo, array $methods, $start_date,$end_date,$branch_id,$is_admin,$supplier_join,$supplier_where,array $supplier_params){
  if (empty($methods)) return 0.0;
  $sql = "SELECT COALESCE(SUM(pp.amount),0)
          FROM purchase_payments pp
          JOIN purchases p ON p.id=pp.purchase_id
          $supplier_join
          WHERE LOWER(pp.method) IN (".in_list_qmarks($methods).")
            AND pp.paid_at BETWEEN ? AND ?";
  $params = array_map('strval',$methods);
  $params[] = $start_date.' 00:00:00';
  $params[] = $end_date.' 23:59:59';
  if (!$is_admin || $branch_id) { $sql.=" AND p.branch_id=?"; $params[]=$branch_id; }
  if ($supplier_where) { $sql .= $supplier_where; $params = array_merge($params,$supplier_params); }
  return sumOne($pdo,$sql,$params);
}
$pay_sup_cash   = purchPaySum($pdo, ['cash'], $start_date,$end_date,$branch_id,$is_admin,$supplier_join,$supplier_where,$supplier_params);
$pay_sup_cheque = purchPaySum($pdo, $CHEQUE_SYNS, $start_date,$end_date,$branch_id,$is_admin,$supplier_join,$supplier_where,$supplier_params);
$pay_sup_card   = purchPaySum($pdo, ['card'], $start_date,$end_date,$branch_id,$is_admin,$supplier_join,$supplier_where,$supplier_params);
$pay_sup_online = purchPaySum($pdo, $ONLINE_SYNS, $start_date,$end_date,$branch_id,$is_admin,$supplier_join,$supplier_where,$supplier_params);
$total_paid_sup = $pay_sup_cash + $pay_sup_cheque + $pay_sup_card + $pay_sup_online;

// “Credit Purchase” fallback
$credit_purchase = max($total_purchase - $total_paid_sup, 0);

// Purchase returns
$params = [$start_date.' 00:00:00', $end_date.' 23:59:59'];
$sql = "SELECT COALESCE(SUM(pr.total_refund),0)
        FROM purchase_returns pr
        JOIN purchases p ON p.id=pr.purchase_id
        WHERE pr.return_date BETWEEN ? AND ?";
if (!$is_admin || $branch_id) { $sql.=" AND p.branch_id=?"; $params[]=$branch_id; }
$total_purchase_return = sumOne($pdo,$sql,$params);
$credit_purchase_return = 0.0; // hook if your schema tracks method/type on returns

/* ---------- EXPENSES ---------- */
$exp_has_method = col_exists($pdo,'expenses','payment_method');
// Total
$params = [$start_date, $end_date];
$sql = "SELECT COALESCE(SUM(e.amount),0) FROM expenses e WHERE e.expense_date BETWEEN ? AND ?";
if (!$is_admin || $branch_id) { $sql.=" AND e.branch_id=?"; $params[]=$branch_id; }
$total_expenses = sumOne($pdo,$sql,$params);

// By method (simple helper)
function expenseSumByMethods(PDO $pdo, array $methods, $start_date,$end_date,$branch_id,$is_admin){
  if (empty($methods)) return 0.0;
  $sql = "SELECT COALESCE(SUM(e.amount),0)
          FROM expenses e
          WHERE e.expense_date BETWEEN ? AND ?
            AND LOWER(e.payment_method) IN (".in_list_qmarks($methods).")";
  if (!$is_admin || $branch_id) { $sql .= " AND e.branch_id=?"; }
  $params = [$start_date,$end_date];
  if (!$is_admin || $branch_id) { $params[]=$branch_id; }
  foreach ($methods as $m) { $params[] = strtolower($m); }
  return sumOne($pdo,$sql,$params);
}
$exp_cash = $exp_cheque = $exp_card = $exp_online = $exp_credit = 0.0;
if ($exp_has_method) {
  $exp_cash   = expenseSumByMethods($pdo, ['cash'],        $start_date,$end_date,$branch_id,$is_admin);
  $exp_cheque = expenseSumByMethods($pdo, $CHEQUE_SYNS,    $start_date,$end_date,$branch_id,$is_admin);
  $exp_card   = expenseSumByMethods($pdo, ['card'],        $start_date,$end_date,$branch_id,$is_admin);
  $exp_online = expenseSumByMethods($pdo, $ONLINE_SYNS,    $start_date,$end_date,$branch_id,$is_admin);
  $exp_credit = expenseSumByMethods($pdo, ['credit'],      $start_date,$end_date,$branch_id,$is_admin);
}

/* ---------- UI ---------- */
function rs($n){ return 'Rs '.number_format($n,2); }
$period_label = ($start_date===$end_date)
  ? ('Today ('.date('d-m-Y', strtotime($start_date)).')')
  : (date('d-m-Y', strtotime($start_date)).' → '.date('d-m-Y', strtotime($end_date)));
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Transaction Summary</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body{background:#f6f7fb;font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,'Helvetica Neue',Arial;}
    .rowline{display:flex;justify-content:space-between;align-items:center;padding:.6rem 1rem;border-bottom:1px solid #eee;background:#fff;}
    .rowline .label{font-weight:500}
    .rowline .amt{font-variant-numeric: tabular-nums;}
    .sub{padding-left:1.5rem;background:#fbfbfd}
    .muted{color:#6b7280}
    .hdr{display:flex;align-items:center;gap:.5rem}
    .hdr .badge-ghost{background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;padding:6px 10px;color:#374151}
    .accordion-button .amt{margin-right:10px}
    .accordion-button .amt{
  margin-left: auto;        /* push to the right */
  white-space: nowrap;
}
.accordion-button::after{
  margin-left: .5rem !important; /* place chevron right next to amount */
}

    
  </style>
</head>
<body>
<?php include 'includes/nav.php'; ?>
<div class="container my-4">
  <div class="hdr mb-3">
    <h4 class="mb-0">Transaction Summary</h4>
    <span class="badge-ghost"><?php echo htmlspecialchars($branch_label); ?></span>
    <span class="badge-ghost"><?php echo htmlspecialchars($period_label); ?></span>
  </div>

  <!-- Filters -->
  <form method="get" class="row g-2 mb-3">
    <div class="col-md-3">
      <label class="form-label">Start Date</label>
      <input type="date" name="start_date" class="form-control" value="<?php echo htmlspecialchars($start_date); ?>">
    </div>
    <div class="col-md-3">
      <label class="form-label">End Date</label>
      <input type="date" name="end_date" class="form-control" value="<?php echo htmlspecialchars($end_date); ?>">
    </div>
    <?php if ($is_admin): ?>
      <div class="col-md-3">
        <label class="form-label">Branch</label>
        <select name="branch_id" class="form-select">
          <option value="0" <?php echo ($branch_id===0?'selected':''); ?>>All</option>
          <?php foreach ($branches as $b): ?>
            <option value="<?php echo (int)$b['id']; ?>" <?php echo ($branch_id===(int)$b['id']?'selected':''); ?>>
              <?php echo htmlspecialchars($b['name']); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    <?php endif; ?>
    <div class="col-md-3">
      <label class="form-label">Supplier (for “Payment to Supplier”)</label>
      <input type="text" name="supplier" class="form-control" placeholder="e.g., Jim" value="<?php echo htmlspecialchars($supplier_q); ?>">
    </div>
    <div class="col-12 d-flex gap-2">
      <button class="btn btn-primary">Apply</button>
      <a class="btn btn-outline-secondary" href="?start_date=<?php echo $today; ?>&end_date=<?php echo $today; ?><?php echo $is_admin?('&branch_id='.$branch_id):''; ?>">Today</a>
      <a class="btn btn-outline-secondary" href="?start_date=<?php echo date('Y-m-01'); ?>&end_date=<?php echo $today; ?><?php echo $is_admin?('&branch_id='.$branch_id):''; ?>">This Month</a>
      <a class="btn btn-outline-secondary" href="?start_date=<?php echo date('Y-01-01'); ?>&end_date=<?php echo $today; ?><?php echo $is_admin?('&branch_id='.$branch_id):''; ?>">This Year</a>
    </div>
  </form>

  <!-- Accordion list -->
  <div class="accordion" id="tsAccordion">

    <!-- Total Sales -->
    <div class="accordion-item">
      <h2 class="accordion-header" id="h-sales">
        <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#c-sales" aria-expanded="true" aria-controls="c-sales">
          <span>Total Sales</span>
          <span class="amt ms-auto"><?php echo rs($total_sales); ?></span>
        </button>
      </h2>
      <div id="c-sales" class="accordion-collapse collapse show" aria-labelledby="h-sales" data-bs-parent="#tsAccordion">
        <div class="accordion-body p-0">
          <div class="rowline sub"><span class="label">Total Cash Sales</span><span class="amt"><?php echo rs($cash_sales); ?></span></div>
          <div class="rowline sub"><span class="label">Total Cheque Sales</span><span class="amt"><?php echo rs($cheque_sales); ?></span></div>
          <div class="rowline sub"><span class="label">Total Card Sales</span><span class="amt"><?php echo rs($card_sales); ?></span></div>
          <div class="rowline sub"><span class="label">Total Online Transaction Sales</span><span class="amt"><?php echo rs($online_sales); ?></span></div>
          <div class="rowline sub"><span class="label">Total Credit Sales (Invoice)</span><span class="amt"><?php echo rs($credit_sales); ?></span></div>
          <div class="rowline sub"><span class="label">Total Exchange Sales</span><span class="amt"><?php echo rs($exchange_sales); ?></span></div>
          <div class="rowline sub"><span class="label">Total Redeemed Sales</span><span class="amt"><?php echo rs($redeemed_sales); ?></span></div>
        </div>
      </div>
    </div>

    <!-- Sales Return -->
    <div class="accordion-item">
      <h2 class="accordion-header" id="h-sr">
        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#c-sr" aria-controls="c-sr">
          <span>Total Sales Return</span>
          <span class="amt ms-auto"><?php echo rs($total_sales_return); ?></span>
        </button>
      </h2>
      <div id="c-sr" class="accordion-collapse collapse" aria-labelledby="h-sr" data-bs-parent="#tsAccordion">
        <div class="accordion-body p-0">
          <div class="rowline sub"><span class="label">Total Cash Return</span><span class="amt"><?php echo rs($sales_ret_cash); ?></span></div>
          <div class="rowline sub"><span class="label">Total Cheque Return</span><span class="amt"><?php echo rs($sales_ret_cheque); ?></span></div>
          <div class="rowline sub"><span class="label">Total Exchange Return</span><span class="amt"><?php echo rs($sales_ret_exch); ?></span></div>
          <div class="rowline sub"><span class="label">Total Card Return</span><span class="amt"><?php echo rs($sales_ret_card); ?></span></div>
          <div class="rowline sub"><span class="label">Total Credit Return</span><span class="amt"><?php echo rs(0); ?></span></div>
        </div>
      </div>
    </div>

    <!-- Payments received from customer -->
    <div class="accordion-item">
      <h2 class="accordion-header" id="h-rec">
        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#c-rec" aria-controls="c-rec">
          <span>Total Payment Received from Customer</span>
          <span class="amt ms-auto"><?php echo rs($total_received); ?></span>
        </button>
      </h2>
      <div id="c-rec" class="accordion-collapse collapse" aria-labelledby="h-rec" data-bs-parent="#tsAccordion">
        <div class="accordion-body p-0">
          <div class="rowline sub"><span class="label">Total Cash Payment</span><span class="amt"><?php echo rs($recv_cash); ?></span></div>
          <div class="rowline sub"><span class="label">Total Cheque Payment</span><span class="amt"><?php echo rs($recv_cheque); ?></span></div>
          <div class="rowline sub"><span class="label">Total Card Payment</span><span class="amt"><?php echo rs($recv_card); ?></span></div>
          <div class="rowline sub"><span class="label">Total Online Transaction Payment</span><span class="amt"><?php echo rs($recv_online); ?></span></div>
        </div>
      </div>
    </div>

    <!-- Purchases -->
    <div class="accordion-item">
      <h2 class="accordion-header" id="h-pur">
        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#c-pur" aria-controls="c-pur">
          <span>Total Purchase</span>
          <span class="amt ms-auto"><?php echo rs($total_purchase); ?></span>
        </button>
      </h2>
      <div id="c-pur" class="accordion-collapse collapse" aria-labelledby="h-pur" data-bs-parent="#tsAccordion">
        <div class="accordion-body p-0">
          <div class="rowline sub"><span class="label">Total Credit Purchase</span><span class="amt"><?php echo rs($credit_purchase); ?></span></div>
        </div>
      </div>
    </div>

    <!-- Purchase Return -->
    <div class="accordion-item">
      <h2 class="accordion-header" id="h-pr">
        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#c-pr" aria-controls="c-pr">
          <span>Total Purchase Return</span>
          <span class="amt ms-auto"><?php echo rs($total_purchase_return); ?></span>
        </button>
      </h2>
      <div id="c-pr" class="accordion-collapse collapse" aria-labelledby="h-pr" data-bs-parent="#tsAccordion">
        <div class="accordion-body p-0">
          <div class="rowline sub"><span class="label">Total Credit Purchase Return</span><span class="amt"><?php echo rs($credit_purchase_return); ?></span></div>
        </div>
      </div>
    </div>

    <!-- Paid to Supplier -->
    <div class="accordion-item">
      <h2 class="accordion-header" id="h-supp">
        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#c-supp" aria-controls="c-supp">
          <span>Total Payment paid to Supplier<?php echo $supplier_q ? ' — '.htmlspecialchars($supplier_q) : ''; ?></span>
          <span class="amt ms-auto"><?php echo rs($total_paid_sup); ?></span>
        </button>
      </h2>
      <div id="c-supp" class="accordion-collapse collapse" aria-labelledby="h-supp" data-bs-parent="#tsAccordion">
        <div class="accordion-body p-0">
          <div class="rowline sub"><span class="label">Total Cash Payment</span><span class="amt"><?php echo rs($pay_sup_cash); ?></span></div>
          <div class="rowline sub"><span class="label">Total Cheque Payment</span><span class="amt"><?php echo rs($pay_sup_cheque); ?></span></div>
          <div class="rowline sub"><span class="label">Total Card Payment</span><span class="amt"><?php echo rs($pay_sup_card); ?></span></div>
          <div class="rowline sub"><span class="label">Total Online Transaction</span><span class="amt"><?php echo rs($pay_sup_online); ?></span></div>
        </div>
      </div>
    </div>

    <!-- Expenses -->
    <div class="accordion-item">
      <h2 class="accordion-header" id="h-exp">
        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#c-exp" aria-controls="c-exp">
          <span>Total Expenses</span>
          <span class="amt ms-auto"><?php echo rs($total_expenses); ?></span>
        </button>
      </h2>
      <div id="c-exp" class="accordion-collapse collapse" aria-labelledby="h-exp" data-bs-parent="#tsAccordion">
        <div class="accordion-body p-0">
          <div class="rowline sub"><span class="label">Total Expenses paid by Cash</span><span class="amt"><?php echo rs($exp_cash); ?></span></div>
          <div class="rowline sub"><span class="label">Total Expenses paid by Cheque</span><span class="amt"><?php echo rs($exp_cheque); ?></span></div>
          <div class="rowline sub"><span class="label">Total Expenses paid by Card</span><span class="amt"><?php echo rs($exp_card); ?></span></div>
          <div class="rowline sub"><span class="label">Total Expenses paid by Online Transaction</span><span class="amt"><?php echo rs($exp_online); ?></span></div>
          <div class="rowline sub"><span class="label">Total Expenses paid by Credit</span><span class="amt"><?php echo rs($exp_credit); ?></span></div>
        </div>
      </div>
    </div>

  </div><!-- /accordion -->
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
