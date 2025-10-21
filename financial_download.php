<?php
// Provides a CSV download of either the profit summary or balance sheet for the selected period.
require_once 'includes/header.php';

if (!function_exists('table_exists_download')) {
    function table_exists_download(PDO $pdo, string $table): bool {
        try {
            $stmt = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table));
            return (bool)$stmt->fetch(PDO::FETCH_NUM);
        } catch (Throwable $e) {
            return false;
        }
    }
}
// Only admin or manager can download financial statements
checkRole(['admin','manager']);

// Determine which section to download
$section = $_GET['section'] ?? '';
$range   = $_GET['range'] ?? 'today';
$start   = $_GET['start_date'] ?? '';
$end     = $_GET['end_date'] ?? '';
$branch_id = isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : 0;

// Derive date range for presets
function derive_dates($range, $start, $end) {
    $tz = new DateTimeZone(date_default_timezone_get());
    $today = new DateTime('today', $tz);
    if ($range !== 'custom') {
        switch ($range) {
            case 'today':
                return [$today->format('Y-m-d'), $today->format('Y-m-d')];
            case 'yesterday':
                $y = (clone $today)->modify('-1 day');
                return [$y->format('Y-m-d'), $y->format('Y-m-d')];
            case 'month':
                $startDt = (clone $today)->modify('first day of this month');
                $endDt   = (clone $today)->modify('last day of this month');
                return [$startDt->format('Y-m-d'), $endDt->format('Y-m-d')];
            case 'year':
                $startDt = (clone $today)->modify('first day of January');
                $endDt   = (clone $today)->modify('last day of December');
                return [$startDt->format('Y-m-d'), $endDt->format('Y-m-d')];
        }
    }
    return [$start ?: date('Y-m-d'), $end ?: date('Y-m-d')];
}
[$start_date, $end_date] = derive_dates($range, $start, $end);

// Branch scope: manager restricted to own branch; admin may choose
$is_admin = ($user['role_name'] === 'admin');
if (!$is_admin) {
    $branch_id = (int)($user['branch_id'] ?? 0);
}

// Utility to run a scalar query with optional branch condition
function scalar($pdo, $sql, $params) {
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return (float)$st->fetchColumn();
}

// Build where clauses
$w_sales = '';
$w_stock = '';
$w_exp   = '';
$w_pur   = '';
$w_trans = '';
$w_returns = '';
$paramsBranch = [];
if (!$is_admin || $branch_id) {
    $w_sales  = ' AND s.branch_id = ?';
    $w_stock  = ' WHERE sb.branch_id = ?';
    $w_exp    = ' AND e.branch_id = ?';
    $w_pur    = ' AND pu.branch_id = ?';
    $w_trans  = ' AND st.to_branch_id = ?';
    $w_returns= ' AND s.branch_id = ?';
    $paramsBranch[] = $branch_id;
}

// Compute high-level figures similar to financial_statement.php
$start_dt = $start_date . ' 00:00:00';
$end_dt   = $end_date . ' 23:59:59';

// 1. Revenue
$sqlRevenue = "SELECT COALESCE(SUM(si.quantity * si.selling_price),0) 
              FROM sale_items si
              JOIN sales s ON si.sale_id = s.id
              WHERE s.sale_date BETWEEN ? AND ?" . $w_sales;
$paramsRevenue = [$start_dt, $end_dt];
if ($paramsBranch) { $paramsRevenue[] = $branch_id; }
$revenue = scalar($pdo, $sqlRevenue, $paramsRevenue);

// 2. Discount
$sqlDisc = "SELECT COALESCE(SUM(
               CASE 
                 WHEN si.discount_type='percentage' THEN (si.quantity*si.selling_price) * (si.discount_value/100)
                 ELSE si.discount_value
               END
             ),0)
             FROM sale_items si
             JOIN sales s ON si.sale_id = s.id
             WHERE s.sale_date BETWEEN ? AND ?" . $w_sales;
