<?php
/**
 * balance_sheet.php
 * Financial summary (Profit & Loss style) with branch/date filters.
 * Drop this file into your project root. Requires:
 *   - includes/header.php (must provide $pdo [PDO], $user [array: id, role_name, branch_id])
 *   - includes/nav.php (optional top nav)
 * Access:
 *   - Admin: can select any branch or "All"
 *   - Manager (or any non-admin): forced to own branch (UI read-only + server-side locked)
 */

require_once 'includes/header.php';
if (function_exists('checkRole')) { checkRole(['admin','manager']); }

// --- Resolve inputs ----------------------------------------------------------
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date   = $_GET['end_date']   ?? date('Y-m-d');

// Normalize incoming branch_id; will be overridden for non-admin below
$incoming_branch_id = isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : 0;

$is_admin  = (isset($user['role_name']) && $user['role_name'] === 'admin');
$user_bid  = (int)($user['branch_id'] ?? 0);

// --- Branch list (for admin dropdown / for showing manager's branch name) ----
$st = $pdo->query("SELECT id, name FROM branches ORDER BY name");
$branches = $st->fetchAll(PDO::FETCH_ASSOC);
$branch_map = [];
foreach ($branches as $b) { $branch_map[(int)$b['id']] = $b['name']; }

// --- Enforce branch scope on server side ------------------------------------
if ($is_admin) {
    // Admin: chosen branch (0 = all)
    $branch_id = $incoming_branch_id;
} else {
    // Non-admin: always own branch (ignore URL tampering)
    $branch_id = $user_bid;
}

// --- Common WHERE snippets & params ------------------------------------------
// Sales/date fields typically DATETIME; expenses typically DATE
$paramsSales    = [$start_date . " 00:00:00", $end_date . " 23:59:59"];
$paramsExpenses = [$start_date, $end_date];
$paramsStock    = [];

$w_sales  = "";
$w_exp    = "";
$w_stock  = "";

if (!$is_admin || $branch_id) {
    // For non-admin it's always true; for admin only if a specific branch selected
    $w_sales = " AND s.branch_id = ? ";
    $w_exp   = " AND e.branch_id = ? ";
    $w_stock = " WHERE sb.branch_id = ? ";

    $paramsSales[]    = $branch_id;
    $paramsExpenses[] = $branch_id;
    $paramsStock[]    = $branch_id;
}

// --- Revenue (from sales.total_amount) ---------------------------------------
$sqlRevenue = "SELECT COALESCE(SUM(s.total_amount),0) AS revenue
               FROM sales s
               WHERE s.sale_date BETWEEN ? AND ? $w_sales";
$st = $pdo->prepare($sqlRevenue);
$st->execute($paramsSales);
$revenue = (float)$st->fetchColumn();

// --- Sale returns reduce revenue and cost of goods sold ------------------------
// Compute total cash refunds (for cash returns) for the period. Exchange returns
// do not reduce revenue; instead, the new sale is captured in sales table.
$sqlReturnRevenue = "SELECT COALESCE(SUM(sr.total_refund),0) FROM sale_returns sr
                      JOIN sales s ON sr.sale_id = s.id
                      WHERE sr.return_type='cash' AND sr.return_date BETWEEN ? AND ?";
// Apply branch filter for returns via sales.branch_id
if ($w_sales) {
    $sqlReturnRevenue .= " AND s.branch_id = ?";
}
$stRetRev = $pdo->prepare($sqlReturnRevenue);
// Bind same date and branch params order as paramsSales
$paramsRet = [$start_date . " 00:00:00", $end_date . " 23:59:59"];
if (!$is_admin || $branch_id) {
    $paramsRet[] = $branch_id;
}
$stRetRev->execute($paramsRet);
$returnRevenue = (float)$stRetRev->fetchColumn();