$paramsDisc = [$start_dt, $end_dt];
if ($paramsBranch) { $paramsDisc[] = $branch_id; }
$discount_total = scalar($pdo, $sqlDisc, $paramsDisc);

// 3. Sales returns
$sqlRet = "SELECT COALESCE(SUM(sr.total_refund),0)
           FROM sale_returns sr
           JOIN sales s ON sr.sale_id = s.id
           WHERE sr.return_date BETWEEN ? AND ?" . $w_returns;
$paramsRet = [$start_dt, $end_dt];
if ($paramsBranch) { $paramsRet[] = $branch_id; }
$returnRevenue = scalar($pdo, $sqlRet, $paramsRet);

// 4. Net sales before discount
$sales_gross = $revenue;
$sales_return = $returnRevenue;
$net_sales_before_discount = $sales_gross - $sales_return;

// 5. COGS (cost of goods sold)
$sqlCOGS = "SELECT COALESCE(SUM(si.quantity * sb.cost_price),0)
            FROM sale_items si
            JOIN sales s ON si.sale_id = s.id
            JOIN stock_batches sb ON si.batch_id = sb.id
            WHERE s.sale_date BETWEEN ? AND ?" . $w_sales;
$paramsCOGS = [$start_dt, $end_dt];
if ($paramsBranch) { $paramsCOGS[] = $branch_id; }
$cogs = scalar($pdo, $sqlCOGS, $paramsCOGS);

// 6. Expenses (system expenses)
$sqlExpenses = "SELECT COALESCE(SUM(e.amount),0) FROM expenses e WHERE e.expense_date BETWEEN ? AND ?" . $w_exp;
$paramsExp = [$start_date, $end_date];
if ($paramsBranch) { $paramsExp[] = $branch_id; }
$expenses = scalar($pdo, $sqlExpenses, $paramsExp);

// 7. Worker payments as expenses
$sqlWorkerExp = "SELECT COALESCE(SUM(wp.amount),0) FROM worker_payments wp JOIN workers w ON wp.worker_id=w.id WHERE wp.payment_date BETWEEN ? AND ?";
$paramsWorkerExp = [$start_date, $end_date];
if (!$is_admin || $branch_id) {
    $sqlWorkerExp .= ' AND w.branch_id = ?';
    $paramsWorkerExp[] = $branch_id;
}
$worker_expenses = scalar($pdo, $sqlWorkerExp, $paramsWorkerExp);
$total_expenses = $expenses + $worker_expenses;

// 8. Discount is computed above in $discount_total

// 9. Net profit
$net_profit = max(($net_sales_before_discount - $discount_total - $cogs - $total_expenses), 0.0);

// Inventory (closing stock) at end date
$sqlStock = "SELECT COALESCE(SUM(sb.quantity * sb.cost_price),0) FROM stock_batches sb" . $w_stock;
$paramsStock = [];
if ($paramsBranch) { $paramsStock[] = $branch_id; }
$closing_stock = scalar($pdo, $sqlStock, $paramsStock);

// Outstanding receivables
$sqlPayments = "SELECT COALESCE(SUM(sp.amount),0) FROM sale_payments sp JOIN sales s ON sp.sale_id=s.id WHERE s.sale_date BETWEEN ? AND ?" . $w_sales;
$paramsPay = [$start_dt, $end_dt];
if ($paramsBranch) { $paramsPay[] = $branch_id; }
$payments_received = scalar($pdo, $sqlPayments, $paramsPay);
$net_sales_after_discount = $net_sales_before_discount - $discount_total;
$outstanding = max($net_sales_after_discount - $payments_received, 0.0);