// Compute cost of returned goods (for any return type)
$sqlReturnCogs = "SELECT COALESCE(SUM(sri.quantity * sb.cost_price),0)
                  FROM sale_return_items sri
                  JOIN sale_returns sr ON sri.sale_return_id = sr.id
                  JOIN sales s ON sr.sale_id = s.id
                  JOIN stock_batches sb ON sri.batch_id = sb.id
                  WHERE sr.return_date BETWEEN ? AND ?";
if ($w_sales) {
    $sqlReturnCogs .= " AND s.branch_id = ?";
}
$stRetCogs = $pdo->prepare($sqlReturnCogs);
$paramsRet2 = [$start_date . " 00:00:00", $end_date . " 23:59:59"];
if (!$is_admin || $branch_id) {
    $paramsRet2[] = $branch_id;
}
$stRetCogs->execute($paramsRet2);
$returnCogs = (float)$stRetCogs->fetchColumn();

// --- Payments received (sum of sale_payments.amount within date range) -------
$sqlPay = "SELECT COALESCE(SUM(sp.amount),0) AS amount
           FROM sale_payments sp
           JOIN sales s ON sp.sale_id = s.id
           WHERE s.sale_date BETWEEN ? AND ? $w_sales";
$st = $pdo->prepare($sqlPay);
$st->execute($paramsSales);
$payments_received = (float)$st->fetchColumn();

// --- Discounts from sale_items (supports percentage & fixed-per-unit) --------
// If your fixed discount is per line (not per unit), replace (si.quantity * si.discount_value)
// with just si.discount_value.
$sqlDisc = "SELECT COALESCE(SUM(
               CASE
                 WHEN si.discount_type='percentage'
                   THEN (si.quantity * si.selling_price) * (si.discount_value/100)
                 WHEN si.discount_type='fixed'
                   THEN si.quantity * si.discount_value
                 ELSE 0
               END
             ),0) AS discount_total
           FROM sale_items si
           JOIN sales s ON si.sale_id = s.id
           WHERE s.sale_date BETWEEN ? AND ? $w_sales";
$st = $pdo->prepare($sqlDisc);
$st->execute($paramsSales);
$discount_total = (float)$st->fetchColumn();

// --- Cost of Goods Sold (COGS) by batch cost --------------------------------
$sqlCOGS = "SELECT COALESCE(SUM(si.quantity * sb.cost_price),0) AS cogs
            FROM sale_items si
            JOIN sales s ON si.sale_id = s.id
            JOIN stock_batches sb ON si.batch_id = sb.id
            WHERE s.sale_date BETWEEN ? AND ? $w_sales";
$st = $pdo->prepare($sqlCOGS);
$st->execute($paramsSales);
$cogs = (float)$st->fetchColumn();

// Adjust revenue and COGS by returns
$revenue -= $returnRevenue;
$cogs    -= $returnCogs;

// --- Expenses (expenses.amount) ----------------------------------------------
$sqlExp = "SELECT COALESCE(SUM(e.amount),0)
           FROM expenses e
           WHERE e.expense_date BETWEEN ? AND ? $w_exp";
$st = $pdo->prepare($sqlExp);
$st->execute($paramsExpenses);
$expenses = (float)$st->fetchColumn();

// --- Closing Stock value at cost (current stock_batches state) ---------------
$sqlStock = "SELECT COALESCE(SUM(sb.quantity * sb.cost_price),0) AS val
             FROM stock_batches sb $w_stock";
$st = $pdo->prepare($sqlStock);
$st->execute($paramsStock);
$closing_stock_value = (float)$st->fetchColumn();

// --- Outstanding receivables (Sales total - payments) ------------------------
$sqlSalesTotal = "SELECT COALESCE(SUM(s.total_amount),0)
                  FROM sales s
                  WHERE s.sale_date BETWEEN ? AND ? $w_sales";
$st = $pdo->prepare($sqlSalesTotal);
$st->execute($paramsSales);
$sales_total = (float)$st->fetchColumn();

// Subtract cash returns from sales total to avoid overstating receivables
$sales_total -= $returnRevenue;