// Cash, bank and cheque balances (simplified from financial_statement.php)
// Cash opening + cash sales - cash returns - expenses - purchase payments - worker cash payments
$sqlOpeningCash = "SELECT COALESCE(SUM(opening_amount),0) FROM cash_openings WHERE opening_date BETWEEN ? AND ?";
$paramsOC = [$start_date, $end_date];
if ($paramsBranch) { $sqlOpeningCash .= ' AND branch_id=?'; $paramsOC[] = $branch_id; }
$opening_cash = scalar($pdo, $sqlOpeningCash, $paramsOC);
$sqlJournalCash = "SELECT COALESCE(SUM(amount),0) FROM journal_entries WHERE account_type='cash' AND entry_date BETWEEN ? AND ?";
$paramsJC = [$start_date, $end_date];
if ($paramsBranch) { $sqlJournalCash .= ' AND branch_id=?'; $paramsJC[] = $branch_id; }
$journal_cash = scalar($pdo, $sqlJournalCash, $paramsJC);
$sqlCashSales = "SELECT COALESCE(SUM(sp.amount),0) FROM sale_payments sp JOIN sales s ON sp.sale_id=s.id WHERE sp.method='cash' AND s.sale_date BETWEEN ? AND ?" . $w_sales;
$paramsCS = [$start_dt, $end_dt];
if ($paramsBranch) { $paramsCS[] = $branch_id; }
$cash_sales = scalar($pdo, $sqlCashSales, $paramsCS);
$sqlCashReturns = "SELECT COALESCE(SUM(sr.total_refund),0) FROM sale_returns sr JOIN sales s ON sr.sale_id=s.id WHERE sr.return_type='cash' AND sr.return_date BETWEEN ? AND ?" . $w_returns;
$paramsCR = [$start_dt, $end_dt];
if ($paramsBranch) { $paramsCR[] = $branch_id; }
$cash_returns = scalar($pdo, $sqlCashReturns, $paramsCR);
$sqlCashExp = "SELECT COALESCE(SUM(e.amount),0) FROM expenses e WHERE e.expense_date BETWEEN ? AND ?" . $w_exp;
$paramsCExp = [$start_date, $end_date];
if ($paramsBranch) { $paramsCExp[] = $branch_id; }
$cash_expenses = scalar($pdo, $sqlCashExp, $paramsCExp);
$sqlCashPurPay = "SELECT COALESCE(SUM(pp.amount),0) FROM purchase_payments pp JOIN purchases p ON pp.purchase_id=p.id WHERE pp.paid_at BETWEEN ? AND ?";
$paramsCPP = [$start_dt, $end_dt];
if (!$is_admin || $branch_id) {
    $sqlCashPurPay .= ' AND p.branch_id = ?';
    $paramsCPP[] = $branch_id;
}
$cash_purchase_payments = scalar($pdo, $sqlCashPurPay, $paramsCPP);
$sqlWorkerCash = "SELECT COALESCE(SUM(wp.amount),0) FROM worker_payments wp JOIN workers w ON wp.worker_id=w.id WHERE wp.method='cash' AND wp.payment_date BETWEEN ? AND ?";
$paramsWC = [$start_date, $end_date];
if (!$is_admin || $branch_id) {
    $sqlWorkerCash .= ' AND w.branch_id = ?';
    $paramsWC[] = $branch_id;
}
$worker_cash = scalar($pdo, $sqlWorkerCash, $paramsWC);
$cash_in_hand = $opening_cash + $journal_cash + $cash_sales - $cash_returns - $cash_expenses - $cash_purchase_payments - $worker_cash;

// Bank balance: non-cash sale payments minus worker bank payments
$sqlBankSales = "SELECT COALESCE(SUM(sp.amount),0) FROM sale_payments sp JOIN sales s ON sp.sale_id=s.id WHERE sp.method <> 'cash' AND (sp.method <> 'cheque' OR sp.deposit_date IS NOT NULL) AND s.sale_date BETWEEN ? AND ?" . $w_sales;
$paramsBS = [$start_dt, $end_dt];
if ($paramsBranch) { $paramsBS[] = $branch_id; }
$bank_sales = scalar($pdo, $sqlBankSales, $paramsBS);
$sqlWorkerBank = "SELECT COALESCE(SUM(wp.amount),0) FROM worker_payments wp JOIN workers w ON wp.worker_id=w.id WHERE wp.method='bank' AND wp.payment_date BETWEEN ? AND ?";
$paramsWB = [$start_date, $end_date];
if (!$is_admin || $branch_id) {
    $sqlWorkerBank .= ' AND w.branch_id = ?';
    $paramsWB[] = $branch_id;
}
$worker_bank = scalar($pdo, $sqlWorkerBank, $paramsWB);
$bank_balance = $bank_sales - $worker_bank;