$sqlPayments = "SELECT COALESCE(SUM(sp.amount),0)
                FROM sale_payments sp
                JOIN sales s ON sp.sale_id = s.id
                WHERE s.sale_date BETWEEN ? AND ? $w_sales";
$st = $pdo->prepare($sqlPayments);
$st->execute($paramsSales);
$payments_total = (float)$st->fetchColumn();
$outstanding = max($sales_total - $payments_total, 0.0);

// --- Profit numbers -----------------------------------------------------------
$gross_profit = max($revenue - $cogs, 0.0);
$net_profit   = $gross_profit - $expenses;

// --- Cheques in Hand ----------------------------------------------------------
// Compute the total amount of undeposited customer cheques.  A cheque is in
// hand when the payment method is 'cheque' and deposit_date is NULL.  We do
// not restrict this by date range because outstanding cheques may have been
// received earlier and still affect the balance sheet.  Branch filtering is
// applied so managers see only their branch's cheques, while admin may
// aggregate across all or a selected branch.
try {
    $sqlChq = "SELECT COALESCE(SUM(sp.amount),0) FROM sale_payments sp JOIN sales s ON sp.sale_id = s.id WHERE sp.method='cheque' AND sp.deposit_date IS NULL";
    $paramsChq = [];
    if (!$is_admin || $branch_id) {
        $sqlChq .= " AND s.branch_id = ?";
        $paramsChq[] = $branch_id;
    }
    $stChq = $pdo->prepare($sqlChq);
    $stChq->execute($paramsChq);
    $cheques_in_hand = (float)$stChq->fetchColumn();
} catch (Throwable $e) {
    $cheques_in_hand = 0.0;
}
if (table_exists($pdo, 'opening_customer_payments')) {
    try {
        $sqlOpen = "SELECT COALESCE(SUM(ocp.amount),0) FROM opening_customer_payments ocp LEFT JOIN customers c ON ocp.customer_id = c.id WHERE ocp.method='cheque' AND (ocp.deposit_date IS NULL OR ocp.deposit_date = '' OR ocp.deposit_date = '0000-00-00')";
        $paramsOpen = [];
        if (!$is_admin || $branch_id) {
            $sqlOpen .= " AND COALESCE(ocp.branch_id, c.branch_id, 0) = ?";
            $paramsOpen[] = $branch_id;
        }
        $stOpen = $pdo->prepare($sqlOpen);
        $stOpen->execute($paramsOpen);
        $cheques_in_hand += (float)$stOpen->fetchColumn();
    } catch (Throwable $e) {
        // ignore
    }
}