// Cheque in hand: undeposited cheques
$sqlCheque = "SELECT COALESCE(SUM(sp.amount),0) FROM sale_payments sp JOIN sales s ON sp.sale_id=s.id WHERE sp.method='cheque' AND sp.deposit_date IS NULL AND s.sale_date BETWEEN ? AND ?" . $w_sales;
$paramsCh = [$start_dt, $end_dt];
if ($paramsBranch) { $paramsCh[] = $branch_id; }
$cheque_in_hand = scalar($pdo, $sqlCheque, $paramsCh);
if (table_exists_download($pdo, 'opening_customer_payments')) {
    $sqlOpen = "SELECT COALESCE(SUM(ocp.amount),0) FROM opening_customer_payments ocp LEFT JOIN customers c ON ocp.customer_id = c.id WHERE ocp.method='cheque' AND (ocp.deposit_date IS NULL OR ocp.deposit_date = '' OR ocp.deposit_date = '0000-00-00')";
    $paramsOpen = [];
    if ($paramsBranch) {
        $sqlOpen .= " AND COALESCE(ocp.branch_id, c.branch_id, 0) = ?";
        $paramsOpen[] = $branch_id;
    }
    $cheque_in_hand += scalar($pdo, $sqlOpen, $paramsOpen);
}
$cheque_return = 0.0;

// Accounts payable (purchases + transfer cost - payments - purchase returns)
$sqlPurchases = "SELECT COALESCE(SUM(pi.quantity * pi.cost_price),0) FROM purchases pu JOIN purchase_items pi ON pi.purchase_id=pu.id WHERE pu.purchase_date BETWEEN ? AND ?" . $w_pur;
$paramsPur = [$start_dt, $end_dt];
if (!$is_admin || $branch_id) { $paramsPur[] = $branch_id; }
$purchase_total = scalar($pdo, $sqlPurchases, $paramsPur);
// Transfer cost: multiply quantity by cost price from stock_batches for each batch
$sqlTC = "SELECT COALESCE(SUM(sti.quantity * sb.cost_price),0)
          FROM stock_transfer_items sti
          JOIN stock_transfers st ON sti.transfer_id = st.id
          JOIN stock_batches sb ON sti.batch_id = sb.id
          WHERE st.transferred_at BETWEEN ? AND ?" . $w_trans;
$paramsTC = [$start_dt, $end_dt];
if (!$is_admin || $branch_id) { $paramsTC[] = $branch_id; }
$transfer_cost = scalar($pdo, $sqlTC, $paramsTC);
$purchase_total_with_transfer = $purchase_total + $transfer_cost;
// Purchase payments
$sqlPurPay = "SELECT COALESCE(SUM(pp.amount),0) FROM purchase_payments pp JOIN purchases p ON pp.purchase_id=p.id WHERE pp.paid_at BETWEEN ? AND ?";
$paramsPPay = [$start_dt, $end_dt];
if (!$is_admin || $branch_id) { $sqlPurPay .= ' AND p.branch_id = ?'; $paramsPPay[] = $branch_id; }
$purchase_payments = scalar($pdo, $sqlPurPay, $paramsPPay);
// Transfer payments
$sqlTPay = "SELECT COALESCE(SUM(tp.amount),0) FROM transfer_payments tp JOIN stock_transfers st ON tp.transfer_id=st.id WHERE tp.paid_at BETWEEN ? AND ?";
$paramsTPay = [$start_dt, $end_dt];
if (!$is_admin || $branch_id) { $sqlTPay .= ' AND st.to_branch_id = ?'; $paramsTPay[] = $branch_id; }
$transfer_payments = scalar($pdo, $sqlTPay, $paramsTPay);
// Purchase returns
$sqlPurReturn = "SELECT COALESCE(SUM(pr.total_refund),0) FROM purchase_returns pr JOIN purchases pu ON pr.purchase_id=pu.id WHERE pr.return_date BETWEEN ? AND ?";
$paramsPR = [$start_dt, $end_dt];
if (!$is_admin || $branch_id) { $sqlPurReturn .= ' AND pu.branch_id = ?'; $paramsPR[] = $branch_id; }
$purchase_return_total = scalar($pdo, $sqlPurReturn, $paramsPR);