// --- Helper: branch label for header / manager view ---------------------------
$branch_label = 'All Branches';
if (!$is_admin) {
    $branch_label = $branch_map[$branch_id] ?? 'My Branch';
} elseif ($branch_id) {
    $branch_label = $branch_map[$branch_id] ?? ("Branch #".$branch_id);
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Financial Summary</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body{background:#f6f7fb;font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,'Helvetica Neue',Arial;}
    .summary-card {border:1px solid #e5e7eb;border-radius:12px;padding:18px;background:#fff;box-shadow:0 4px 12px rgba(0,0,0,.05)}
    .summary-card h6{margin:0;color:#6b7280}
    .summary-card .num{font-size:1.2rem;font-weight:700}
    .grid{display:grid;gap:12px;grid-template-columns:repeat(auto-fit,minmax(220px,1fr))}
    .page-title{display:flex;align-items:center;gap:10px}
    .badge-ghost{background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;padding:6px 10px;color:#374151}
  </style>
</head>
<body>
<?php if (file_exists('includes/nav.php')) include 'includes/nav.php'; ?>

<div class="container my-4">
  <div class="page-title mb-2">
    <h3 class="mb-0">Financial Summary (Profit &amp; Loss)</h3>
    <span class="badge-ghost"><?php echo htmlspecialchars($branch_label); ?></span>
  </div>

  <form class="row g-2 align-items-end mb-3" method="get">
    <div class="col-md-3">
      <label class="form-label">Start</label>
      <input type="date" class="form-control" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>" required>
    </div>
    <div class="col-md-3">
      <label class="form-label">End</label>
      <input type="date" class="form-control" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>" required>
    </div>

    <?php if ($is_admin): ?>
      <div class="col-md-3">
        <label class="form-label">Branch</label>
        <select class="form-select" name="branch_id">
          <option value="0"<?php echo ($branch_id===0?' selected':''); ?>>All (admin)</option>
          <?php foreach($branches as $b): ?>
            <option value="<?php echo (int)$b['id']; ?>" <?php echo ($branch_id===(int)$b['id'])?'selected':''; ?>>
              <?php echo htmlspecialchars($b['name']); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    <?php else: ?>
      <div class="col-md-3">
        <label class="form-label">Branch</label>
        <input type="text" class="form-control" value="<?php
          echo htmlspecialchars($branch_map[$branch_id] ?? 'My Branch');
        ?>" disabled>
        <input type="hidden" name="branch_id" value="<?php echo (int)$branch_id; ?>">
      </div>
    <?php endif; ?>

    <div class="col-md-3">
      <button class="btn btn-primary w-100">Filter</button>
    </div>
  </form>

  <!-- Summary in a vertical list: show revenue, discounts, COGS, profits and other key figures
       aligned left/right to make the statement easier to follow. -->
  <div class="card mb-4">
    <div class="card-header">Financial Summary</div>
    <div class="card-body">
      <div class="row g-2 mb-1">
        <div class="col-6">Revenue (Sales)</div>
        <div class="col-6 text-end">Rs. <?php echo number_format($revenue, 2); ?></div>
      </div>
      <div class="row g-2 mb-1">
        <div class="col-6">Discounts</div>
        <div class="col-6 text-end text-danger">-Rs. <?php echo number_format($discount_total, 2); ?></div>
      </div>
      <div class="row g-2 mb-1">
        <div class="col-6">Cost of Goods Sold (COGS)</div>
        <div class="col-6 text-end text-danger">-Rs. <?php echo number_format($cogs, 2); ?></div>
      </div>
      <div class="row g-2 mb-1">
        <div class="col-6">Gross Profit</div>
        <div class="col-6 text-end fw-bold">Rs. <?php echo number_format($gross_profit, 2); ?></div>
      </div>
      <div class="row g-2 mb-1">
        <div class="col-6">Expenses</div>
        <div class="col-6 text-end text-danger">-Rs. <?php echo number_format($expenses, 2); ?></div>
      </div>
      <div class="row g-2 mb-1">
        <div class="col-6">Net Profit</div>
        <div class="col-6 text-end fw-bold">Rs. <?php echo number_format($net_profit, 2); ?></div>
      </div>
      <div class="row g-2 mb-1">
        <div class="col-6">Payments Received</div>
        <div class="col-6 text-end">Rs. <?php echo number_format($payments_received, 2); ?></div>
      </div>
      <div class="row g-2 mb-1">
        <div class="col-6">Cheques In Hand</div>
        <div class="col-6 text-end">Rs. <?php echo number_format($cheques_in_hand, 2); ?></div>
      </div>
      <div class="row g-2 mb-1">
        <div class="col-6">Outstanding (Unpaid)</div>
        <div class="col-6 text-end text-warning">Rs. <?php echo number_format($outstanding, 2); ?></div>
      </div>
      <div class="row g-2">
        <div class="col-6">Closing Stock (cost)</div>
        <div class="col-6 text-end">Rs. <?php echo number_format($closing_stock_value, 2); ?></div>
      </div>
    </div>
  </div>

  <p class="text-muted small mb-0">
    Discounts are derived from <code>sale_items</code>. COGS uses the batch cost tied to each sold line.
    Payments Received sum all sale payments dated within the selected range. Non-admin users are restricted to their own branch both in UI and server logic.
  </p>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