$accounts_payable = max($purchase_total_with_transfer - $purchase_payments - $transfer_payments - $purchase_return_total, 0.0);

// Drawings (write-offs)
$sqlDraw = "SELECT COALESCE(SUM(wo.quantity * wo.cost_price),0) FROM write_offs wo WHERE wo.date BETWEEN ? AND ?";
$paramsDraw = [$start_date, $end_date];
if (!$is_admin || $branch_id) { $sqlDraw .= ' AND wo.branch_id=?'; $paramsDraw[] = $branch_id; }
$drawings = scalar($pdo, $sqlDraw, $paramsDraw);

// Equity calculations
$total_assets = $closing_stock + $outstanding + $cash_in_hand + $bank_balance + $cheque_return + $cheque_in_hand;
$opening_equity = $total_assets - $accounts_payable - ($net_profit - $drawings);
$closing_equity = $opening_equity + $net_profit - $drawings;

// Create CSV output
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="financial_' . $section . '_' . $start_date . '_' . $end_date . '.csv"');
$output = fopen('php://output', 'w');
if ($section === 'profit') {
    fputcsv($output, ['Metric','Amount']);
    fputcsv($output, ['Sales', $revenue]);
    fputcsv($output, ['(-) Sales Return', -$sales_return]);
    fputcsv($output, ['Net Sales (before discount)', $net_sales_before_discount]);
    fputcsv($output, ['(-) Discount', -$discount_total]);
    fputcsv($output, ['Net Sales (after discount)', $net_sales_before_discount - $discount_total]);
    fputcsv($output, ['Cost of Goods Sold', -$cogs]);
    fputcsv($output, ['Gross Profit', $net_sales_before_discount - $discount_total - $cogs]);
    fputcsv($output, ['Other Income', 0]);
    fputcsv($output, ['Total Expenses', -$total_expenses]);
    fputcsv($output, ['Net Profit', $net_profit]);
} elseif ($section === 'balance') {
    fputcsv($output, ['Category','Item','Amount']);
    // Assets
    fputcsv($output, ['Assets','Inventory', $closing_stock]);
    fputcsv($output, ['Assets','Debtors', $outstanding]);
    fputcsv($output, ['Assets','Cash in Hand', $cash_in_hand]);
    fputcsv($output, ['Assets','Bank Balance', $bank_balance]);
    fputcsv($output, ['Assets','Cheque Return', $cheque_return]);
    fputcsv($output, ['Assets','Cheque in Hand', $cheque_in_hand]);
    fputcsv($output, ['Assets','Total Assets', $total_assets]);
    // Liabilities
    fputcsv($output, ['Liabilities','Accounts Payable', -$accounts_payable]);
    // Equity
    fputcsv($output, ['Equity','Opening Equity', $opening_equity]);
    fputcsv($output, ['Equity','Net Profit', $net_profit]);
    fputcsv($output, ['Equity','(-) Drawings', -$drawings]);
    fputcsv($output, ['Equity','Closing Equity', $closing_equity]);
} else {
    fputcsv($output, ['Error','Invalid section']);
}
fclose($output);
exit;