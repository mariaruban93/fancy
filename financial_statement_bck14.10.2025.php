<?php
/**
 * financial_statement.php — Supplier A/P nets cargo debits; now also includes stock adjustments.
 * - Supplier Payable = Purchases − CargoDebitsToSuppliers − Returns − Payments
 * - Cargo Payable    = Cargo Services − Cargo Payments
 * - Inventory value includes stock adjustments (± delta * cost).
 * - Drawings (items) include negative stock adjustments in the period.
 *
 * NOTE: To avoid double-counting, stock JEs that originate from adjustments
 *       (description contains "adjust", "adj ", "split", "repack") are excluded
 *       from inventory build-up and the reference "Opening Stock injections".
 */

require_once 'includes/header.php';
checkRole(['admin','manager']);

/* ------------------ Date range ------------------ */
$range      = $_GET['range']      ?? 'today';
$start_date = $_GET['start_date'] ?? '';
$end_date   = $_GET['end_date']   ?? '';

$today      = date('Y-m-d');
$monthStart = date('Y-m-01');
$yearStart  = date('Y-01-01');

switch ($range) {
  case 'month':  $start_date = $monthStart; $end_date = $today; break;
  case 'year':   $start_date = $yearStart;  $end_date = $today; break;
  case 'custom': break;
  case 'today':
  default: $range='today'; $start_date=$today; $end_date=$today; break;
}
if ($range==='custom' && (!$start_date || !$end_date)) { $start_date=$today; $end_date=$today; $range='today'; }
$start_dt = $start_date.' 00:00:00';
$end_dt   = $end_date.' 23:59:59';

/* ------------------ Branch scope ------------------ */
$is_admin           = (($user['role_name'] ?? '') === 'admin');
$incoming_branch_id = isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : 0;
$branch_id          = $is_admin ? $incoming_branch_id : (int)($user['branch_id'] ?? 0);

$andBranchSales = (!$is_admin || $branch_id) ? ' AND s.branch_id = :b' : '';
$andBranchOnly  = (!$is_admin || $branch_id) ? ' AND branch_id = :b' : '';
$paramB         = (!$is_admin || $branch_id) ? [':b'=>$branch_id] : [];

/* Branch list for dropdown */
$branches = [];
if ($is_admin) {
  try { $branches = $pdo->query("SELECT id,name FROM branches ORDER BY name")->fetchAll(PDO::FETCH_ASSOC); } catch(Throwable $e){}
}
$branch_map = [];
foreach ($branches as $br) $branch_map[(int)$br['id']] = $br['name'];
if (!$is_admin && !empty($user['branch_name'])) $branch_map[$branch_id] = $user['branch_name'];

/* ------------------ Helpers ------------------ */
function col_exists(PDO $pdo, $table, $col) {
  try { return (bool)$pdo->query("SHOW COLUMNS FROM `$table` LIKE ".$pdo->quote($col))->fetch(PDO::FETCH_ASSOC); }
  catch (Throwable $e) { return false; }
}
function table_exists(PDO $pdo, string $table): bool {
  try { $q=$pdo->query("SHOW TABLES LIKE ".$pdo->quote($table)); return (bool)($q && $q->fetchColumn()); }
  catch (Throwable $e) { return false; }
}
function first_available_col(PDO $pdo, string $table, array $candidates): ?string {
  foreach ($candidates as $c) {
    try { $q = $pdo->query("SHOW COLUMNS FROM `$table` LIKE ".$pdo->quote($c)); if ($q && $q->fetch(PDO::FETCH_ASSOC)) return $c; }
    catch (Throwable $e) {}
  }
  return null;
}

/* ------------------------------------------------------------------
   Branch Balance Helpers
   These functions compute cumulative cash and bank balances up to a
   specified cutoff datetime for a given branch.  They centralise the
   logic used across the system (daily cash drawer, financial reports,
   payment validations) so that cash/bank calculations remain
   consistent.  If branch_id is 0 for admin, balances are aggregated
   across all branches.
------------------------------------------------------------------ */
function compute_branch_cash_balance(PDO $pdo, string $cutoff_dt, int $branch_id, bool $is_admin): float {
  // Sum of cash openings up to cutoff
  $sum = 0.0;
  try {
    $co_date_col = first_available_col($pdo,'cash_openings',['open_date','opening_date','date','opened_on','created_at']);
    $co_amt_col  = first_available_col($pdo,'cash_openings',['opening_amount','amount','opening','opening_balance']);
    if ($co_amt_col) {
      $sql="SELECT COALESCE(SUM($co_amt_col),0) FROM cash_openings WHERE 1=1";
      $params = [];
      if ($co_date_col) { $sql .= " AND $co_date_col <= :to"; $params[':to'] = substr($cutoff_dt,0,10); }
      if (!$is_admin || $branch_id) { $sql .= " AND branch_id=:b"; $params[':b'] = $branch_id; }
      $st = $pdo->prepare($sql); $st->execute($params);
      $sum += (float)$st->fetchColumn();
    }
  } catch (Throwable $e) {}

  // Cash sale payments
  try {
    $sql="SELECT COALESCE(SUM(sp.amount),0) FROM sale_payments sp JOIN sales s ON sp.sale_id=s.id WHERE sp.method='cash' AND s.sale_date<=:to";
    $params=[":to"=>$cutoff_dt];
    if (!$is_admin || $branch_id) { $sql .= " AND s.branch_id=:b"; $params[':b'] = $branch_id; }
    $st=$pdo->prepare($sql); $st->execute($params);
    $sum += (float)$st->fetchColumn();
  } catch (Throwable $e) {}
  // Cash journal entries
  try {
    $sql="SELECT COALESCE(SUM(je.amount),0) FROM journal_entries je WHERE je.account_type='cash' AND je.entry_date<=:to";
    $params=[":to"=>substr($cutoff_dt,0,10)];
    if (!$is_admin || $branch_id) { $sql .= " AND je.branch_id=:b"; $params[':b'] = $branch_id; }
    $st=$pdo->prepare($sql); $st->execute($params);
    $sum += (float)$st->fetchColumn();
  } catch (Throwable $e) {}
  // Cash returns
  try {
    $has_return_type = col_exists($pdo,'sale_returns','return_type');
    $sql="SELECT COALESCE(SUM(sr.total_refund),0) FROM sale_returns sr JOIN sales s ON sr.sale_id=s.id WHERE sr.return_date<=:to";
    $params=[":to"=>$cutoff_dt];
    if (!$is_admin || $branch_id) { $sql .= " AND s.branch_id=:b"; $params[':b'] = $branch_id; }
    if ($has_return_type) $sql .= " AND sr.return_type='cash'";
    $st=$pdo->prepare($sql); $st->execute($params);
    $sum -= (float)$st->fetchColumn();
  } catch (Throwable $e) {}
  // Cash expenses
  try {
    $expenses_has_method = col_exists($pdo,'expenses','payment_method');
    if ($expenses_has_method) {
      $sql="SELECT COALESCE(SUM(e.amount),0) FROM expenses e WHERE e.expense_date<=:to AND e.payment_method='cash'";
    } else {
      $sql="SELECT COALESCE(SUM(e.amount),0) FROM expenses e WHERE e.expense_date<=:to";
    }
    $params=[":to"=>substr($cutoff_dt,0,10)];
    if (!$is_admin || $branch_id) { $sql .= " AND e.branch_id=:b"; $params[':b']=$branch_id; }
    $st=$pdo->prepare($sql); $st->execute($params);
    $sum -= (float)$st->fetchColumn();
  } catch (Throwable $e) {}
  // Purchase payments (cash)
  try {
    $pp_has_method = col_exists($pdo,'purchase_payments','method');
    $sql="SELECT COALESCE(SUM(pp.amount),0) FROM purchase_payments pp JOIN purchases p ON pp.purchase_id=p.id WHERE pp.paid_at<=:to";
    $params=[":to"=>$cutoff_dt];
    if ($pp_has_method) {
      $sql .= " AND (pp.method='cash' OR pp.method IS NULL)";
    }
    if (!$is_admin || $branch_id) { $sql .= " AND p.branch_id=:b"; $params[':b']=$branch_id; }
    $st=$pdo->prepare($sql); $st->execute($params);
    $sum -= (float)$st->fetchColumn();
  } catch (Throwable $e) {}
  // Worker payments (cash)
  try {
    $sql="SELECT COALESCE(SUM(wp.amount),0) FROM worker_payments wp JOIN workers w ON wp.worker_id=w.id WHERE wp.method='cash' AND wp.payment_date<=:to";
    $params=[":to"=>substr($cutoff_dt,0,10)];
    if (!$is_admin || $branch_id) { $sql .= " AND w.branch_id=:b"; $params[':b']=$branch_id; }
    $st=$pdo->prepare($sql); $st->execute($params);
    $sum -= (float)$st->fetchColumn();
  } catch (Throwable $e) {}
  // Cargo payments (cash)
  try {
    $cargo_has_method = col_exists($pdo,'cargo_payments','method');
    $sql="SELECT COALESCE(SUM(cp.amount),0) FROM cargo_payments cp WHERE cp.paid_at<=:to";
    $params=[":to"=>$cutoff_dt];
    if ($cargo_has_method) $sql .= " AND cp.method='cash'";
    if (!$is_admin || $branch_id) { $sql .= " AND cp.branch_id=:b"; $params[':b']=$branch_id; }
    $st=$pdo->prepare($sql); $st->execute($params);
    $sum -= (float)$st->fetchColumn();
  } catch (Throwable $e) {}
  return max(0.0, $sum);
}

function compute_branch_bank_balance(PDO $pdo, string $cutoff_dt, int $branch_id, bool $is_admin): float {
  // Starting with opening balances from bank_accounts
  $sum = 0.0;
  try {
    $sql="SELECT COALESCE(SUM(opening_balance),0) FROM bank_accounts";
    $params=[];
    if (!$is_admin || $branch_id) { $sql .= " WHERE branch_id=:b"; $params[':b'] = $branch_id; }
    $st=$pdo->prepare($sql); $st->execute($params);
    $sum += (float)$st->fetchColumn();
  } catch (Throwable $e) {}
  // Inflows: sale payments non-cash (card/upi/bank_transfer) and cheques when deposited
  try {
    $sql="SELECT COALESCE(SUM(sp.amount),0) FROM sale_payments sp JOIN sales s ON sp.sale_id=s.id WHERE sp.method<>'cash' AND (sp.method<>'cheque' OR sp.deposit_date IS NOT NULL) AND s.sale_date<=:to";
    $params=[":to"=>$cutoff_dt];
    if (!$is_admin || $branch_id) { $sql .= " AND s.branch_id=:b"; $params[':b']=$branch_id; }
    $st=$pdo->prepare($sql); $st->execute($params);
    $sum += (float)$st->fetchColumn();
  } catch (Throwable $e) {}
  // Outflows: worker payments (bank)
  try {
    $sql="SELECT COALESCE(SUM(wp.amount),0) FROM worker_payments wp JOIN workers w ON wp.worker_id=w.id WHERE wp.method='bank' AND wp.payment_date<=:to";
    $params=[":to"=>substr($cutoff_dt,0,10)];
    if (!$is_admin || $branch_id) { $sql .= " AND w.branch_id=:b"; $params[':b']=$branch_id; }
    $st=$pdo->prepare($sql); $st->execute($params);
    $sum -= (float)$st->fetchColumn();
  } catch (Throwable $e) {}
  // Outflows: purchase payments via bank/card/upi
  try {
    $pp_has_method = col_exists($pdo,'purchase_payments','method');
    if ($pp_has_method) {
      $sql="SELECT COALESCE(SUM(pp.amount),0) FROM purchase_payments pp JOIN purchases p ON pp.purchase_id=p.id WHERE pp.paid_at<=:to AND pp.method IN ('bank','card','upi')";
      $params=[":to"=>$cutoff_dt];
      if (!$is_admin || $branch_id) { $sql .= " AND p.branch_id=:b"; $params[':b']=$branch_id; }
      $st=$pdo->prepare($sql); $st->execute($params);
      $sum -= (float)$st->fetchColumn();
    }
  } catch (Throwable $e) {}
  // Outflows: cargo payments via bank/card/upi
  try {
    $cargo_has_method = col_exists($pdo,'cargo_payments','method');
    if ($cargo_has_method) {
      $sql="SELECT COALESCE(SUM(cp.amount),0) FROM cargo_payments cp WHERE cp.paid_at<=:to AND cp.method IN ('bank','card','upi')";
      $params=[":to"=>$cutoff_dt];
      if (!$is_admin || $branch_id) { $sql .= " AND cp.branch_id=:b"; $params[':b']=$branch_id; }
      $st=$pdo->prepare($sql); $st->execute($params);
      $sum -= (float)$st->fetchColumn();
    }
  } catch (Throwable $e) {}
  // Bank journals (account_type=bank)
  try {
    $sql="SELECT COALESCE(SUM(je.amount),0) FROM journal_entries je WHERE je.account_type='bank' AND je.entry_date<=:to";
    $params=[":to"=>substr($cutoff_dt,0,10)];
    if (!$is_admin || $branch_id) { $sql .= " AND je.branch_id=:b"; $params[':b']=$branch_id; }
    $st=$pdo->prepare($sql); $st->execute($params);
    $sum += (float)$st->fetchColumn();
  } catch (Throwable $e) {}
  // Cleared supplier cheques (reduce bank)
  try {
    $pp_has_method = col_exists($pdo,'purchase_payments','method');
    $pp_clear_col  = first_available_col($pdo,'purchase_payments',['deposit_date','cleared_on','clear_date','transferred_on']);
    if ($pp_has_method && $pp_clear_col) {
      $sql="SELECT COALESCE(SUM(pp.amount),0) FROM purchase_payments pp JOIN purchases p ON pp.purchase_id=p.id WHERE pp.paid_at<=:to AND LOWER(pp.method) IN ('cheque','chuque','chq','cheq','check','cq') AND $pp_clear_col IS NOT NULL AND $pp_clear_col<=:ct";
      $params=[":to"=>$cutoff_dt, ":ct"=>substr($cutoff_dt,0,10)];
      if (!$is_admin || $branch_id) { $sql .= " AND p.branch_id=:b"; $params[':b']=$branch_id; }
      $st=$pdo->prepare($sql); $st->execute($params);
      $sum -= (float)$st->fetchColumn();
    }
  } catch (Throwable $e) {}
  return max(0.0, $sum);
}

/* ---------- Detect stock adjustment tables/columns (header + lines) ---------- */
$adj_hdr_tbl   = null;  // stock_adjustments
$adj_line_tbl  = null;  // stock_adjustment_items OR stock_adjustment_lines
$adj_date_col  = null;  // adj_datetime / adjusted_at / created_at / date
$adj_fk_col    = null;  // adjustment_id / stock_adjustment_id
$adj_delta_col = null;  // delta_qty / qty_change / change_qty / delta / quantity_delta
$adj_cost_col  = null;  // cost_price / cost / unit_cost

if (table_exists($pdo,'stock_adjustments')) {
  $adj_hdr_tbl  = 'stock_adjustments';
  // ADD 'adj_datetime' first to match your ajax file header timestamp
  $adj_date_col = first_available_col($pdo,'stock_adjustments',['adj_datetime','adjusted_at','adjusted_on','created_at','date','posted_at']);
}
if (table_exists($pdo,'stock_adjustment_items')) {
  $adj_line_tbl  = 'stock_adjustment_items';
  $adj_fk_col    = first_available_col($pdo,'stock_adjustment_items',['adjustment_id','stock_adjustment_id']);
  $adj_delta_col = first_available_col($pdo,'stock_adjustment_items',['delta_qty','qty_change','change_qty','delta','quantity_delta']);
  $adj_cost_col  = first_available_col($pdo,'stock_adjustment_items',['cost_price','cost','unit_cost']);
}
if (!$adj_line_tbl && table_exists($pdo,'stock_adjustment_lines')) {
  $adj_line_tbl  = 'stock_adjustment_lines';
  $adj_fk_col    = first_available_col($pdo,'stock_adjustment_lines',['adjustment_id','stock_adjustment_id']);
  $adj_delta_col = first_available_col($pdo,'stock_adjustment_lines',['delta_qty','qty_change','change_qty','delta','quantity_delta']);
  $adj_cost_col  = first_available_col($pdo,'stock_adjustment_lines',['cost_price','cost','unit_cost']);
}

/* ------------------ Stock value as-of (NOW includes adjustments) ------------------ */
function stock_value_as_of(string $cutoff_dt, int $branch_id, PDO $pdo,
                           ?string $adj_hdr_tbl, ?string $adj_line_tbl,
                           ?string $adj_date_col, ?string $adj_fk_col,
                           ?string $adj_delta_col, ?string $adj_cost_col): float {
  $params = [':to'=>$cutoff_dt, ':b'=>$branch_id];
  $sum = 0.0;

  // Stock account journal entries (opening/legacy postings only)
  // Exclude adjustment-origin journals to avoid double count.
  $excludeAdj = "";
  if ($adj_hdr_tbl && $adj_line_tbl) {
    $excludeAdj = " AND (je.description IS NULL OR (
        LOWER(je.description) NOT LIKE '%adjust%'
    AND LOWER(je.description) NOT LIKE '%adj %'
    AND LOWER(je.description) NOT LIKE '%adj#%'
    AND LOWER(je.description) NOT LIKE '%adj #%'
    AND LOWER(je.description) NOT LIKE '%split%'
    AND LOWER(je.description) NOT LIKE '%repack%'))";
  }
  try {
    $st=$pdo->prepare("
      SELECT COALESCE(SUM(je.amount),0)
      FROM journal_entries je
      WHERE je.entry_date<=:to
        AND je.branch_id=:b
        AND je.account_type='stock' {$excludeAdj}
    ");
    $st->execute($params); $sum += (float)$st->fetchColumn();
  } catch(Throwable $e){}

  // Purchases in
  $st=$pdo->prepare("SELECT COALESCE(SUM(pi.quantity*pi.cost_price),0)
                     FROM purchase_items pi JOIN purchases p ON pi.purchase_id=p.id
                     WHERE p.purchase_date<=:to AND p.branch_id=:b");
  $st->execute($params); $sum += (float)$st->fetchColumn();

  // Transfers in
  $st=$pdo->prepare("SELECT COALESCE(SUM(sti.quantity*sb.cost_price),0)
                     FROM stock_transfer_items sti
                     JOIN stock_transfers st ON sti.transfer_id=st.id
                     JOIN stock_batches sb    ON sb.id=sti.batch_id
                     WHERE st.transferred_at<=:to AND st.to_branch_id=:b");
  $st->execute($params); $sum += (float)$st->fetchColumn();

  // Transfers out
  $st=$pdo->prepare("SELECT COALESCE(SUM(sti.quantity*sb.cost_price),0)
                     FROM stock_transfer_items sti
                     JOIN stock_transfers st ON sti.transfer_id=st.id
                     JOIN stock_batches sb    ON sb.id=sti.batch_id
                     WHERE st.transferred_at<=:to AND st.from_branch_id=:b");
  $st->execute($params); $sum -= (float)$st->fetchColumn();

  // Sales out (at cost)
  $st=$pdo->prepare("SELECT COALESCE(SUM(si.quantity*sb.cost_price),0)
                     FROM sale_items si
                     JOIN sales s ON si.sale_id=s.id
                     JOIN stock_batches sb ON sb.id=si.batch_id
                     WHERE s.sale_date<=:to AND s.branch_id=:b");
  $st->execute($params); $sum -= (float)$st->fetchColumn();

  // Sale returns in (at cost)
  $st=$pdo->prepare("SELECT COALESCE(SUM(sri.quantity*sb.cost_price),0)
                     FROM sale_return_items sri
                     JOIN sale_returns sr ON sri.sale_return_id=sr.id
                     JOIN sales s ON sr.sale_id=s.id
                     JOIN stock_batches sb ON sb.id=sri.batch_id
                     WHERE sr.return_date<=:to AND s.branch_id=:b");
  $st->execute($params); $sum += (float)$st->fetchColumn();

  // Purchase returns out (monetary)
  $st=$pdo->prepare("SELECT COALESCE(SUM(pr.total_refund),0)
                     FROM purchase_returns pr
                     JOIN purchases p ON pr.purchase_id=p.id
                     WHERE pr.return_date<=:to AND p.branch_id=:b");
  $st->execute($params); $sum -= (float)$st->fetchColumn();

  // Write-offs out (cost)
  $st=$pdo->prepare("SELECT COALESCE(SUM(wo.quantity*wo.cost_price),0)
                     FROM write_offs wo
                     WHERE wo.write_off_date<=:to AND wo.branch_id=:b");
  $st->execute($params); $sum -= (float)$st->fetchColumn();

  // Stock adjustments (if tables/cols exist) — do this ONCE here
  if ($adj_hdr_tbl && $adj_line_tbl && $adj_date_col && $adj_fk_col && $adj_delta_col && $adj_cost_col) {
    $sql = "
      SELECT
        COALESCE(SUM(CASE WHEN li.`$adj_delta_col` > 0 THEN li.`$adj_delta_col` * li.`$adj_cost_col` ELSE 0 END),0)  AS adj_in,
        COALESCE(SUM(CASE WHEN li.`$adj_delta_col` < 0 THEN -li.`$adj_delta_col` * li.`$adj_cost_col` ELSE 0 END),0) AS adj_out
      FROM `$adj_line_tbl` li
      JOIN `$adj_hdr_tbl`  hd ON hd.id = li.`$adj_fk_col`
      WHERE hd.`$adj_date_col` <= :to AND hd.branch_id = :b
    ";
    try {
      $st = $pdo->prepare($sql);
      $st->execute($params);
      $row = $st->fetch(PDO::FETCH_ASSOC) ?: ['adj_in'=>0, 'adj_out'=>0];
      $sum += (float)$row['adj_in'];
      $sum -= (float)$row['adj_out'];
    } catch (Throwable $e) {}
  }

  return max($sum,0.0);
}

/* ------------------ Sales & COGS (period) ------------------ */
$st=$pdo->prepare("SELECT COALESCE(SUM(s.total_amount),0)
                   FROM sales s WHERE s.sale_date BETWEEN :f AND :t".$andBranchSales);
$st->execute(array_merge([':f'=>$start_dt,':t'=>$end_dt],$paramB));
$sales_gross=(float)$st->fetchColumn();

$st=$pdo->prepare("SELECT COALESCE(SUM(sr.total_refund),0)
                   FROM sale_returns sr JOIN sales s ON sr.sale_id=s.id
                   WHERE sr.return_date BETWEEN :f AND :t".$andBranchSales);
$st->execute(array_merge([':f'=>$start_dt,':t'=>$end_dt],$paramB));
$sales_return_amt=(float)$st->fetchColumn();

$net_sales_before_discount=max($sales_gross-$sales_return_amt,0.0);

$st=$pdo->prepare("SELECT COALESCE(SUM(si.quantity*sb.cost_price),0)
                   FROM sale_items si
                   JOIN sales s ON si.sale_id=s.id
                   JOIN stock_batches sb ON sb.id=si.batch_id
                   WHERE s.sale_date BETWEEN :f AND :t".$andBranchSales);
$st->execute(array_merge([':f'=>$start_dt,':t'=>$end_dt],$paramB));
$cogs_sales=(float)$st->fetchColumn();

$st=$pdo->prepare("SELECT COALESCE(SUM(sri.quantity*sb.cost_price),0)
                   FROM sale_return_items sri
                   JOIN sale_returns sr ON sri.sale_return_id=sr.id
                   JOIN sales s ON sr.sale_id=s.id
                   JOIN stock_batches sb ON sb.id=sri.batch_id
                   WHERE sr.return_date BETWEEN :f AND :t".$andBranchSales);
$st->execute(array_merge([':f'=>$start_dt,':t'=>$end_dt],$paramB));
$cogs_returns=(float)$st->fetchColumn();

$cost_of_sales=max($cogs_sales-$cogs_returns,0.0);

/* Opening & Closing stock values via movements (now includes adjustments) */
if ($is_admin && !$branch_id) {
  $opening_stock_value=0.0; $closing_stock_value=0.0;
  try {
    $ids=$pdo->query("SELECT id FROM branches")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $bid) {
      $opening_stock_value+=stock_value_as_of($start_dt,(int)$bid,$pdo,$adj_hdr_tbl,$adj_line_tbl,$adj_date_col,$adj_fk_col,$adj_delta_col,$adj_cost_col);
      $closing_stock_value+=stock_value_as_of($end_dt,(int)$bid,$pdo,$adj_hdr_tbl,$adj_line_tbl,$adj_date_col,$adj_fk_col,$adj_delta_col,$adj_cost_col);
    }
  } catch(Throwable $e){}
} else {
  $opening_stock_value=stock_value_as_of($start_dt,$branch_id,$pdo,$adj_hdr_tbl,$adj_line_tbl,$adj_date_col,$adj_fk_col,$adj_delta_col,$adj_cost_col);
  $closing_stock_value=stock_value_as_of($end_dt,$branch_id,$pdo,$adj_hdr_tbl,$adj_line_tbl,$adj_date_col,$adj_fk_col,$adj_delta_col,$adj_cost_col);
}

/* Purchases & transfers (period) for reference display */
$st=$pdo->prepare("SELECT COALESCE(SUM(pi.quantity*pi.cost_price),0)
                   FROM purchase_items pi JOIN purchases p ON pi.purchase_id=p.id
                   WHERE p.purchase_date BETWEEN :f AND :t".((!$is_admin||$branch_id)?' AND p.branch_id=:b':'')); 
$st->execute(array_merge([':f'=>$start_dt,':t'=>$end_dt],$paramB));
$purchase_period_val=(float)$st->fetchColumn();

$st=$pdo->prepare("SELECT COALESCE(SUM(sti.quantity*sb.cost_price),0)
                   FROM stock_transfer_items sti
                   JOIN stock_transfers st ON sti.transfer_id=st.id
                   JOIN stock_batches sb    ON sb.id=sti.batch_id
                   WHERE st.transferred_at BETWEEN :f AND :t".((!$is_admin||$branch_id)?' AND st.to_branch_id=:b':'')); 
$st->execute(array_merge([':f'=>$start_dt,':t'=>$end_dt],$paramB));
$transfer_in_period_val=(float)$st->fetchColumn();

/* Opening injections (reference) — exclude adjustment-origin stock JEs */
$opening_in_period_val=0.0;
try{
  $excludeAdj = " AND (je.description IS NULL OR (
      LOWER(je.description) NOT LIKE '%adjust%'
  AND LOWER(je.description) NOT LIKE '%adj %'
  AND LOWER(je.description) NOT LIKE '%adj#%'
  AND LOWER(je.description) NOT LIKE '%adj #%'
  AND LOWER(je.description) NOT LIKE '%split%'
  AND LOWER(je.description) NOT LIKE '%repack%'))";

  $st=$pdo->prepare("
    SELECT COALESCE(SUM(je.amount),0)
    FROM journal_entries je
    WHERE je.entry_date BETWEEN :f AND :t
      AND je.account_type='stock'".$andBranchOnly.$excludeAdj);
  $st->execute(array_merge([':f'=>$start_date,':t'=>$end_date],$paramB));
  $opening_in_period_val=(float)$st->fetchColumn();
}catch(Throwable $e){}
$period_purchases_display=$purchase_period_val+$transfer_in_period_val;

/* ------------------ Expenses (period) ------------------ */
$st=$pdo->prepare("SELECT COALESCE(SUM(e.amount),0)
                   FROM expenses e
                   WHERE e.expense_date BETWEEN :f AND :t".$andBranchOnly);
$st->execute(array_merge([':f'=>$start_date,':t'=>$end_date],$paramB));
$expenses_total=(float)$st->fetchColumn();

/* ------------------ Net/Gross Profit ------------------ */
$gross_profit=$net_sales_before_discount-$cost_of_sales;
if ($gross_profit<0) $gross_profit=0.0;

$st=$pdo->prepare("SELECT COALESCE(SUM(je.amount),0)
                   FROM journal_entries je
                   WHERE je.account_type='income' AND je.entry_date BETWEEN :f AND :t".$andBranchOnly);
$st->execute(array_merge([':f'=>$start_date,':t'=>$end_date],$paramB));
$other_income=(float)$st->fetchColumn();

$net_profit=$gross_profit+$other_income-$expenses_total;

/* ------------------ Debtors & Intercompany ------------------ */
$st=$pdo->prepare("SELECT COALESCE(SUM(s.total_amount),0)
                   FROM sales s WHERE s.sale_date<=:t".$andBranchSales);
$st->execute(array_merge([':t'=>$end_dt],$paramB));
$sales_to_end=(float)$st->fetchColumn();

$st=$pdo->prepare("SELECT COALESCE(SUM(sr.total_refund),0)
                   FROM sale_returns sr JOIN sales s ON sr.sale_id=s.id
                   WHERE sr.return_date<=:t".$andBranchSales);
$st->execute(array_merge([':t'=>$end_dt],$paramB));
$sales_returns_to_end=(float)$st->fetchColumn();

$st=$pdo->prepare("SELECT COALESCE(SUM(sp.amount),0)
                   FROM sale_payments sp JOIN sales s ON sp.sale_id=s.id
                   WHERE s.sale_date<=:t".$andBranchSales);
$st->execute(array_merge([':t'=>$end_dt],$paramB));
$sale_payments_to_end=(float)$st->fetchColumn();

$customer_debtors=max($sales_to_end-$sales_returns_to_end-$sale_payments_to_end,0.0);

/* --------- Branch Debtors / Payable (from transfers) --------- */
$branch_debtors = 0.0;
$branch_payable = 0.0;
try {
  if ($is_admin && !$branch_id) {
    $branch_debtors = 0.0; $branch_payable = 0.0;
  } else {
    $recvVal = [];
    $stmt = $pdo->prepare("
      SELECT st.to_branch_id AS counter_id, COALESCE(SUM(sti.quantity*sb.cost_price),0) AS amount
      FROM stock_transfer_items sti
      JOIN stock_transfers st ON st.id = sti.transfer_id
      JOIN stock_batches sb   ON sb.id = sti.batch_id
      WHERE st.transferred_at <= :t AND st.from_branch_id = :b
      GROUP BY st.to_branch_id
    ");
    $stmt->execute([':t'=>$end_dt, ':b'=>$branch_id]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $recvVal[(int)$r['counter_id']] = (float)$r['amount'];

    $recvPaid = [];
    $stmt = $pdo->prepare("
      SELECT st.to_branch_id AS counter_id, COALESCE(SUM(tp.amount),0) AS amount
      FROM transfer_payments tp
      JOIN stock_transfers st ON st.id = tp.transfer_id
      WHERE tp.paid_at <= :t AND st.from_branch_id = :b
      GROUP BY st.to_branch_id
    ");
    $stmt->execute([':t'=>$end_dt, ':b'=>$branch_id]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $recvPaid[(int)$r['counter_id']] = (float)$r['amount'];

    $payVal = [];
    $stmt = $pdo->prepare("
      SELECT st.from_branch_id AS counter_id, COALESCE(SUM(sti.quantity*sb.cost_price),0) AS amount
      FROM stock_transfer_items sti
      JOIN stock_transfers st ON st.id = sti.transfer_id
      JOIN stock_batches sb   ON sb.id = sti.batch_id
      WHERE st.transferred_at <= :t AND st.to_branch_id = :b
      GROUP BY st.from_branch_id
    ");
    $stmt->execute([':t'=>$end_dt, ':b'=>$branch_id]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $payVal[(int)$r['counter_id']] = (float)$r['amount'];

    $payPaid = [];
    $stmt = $pdo->prepare("
      SELECT st.from_branch_id AS counter_id, COALESCE(SUM(tp.amount),0) AS amount
      FROM transfer_payments tp
      JOIN stock_transfers st ON st.id = tp.transfer_id
      WHERE tp.paid_at <= :t AND st.to_branch_id = :b
      GROUP BY st.from_branch_id
    ");
    $stmt->execute([':t'=>$end_dt, ':b'=>$branch_id]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $payPaid[(int)$r['counter_id']] = (float)$r['amount'];

    $allCounterIds = array_unique(array_merge(array_keys($recvVal), array_keys($recvPaid), array_keys($payVal), array_keys($payPaid)));
    foreach ($allCounterIds as $cid) {
      $rcv  = ($recvVal[$cid]  ?? 0.0) - ($recvPaid[$cid] ?? 0.0);
      $pay  = ($payVal[$cid]   ?? 0.0) - ($payPaid[$cid]  ?? 0.0);
      $net  = $rcv - $pay;
      if ($net > 0)  $branch_debtors += $net;
      if ($net < 0)  $branch_payable += -$net;
    }
  }
} catch (Throwable $e) {
  $branch_debtors = 0.0; $branch_payable = 0.0;
}

/* ------------------ CASH (cumulative) ------------------ */
$opening_sum=0.0;
if (table_exists($pdo,'cash_openings')) {
  $co_date_col = first_available_col($pdo,'cash_openings',['open_date','opening_date','date','opened_on','created_at']);
  $co_amt_col  = first_available_col($pdo,'cash_openings',['opening_amount','amount','opening','opening_balance']);
  if ($co_amt_col) {
    $sql="SELECT COALESCE(SUM($co_amt_col),0) FROM cash_openings WHERE 1=1";
    $params=[];
    if ($co_date_col) { $sql.=" AND $co_date_col<=:to"; $params[':to']=$end_date; }
    if (!$is_admin || $branch_id) { $sql.=" AND branch_id=:b"; $params[':b']=$branch_id; }
    try { $st=$pdo->prepare($sql); $st->execute($params); $opening_sum=(float)$st->fetchColumn(); } catch(Throwable $e){}
  }
}

$st=$pdo->prepare("SELECT COALESCE(SUM(sp.amount),0)
                   FROM sale_payments sp JOIN sales s ON sp.sale_id=s.id
                   WHERE sp.method='cash' AND s.sale_date<=:t".$andBranchSales);
$st->execute(array_merge([':t'=>$end_dt],$paramB));
$cash_sales_to_end=(float)$st->fetchColumn();

$st=$pdo->prepare("SELECT COALESCE(SUM(je.amount),0)
                   FROM journal_entries je
                   WHERE je.account_type='cash' AND je.entry_date<=:t".$andBranchOnly);
$st->execute(array_merge([':t'=>$end_date],$paramB));
$cash_journal_to_end=(float)$st->fetchColumn();

// Cash sale returns only (respect return_type if present)
$has_return_type = col_exists($pdo,'sale_returns','return_type');
$sql = "SELECT COALESCE(SUM(sr.total_refund),0)
        FROM sale_returns sr
        JOIN sales s ON sr.sale_id = s.id
        WHERE sr.return_date <= :t".$andBranchSales;
if ($has_return_type) $sql .= " AND sr.return_type = 'cash'";
$st = $pdo->prepare($sql);
$st->execute(array_merge([':t' => $end_dt], $paramB));
$cash_returns_to_end = (float)$st->fetchColumn();

$expenses_has_method = col_exists($pdo,'expenses','payment_method');
$sqlExp = "SELECT COALESCE(SUM(e.amount),0) FROM expenses e WHERE e.expense_date<=:t".$andBranchOnly;
if ($expenses_has_method) $sqlExp = "SELECT COALESCE(SUM(e.amount),0) FROM expenses e WHERE e.expense_date<=:t AND e.payment_method='cash'".$andBranchOnly;
$st=$pdo->prepare($sqlExp);
$st->execute(array_merge([':t'=>$end_date],$paramB));
$cash_expenses_to_end=(float)$st->fetchColumn();

$st=$pdo->prepare("SELECT COALESCE(SUM(pp.amount),0)
                   FROM purchase_payments pp JOIN purchases p ON pp.purchase_id=p.id
                   WHERE pp.paid_at<=:t AND (pp.method='cash' OR pp.method IS NULL)".((!$is_admin||$branch_id)?' AND p.branch_id=:b':'')); 
$st->execute(array_merge([':t'=>$end_dt],$paramB));
$cash_purchase_to_end=(float)$st->fetchColumn();

$st=$pdo->prepare("SELECT COALESCE(SUM(wp.amount),0)
                   FROM worker_payments wp JOIN workers w ON wp.worker_id=w.id
                   WHERE wp.method='cash' AND wp.payment_date<=:t".((!$is_admin||$branch_id)?' AND w.branch_id=:b':'')); 
$st->execute(array_merge([':t'=>$end_date],$paramB));
$worker_cash_to_end=(float)$st->fetchColumn();

$cargo_has_method   = col_exists($pdo,'cargo_payments','method');
if ($cargo_has_method) {
  $sql = "SELECT COALESCE(SUM(cp.amount),0)
          FROM cargo_payments cp
          WHERE cp.paid_at <= :t AND cp.method = 'cash'";
  $params = [':t' => $end_dt];
  if (!$is_admin || $branch_id) { $sql .= " AND cp.branch_id = :b"; $params[':b'] = $branch_id; }
  $st = $pdo->prepare($sql);
  $st->execute($params);
  $cargo_cash_to_end = (float)$st->fetchColumn();
} else {
  $sql = "SELECT COALESCE(SUM(cp.amount),0)
          FROM cargo_payments cp
          WHERE cp.paid_at <= :t";
  $params = [':t' => $end_dt];
  if (!$is_admin || $branch_id) { $sql .= " AND cp.branch_id = :b"; $params[':b'] = $branch_id; }
  $st = $pdo->prepare($sql);
  $st->execute($params);
  $cargo_cash_to_end = (float)$st->fetchColumn();
}

// Compute final cash in hand using unified helper (overrides interim sums).  This ensures
// that cash balances match across financial reports and other parts of the system.
$cash_in_hand = compute_branch_cash_balance($pdo, $end_dt, $branch_id, $is_admin);

/* ------------------ BANK (cumulative) ------------------ */
$st=$pdo->prepare("SELECT COALESCE(SUM(opening_balance),0)
                   FROM bank_accounts".((!$is_admin||$branch_id)?' WHERE branch_id=:b':'')); 
$st->execute($paramB);
// Reset bank_balance from opening; we will override later with unified helper
$bank_balance = (float)$st->fetchColumn();

/* Inflows from sales to bank (non-cash; cheques once deposited) */
$st=$pdo->prepare("SELECT COALESCE(SUM(sp.amount),0)
                   FROM sale_payments sp JOIN sales s ON sp.sale_id=s.id
                   WHERE sp.method<>'cash' AND (sp.method<>'cheque' OR sp.deposit_date IS NOT NULL)
                     AND s.sale_date<=:t".$andBranchSales);
$st->execute(array_merge([':t'=>$end_dt],$paramB));
// Bank balance will be recomputed using helper below
$bank_balance += (float)$st->fetchColumn();

/* Outflows via bank */
$st=$pdo->prepare("SELECT COALESCE(SUM(wp.amount),0)
                   FROM worker_payments wp JOIN workers w ON wp.worker_id=w.id
                   WHERE wp.method='bank' AND wp.payment_date<=:t".((!$is_admin||$branch_id)?' AND w.branch_id=:b':'')); 
$st->execute(array_merge([':t'=>$end_date],$paramB));
// Bank balance will be recomputed using helper below
$bank_balance -= (float)$st->fetchColumn();

$pp_has_method = col_exists($pdo,'purchase_payments','method');
if ($pp_has_method) {
  $st=$pdo->prepare("SELECT COALESCE(SUM(pp.amount),0)
                     FROM purchase_payments pp JOIN purchases p ON pp.purchase_id=p.id
                     WHERE pp.paid_at<=:t AND pp.method IN ('bank','card','upi')".((!$is_admin||$branch_id)?' AND p.branch_id=:b':'')); 
  $st->execute(array_merge([':t'=>$end_dt],$paramB));
// Bank balance will be recomputed using helper below
$bank_balance -= (float)$st->fetchColumn();
}

if ($cargo_has_method) {
  $sql="SELECT COALESCE(SUM(cp.amount),0) FROM cargo_payments cp
        WHERE cp.paid_at<=:t AND cp.method IN ('bank','card','upi')";
  if (!$is_admin || $branch_id) $sql.=" AND cp.branch_id=:b";
  $st=$pdo->prepare($sql); $st->execute(array_merge([':t'=>$end_dt],$paramB));
// Bank balance will be recomputed using helper below
$bank_balance -= (float)$st->fetchColumn();
}

/* Bank journals (include all bank-side movements, so Cash→Bank increases bank) */
$je_sql = "SELECT COALESCE(SUM(je.amount),0)
           FROM journal_entries je
           WHERE je.account_type='bank'
             AND je.entry_date <= :t".$andBranchOnly;

$st=$pdo->prepare($je_sql);
$st->execute(array_merge([':t'=>$end_date],$paramB));
// Bank balance will be recomputed using helper below
$bank_balance += (float)$st->fetchColumn();

/* Cleared supplier cheques reduce bank once they clear — only once */
$pp_clear_col = first_available_col($pdo,'purchase_payments',['deposit_date','cleared_on','clear_date','transferred_on']);
if ($pp_has_method && $pp_clear_col) {
  $sql = "SELECT COALESCE(SUM(pp.amount),0)
          FROM purchase_payments pp
          JOIN purchases p ON pp.purchase_id=p.id
          WHERE pp.paid_at<=:tp
            AND LOWER(pp.method) IN ('cheque','chuque','chq','cheq','check','cq')
            AND $pp_clear_col IS NOT NULL
            AND $pp_clear_col <= :tc";
  $params = [':tp'=>$end_dt, ':tc'=>$end_dt];
  if (!$is_admin || $branch_id) { $sql .= " AND p.branch_id=:b"; $params[':b']=$branch_id; }
  $st=$pdo->prepare($sql);
  $st->execute($params);
  // Bank balance will be recomputed using helper below
  $bank_balance -= (float)$st->fetchColumn();
}

// After processing all bank-related flows above, override bank_balance using unified helper.
$bank_balance = compute_branch_bank_balance($pdo, $end_dt, $branch_id, $is_admin);

/* ------------------ Wallet & Cheques in hand ------------------ */
$wallet_balance = 0.0;
if (table_exists($pdo, 'wallets')) {
  $wl_open_col = first_available_col($pdo, 'wallets', ['opening_balance','opening','opening_amount','openingbal']);
  if ($wl_open_col) {
    $sqlW = "SELECT COALESCE(SUM($wl_open_col),0) FROM wallets"
          .((!$is_admin || $branch_id) ? ' WHERE branch_id=:b' : '');
    $st = $pdo->prepare($sqlW);
    $st->execute($paramB);
    $wallet_balance += (float)$st->fetchColumn();
  }
}
$st = $pdo->prepare("SELECT COALESCE(SUM(je.amount),0)
                     FROM journal_entries je
                     WHERE je.account_type='wallet' AND je.entry_date<=:t".$andBranchOnly);
$st->execute(array_merge([':t'=>$end_date], $paramB));
$wallet_balance += (float)$st->fetchColumn();

$st = $pdo->prepare("SELECT COALESCE(SUM(sp.amount),0)
                     FROM sale_payments sp
                     JOIN sales s ON sp.sale_id = s.id
                     WHERE sp.method='wallet' AND s.sale_date<=:t".$andBranchSales);
$st->execute(array_merge([':t'=>$end_dt], $paramB));
$wallet_balance += (float)$st->fetchColumn();

$expenses_has_method = col_exists($pdo,'expenses','payment_method');
if ($expenses_has_method) {
  $st = $pdo->prepare("SELECT COALESCE(SUM(e.amount),0)
                       FROM expenses e
                       WHERE e.expense_date<=:t AND e.payment_method='wallet'".$andBranchOnly);
  $st->execute(array_merge([':t'=>$end_date], $paramB));
  $wallet_balance -= (float)$st->fetchColumn();
}
if ($pp_has_method) {
  $st = $pdo->prepare("SELECT COALESCE(SUM(pp.amount),0)
                       FROM purchase_payments pp
                       JOIN purchases p ON pp.purchase_id=p.id
                       WHERE pp.paid_at<=:t AND pp.method='wallet'"
                       .((!$is_admin || $branch_id) ? ' AND p.branch_id=:b' : ''));
  $st->execute(array_merge([':t'=>$end_dt], $paramB));
  $wallet_balance -= (float)$st->fetchColumn();
}
$st = $pdo->prepare("SELECT COALESCE(SUM(wp.amount),0)
                     FROM worker_payments wp
                     JOIN workers w ON wp.worker_id=w.id
                     WHERE wp.method='wallet' AND wp.payment_date<=:t"
                     .((!$is_admin || $branch_id) ? ' AND w.branch_id=:b' : ''));
$st->execute(array_merge([':t'=>$end_date], $paramB));
$wallet_balance -= (float)$st->fetchColumn();

if ($cargo_has_method) {
  $sql = "SELECT COALESCE(SUM(cp.amount),0) FROM cargo_payments cp
          WHERE cp.paid_at<=:t AND cp.method='wallet'";
  if (!$is_admin || $branch_id) $sql .= " AND cp.branch_id=:b";
  $st = $pdo->prepare($sql);
  $st->execute(array_merge([':t'=>$end_dt], $paramB));
  $wallet_balance -= (float)$st->fetchColumn();
}

$st = $pdo->prepare("SELECT COALESCE(SUM(sp.amount),0)
                     FROM sale_payments sp JOIN sales s ON sp.sale_id=s.id
                     WHERE sp.method='cheque' AND sp.deposit_date IS NULL
                       AND s.sale_date<=:t".$andBranchSales);
$st->execute(array_merge([':t'=>$end_dt], $paramB));
$cheque_in_hand = (float)$st->fetchColumn();
$cheque_return  = 0.0;

/* ------------------ Supplier & Cargo Payables ------------------ */
$has_opening_flag_1 = col_exists($pdo,'purchases','is_opening_stock');
$has_opening_flag_2 = col_exists($pdo,'purchases','is_opening');
$cs_has_supplier_id = col_exists($pdo,'cargo_services','supplier_id');
$tp_has_method      = col_exists($pdo,'transfer_payments','method');

$wherePurch  = " p.purchase_date<=:t ";
$paramsPurch = [':t'=>$end_dt];
if (!$is_admin || $branch_id) { $wherePurch.=" AND p.branch_id=:b "; $paramsPurch[':b']=$branch_id; }
if ($has_opening_flag_1)     { $wherePurch.=" AND COALESCE(p.is_opening_stock,0)=0 "; }
if ($has_opening_flag_2)     { $wherePurch.=" AND COALESCE(p.is_opening,0)=0 "; }

$st=$pdo->prepare("SELECT COALESCE(SUM(pi.quantity*pi.cost_price),0)
                   FROM purchase_items pi JOIN purchases p ON pi.purchase_id=p.id
                   WHERE $wherePurch");
$st->execute($paramsPurch);
$purch_invoices_to_end=(float)$st->fetchColumn();

$supplier_cargo_debits = 0.0;
if ($cs_has_supplier_id && table_exists($pdo,'cargo_services')) {
  $sql = "SELECT COALESCE(SUM(cs.amount),0)
          FROM cargo_services cs
          WHERE cs.transfer_date<=:t AND cs.supplier_id IS NOT NULL";
  $params = [':t'=>$end_dt];
  if (!$is_admin || $branch_id) { $sql .= " AND cs.branch_id=:b"; $params[':b']=$branch_id; }
  try { $st=$pdo->prepare($sql); $st->execute($params); $supplier_cargo_debits=(float)$st->fetchColumn(); } catch(Throwable $e){}
}

$sql_ret="SELECT COALESCE(SUM(pr.total_refund),0)
          FROM purchase_returns pr JOIN purchases p ON pr.purchase_id=p.id
          WHERE pr.return_date<=:t";
$paramsRet=[':t'=>$end_dt];
if (!$is_admin || $branch_id) { $sql_ret.=" AND p.branch_id=:b"; $paramsRet[':b']=$branch_id; }
if ($has_opening_flag_1)     { $sql_ret.=" AND COALESCE(p.is_opening_stock,0)=0 "; }
if ($has_opening_flag_2)     { $sql_ret.=" AND COALESCE(p.is_opening,0)=0 "; }
$st=$pdo->prepare($sql_ret); $st->execute($paramsRet);
$pur_returns_to_end=(float)$st->fetchColumn();

$sql_pay="SELECT COALESCE(SUM(pp.amount),0)
          FROM purchase_payments pp JOIN purchases p ON pp.purchase_id=p.id
          WHERE pp.paid_at<=:t";
$paramsPay=[':t'=>$end_dt];
if (!$is_admin || $branch_id) { $sql_pay.=" AND p.branch_id=:b"; $paramsPay[':b']=$branch_id; }
if ($has_opening_flag_1)     { $sql_pay.=" AND COALESCE(p.is_opening_stock,0)=0 "; }
if ($has_opening_flag_2)     { $sql_pay.=" AND COALESCE(p.is_opening,0)=0 "; }
$st=$pdo->prepare($sql_pay); $st->execute($paramsPay);
$pur_payments_to_end=(float)$st->fetchColumn();

$supplier_payable=max($purch_invoices_to_end - $supplier_cargo_debits - $pur_returns_to_end - $pur_payments_to_end, 0.0);

$cheque_payable = 0.0;
$pp_clear_col = first_available_col($pdo,'purchase_payments',['deposit_date','cleared_on','clear_date','transferred_on']);
$sql = "SELECT COALESCE(SUM(pp.amount),0)
        FROM purchase_payments pp
        JOIN purchases p ON pp.purchase_id=p.id
        WHERE pp.paid_at<=:tp
          AND LOWER(pp.method) IN ('cheque','chuque','chq','cheq','check','cq')";
$params = [':tp'=>$end_dt];
if ($pp_clear_col) { $sql .= " AND ($pp_clear_col IS NULL OR $pp_clear_col > :tc)"; $params[':tc']=$end_dt; }
if (!$is_admin || $branch_id) { $sql .= " AND p.branch_id=:b"; $params[':b']=$branch_id; }
$st=$pdo->prepare($sql); $st->execute($params);
$cheque_payable=(float)$st->fetchColumn();

$cargo_services_to_end = 0.0;
$cargo_payments_to_end = 0.0;
try {
  $sql = "SELECT COALESCE(SUM(cs.amount),0)
          FROM cargo_services cs
          WHERE cs.transfer_date <= :t";
  $params = [':t'=>$end_dt];
  if (!$is_admin || $branch_id) { $sql.=" AND cs.branch_id=:b"; $params[':b']=$branch_id; }
  $st=$pdo->prepare($sql); $st->execute($params);
  $cargo_services_to_end=(float)$st->fetchColumn();

  $sql = "SELECT COALESCE(SUM(cp.amount),0)
          FROM cargo_payments cp
          WHERE cp.paid_at <= :t";
  $params = [':t'=>$end_dt];
  if (!$is_admin || $branch_id) { $sql.=" AND cp.branch_id=:b"; $params[':b']=$branch_id; }
  $st=$pdo->prepare($sql); $st->execute($params);
  $cargo_payments_to_end=(float)$st->fetchColumn();
} catch(Throwable $e){}

$cargo_payable = max($cargo_services_to_end - $cargo_payments_to_end, 0.0);

/* ------------------ Totals & Equity ------------------ */
$total_assets     = $closing_stock_value + $customer_debtors + $branch_debtors + $cash_in_hand + $bank_balance + $wallet_balance + $cheque_return + $cheque_in_hand;
$accounts_payable = $supplier_payable + $cheque_payable + $cargo_payable + $branch_payable;

/* Drawings = Items (write_offs + negative adjustments) + Cash/Bank/Wallet */
$st=$pdo->prepare("SELECT COALESCE(SUM(wo.quantity*wo.cost_price),0)
                   FROM write_offs wo
                   WHERE wo.write_off_date BETWEEN :f AND :t".$andBranchOnly);
$st->execute(array_merge([':f'=>$start_dt,':t'=>$end_dt],$paramB));
$draw_items=(float)$st->fetchColumn();

/* Add negative stock adjustments of the period into drawings (items) */
$draw_items_adj = 0.0;
if ($adj_hdr_tbl && $adj_line_tbl && $adj_date_col && $adj_fk_col && $adj_delta_col && $adj_cost_col) {
  $sql = "
    SELECT COALESCE(SUM(-li.`$adj_delta_col` * li.`$adj_cost_col`),0) AS neg_val
    FROM `$adj_line_tbl` li
    JOIN `$adj_hdr_tbl`  hd ON hd.id = li.`$adj_fk_col`
    WHERE hd.`$adj_date_col` BETWEEN :f AND :t
      AND hd.branch_id ".(($is_admin && !$branch_id)?'>= 0':'= :b')."
      AND li.`$adj_delta_col` < 0
  ";
  $params = [':f'=>$start_dt, ':t'=>$end_dt];
  if (!($is_admin && !$branch_id)) $params[':b']=$branch_id;
  try { $st=$pdo->prepare($sql); $st->execute($params); $draw_items_adj=(float)$st->fetchColumn(); } catch(Throwable $e){}
}
$draw_items += $draw_items_adj;

$draw_cash = $draw_cash_cash = $draw_cash_bank = $draw_cash_wallet = 0.0;
if (table_exists($pdo,'cash_draws')) {
  $sql="SELECT
          COALESCE(SUM(CASE WHEN cd.source_type='cash'   THEN cd.amount ELSE 0 END),0) AS cash_amt,
          COALESCE(SUM(CASE WHEN cd.source_type='bank'   THEN cd.amount ELSE 0 END),0) AS bank_amt,
          COALESCE(SUM(CASE WHEN cd.source_type='wallet' THEN cd.amount ELSE 0 END),0) AS wallet_amt
        FROM cash_draws cd
        WHERE cd.draw_date BETWEEN :f AND :t";
  $params=[':f'=>$start_date, ':t'=>$end_date];
  if (!$is_admin || $branch_id) { $sql.=" AND cd.branch_id=:b"; $params[':b']=$branch_id; }
  $st=$pdo->prepare($sql); $st->execute($params);
  $row = $st->fetch(PDO::FETCH_ASSOC) ?: ['cash_amt'=>0,'bank_amt'=>0,'wallet_amt'=>0];
  $draw_cash_cash   = (float)$row['cash_amt'];
  $draw_cash_bank   = (float)$row['bank_amt'];
  $draw_cash_wallet = (float)$row['wallet_amt'];
  $draw_cash = $draw_cash_cash + $draw_cash_bank + $draw_cash_wallet;
}
$drawings = $draw_items + $draw_cash;

$opening_equity    = $total_assets - $accounts_payable - ($net_profit - $drawings);
$closing_equity    = $opening_equity + $net_profit - $drawings;
$total_liab_equity = $accounts_payable + $closing_equity;

/* ------------------ Labels ------------------ */
$branch_label='All Branches';
if ($is_admin && $branch_id)      $branch_label=$branch_map[$branch_id] ?? ("Branch #$branch_id");
elseif (!$is_admin)                $branch_label=$branch_map[$branch_id] ?? 'My Branch';

$period_label = ($range==='today') ? ('Today ('.htmlspecialchars($start_date).')')
             : (($range==='month') ? ('This Month ('.DateTime::createFromFormat('Y-m-d',$start_date)->format('F Y').')')
             : (($range==='year')  ? ('This Year ('.DateTime::createFromFormat('Y-m-d',$start_date)->format('Y').')')
             : ('From '.htmlspecialchars($start_date).' to '.htmlspecialchars($end_date)) ));
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Profit &amp; Loss / Balance Sheet</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body{background:#f6f7fb;font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,'Helvetica Neue',Arial;}
    .badge-ghost{background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;padding:6px 10px;color:#374151}
    .subrow{padding-left:1rem;color:#555}
  </style>
</head>
<body>
<?php include 'includes/nav.php'; ?>
<div class="container my-4">
  <div class="d-flex align-items-center gap-2 mb-3">
    <h3 class="mb-0">Profit &amp; Loss and Balance Sheet</h3>
    <span class="badge-ghost"><?php echo htmlspecialchars($branch_label); ?></span>
    <span class="badge-ghost"><?php echo htmlspecialchars($period_label); ?></span>
  </div>

  <!-- Filter -->
  <form class="row g-2 align-items-end mb-3" method="get">
    <div class="col-md-2">
      <label class="form-label">Range</label>
      <select class="form-select" name="range" onchange="toggleCustomRange(this.value)">
        <option value="today"  <?php echo ($range==='today'?'selected':''); ?>>Today</option>
        <option value="month"  <?php echo ($range==='month'?'selected':''); ?>>This Month</option>
        <option value="year"   <?php echo ($range==='year'?'selected':''); ?>>This Year</option>
        <option value="custom" <?php echo ($range==='custom'?'selected':''); ?>>Custom</option>
      </select>
    </div>
    <div class="col-md-3" id="startDateDiv" style="display:<?php echo ($range==='custom'?'block':'none'); ?>;">
      <label class="form-label">Start Date</label>
      <input type="date" class="form-control" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>">
    </div>
    <div class="col-md-3" id="endDateDiv" style="display:<?php echo ($range==='custom'?'block':'none'); ?>;">
      <label class="form-label">End Date</label>
      <input type="date" class="form-control" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>">
    </div>
    <?php if ($is_admin): ?>
      <div class="col-md-3">
        <label class="form-label">Branch</label>
        <select class="form-select" name="branch_id">
          <option value="0" <?php echo ($branch_id===0?'selected':''); ?>>All Branches</option>
          <?php foreach ($branches as $b): ?>
            <option value="<?php echo (int)$b['id']; ?>" <?php echo ($branch_id===(int)$b['id']?'selected':''); ?>>
              <?php echo htmlspecialchars($b['name']); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    <?php endif; ?>
    <div class="col-md-2"><button class="btn btn-primary w-100" type="submit">Apply</button></div>
  </form>

  <!-- Profit Summary -->
  <div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
      <span>Profit Summary Details</span>
    </div>
    <div class="card-body">
      <div class="row g-2 mb-1"><div class="col-6">Sales (Gross)</div><div class="col-6 text-end">Rs. <?php echo number_format($sales_gross,2); ?></div></div>
      <div class="row g-2 mb-1"><div class="col-6">(-) Sales Return</div><div class="col-6 text-end text-danger">-Rs. <?php echo number_format($sales_return_amt,2); ?></div></div>
      <div class="row g-2 mb-1"><div class="col-6">Net Sales</div><div class="col-6 text-end">Rs. <?php echo number_format($net_sales_before_discount,2); ?></div></div>

      <div class="row g-2 mb-2"><div class="col-12 fw-bold">(-) Cost of Goods Sold</div></div>
      <div class="row g-2 mb-1 subrow"><div class="col-6">COGS from Sales (period)</div><div class="col-6 text-end">Rs. <?php echo number_format($cogs_sales,2); ?></div></div>
      <div class="row g-2 mb-1 subrow"><div class="col-6">(-) COGS of Sale Returns (period)</div><div class="col-6 text-end text-danger">-Rs. <?php echo number_format($cogs_returns,2); ?></div></div>
      <div class="row g-2 mb-1"><div class="col-6">Cost of Sales</div><div class="col-6 text-end">Rs. <?php echo number_format($cost_of_sales,2); ?></div></div>

      <div class="row g-2 mb-2"><div class="col-12 fw-bold">Inventory Movement (for reference)</div></div>
      <div class="row g-2 mb-1 subrow"><div class="col-6">Opening Stock (as of <?php echo htmlspecialchars($start_dt); ?>)</div><div class="col-6 text-end">Rs. <?php echo number_format($opening_stock_value,2); ?></div></div>
      <div class="row g-2 mb-1 subrow"><div class="col-6">(+)&nbsp;Opening Stock injections (setup)</div><div class="col-6 text-end">Rs. <?php echo number_format($opening_in_period_val,2); ?></div></div>
      <div class="row g-2 mb-1 subrow"><div class="col-6">Purchases + Transfers In (period)</div><div class="col-6 text-end">Rs. <?php echo number_format($purchase_period_val + $transfer_in_period_val,2); ?></div></div>
      <div class="row g-2 mb-1 subrow"><div class="col-6">Closing Stock (as of <?php echo htmlspecialchars($end_dt); ?>)</div><div class="col-6 text-end">Rs. <?php echo number_format($closing_stock_value,2); ?></div></div>

      <div class="row g-2 mb-1"><div class="col-6">Gross Profit</div><div class="col-6 text-end fw-bold">Rs. <?php echo number_format($gross_profit,2); ?></div></div>
      <div class="row g-2 mb-1"><div class="col-6">(+) Other Income</div><div class="col-6 text-end">Rs. <?php echo number_format($other_income,2); ?></div></div>
      <div class="row g-2 mb-1"><div class="col-6">(-) Expenses</div><div class="col-6 text-end text-danger">-Rs. <?php echo number_format($expenses_total,2); ?></div></div>
      <div class="row g-2 mb-1"><div class="col-6">Net Profit</div><div class="col-6 text-end fw-bold">Rs. <?php echo number_format($net_profit,2); ?></div></div>
    </div>
  </div>

  <!-- Balance Sheet -->
  <div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
      <span>Balance Sheet</span>
    </div>
    <div class="card-body">
      <div class="row g-4">
        <div class="col-md-6">
          <h6 class="fw-bold mb-2">Assets</h6>
          <div class="d-flex justify-content-between mb-1"><span>Inventory</span><span>Rs. <?php echo number_format($closing_stock_value,2); ?></span></div>
          <div class="d-flex justify-content-between mb-1"><span>Customer Debtors</span><span>Rs. <?php echo number_format($customer_debtors,2); ?></span></div>
          <div class="d-flex justify-content-between mb-1"><span>Branch Debtors</span><span>Rs. <?php echo number_format($branch_debtors,2); ?></span></div>
          <div class="d-flex justify-content-between mb-1"><span>Cash in Hand</span><span>Rs. <?php echo number_format($cash_in_hand,2); ?></span></div>
          <div class="d-flex justify-content-between mb-1"><span>Bank Balance</span><span>Rs. <?php echo number_format($bank_balance,2); ?></span></div>
          <div class="d-flex justify-content-between mb-1"><span>Wallet Balance</span><span>Rs. <?php echo number_format($wallet_balance,2); ?></span></div>
          <div class="d-flex justify-content-between mb-1"><span>Cheque Return</span><span>Rs. <?php echo number_format($cheque_return,2); ?></span></div>
          <div class="d-flex justify-content-between mb-1"><span>Cheque in Hand</span><span>Rs. <?php echo number_format($cheque_in_hand,2); ?></span></div>
          <hr class="my-2">
          <div class="d-flex justify-content-between fw-bold">
            <span>Total Assets</span><span>Rs. <?php echo number_format($total_assets, 2); ?></span>
          </div>
        </div>

        <div class="col-md-6">
          <h6 class="fw-bold mb-2">Liabilities &amp; Equity</h6>
          <div class="d-flex justify-content-between mb-1"><span>Accounts Payable (Suppliers)</span><span>Rs. <?php echo number_format($supplier_payable,2); ?></span></div>
          <div class="d-flex justify-content-between mb-1"><span>Cheque Payable (Suppliers)</span><span>Rs. <?php echo number_format($cheque_payable,2); ?></span></div>
          <div class="d-flex justify-content-between mb-1"><span>Cargo Payable</span><span>Rs. <?php echo number_format($cargo_payable,2); ?></span></div>
          <div class="d-flex justify-content-between mb-1"><span>Branch Payable</span><span>Rs. <?php echo number_format($branch_payable,2); ?></span></div>
          <div class="d-flex justify-content-between mb-3 fw-bold"><span>Total Liabilities</span><span>Rs. <?php echo number_format($accounts_payable,2); ?></span></div>
          <div class="mt-2">
            <h6 class="fw-bold">Equity</h6>
            <div class="d-flex justify-content-between mb-1"><span>Opening Equity</span><span>Rs. <?php echo number_format($opening_equity,2); ?></span></div>
            <div class="d-flex justify-content-between mb-1"><span>(+) Net Profit</span><span>Rs. <?php echo number_format($net_profit,2); ?></span></div>
            <div class="d-flex justify-content-between mb-1"><span>(-) Drawings — Items</span><span>Rs. <?php echo number_format($draw_items,2); ?></span></div>
            <div class="d-flex justify-content-between mb-1"><span>(-) Drawings — Cash/Bank/Wallet</span><span>Rs. <?php echo number_format($draw_cash,2); ?></span></div>
            <div class="d-flex justify-content-between mb-1 fw-semibold"><span>Total Drawings</span><span>Rs. <?php echo number_format($drawings,2); ?></span></div>
            <div class="d-flex justify-content-between fw-bold"><span>Closing Equity</span><span>Rs. <?php echo number_format($closing_equity,2); ?></span></div>
          </div>
        </div>
      </div>

      <hr class="mt-3">
      <div class="row fw-bold">
        <div class="col-md-6 d-flex justify-content-between">
          <span>Total Assets</span>
          <span>Rs. <?php echo number_format($total_assets, 2); ?></span>
        </div>
        <div class="col-md-6 d-flex justify-content-between">
          <span>Total Liabilities + Equity</span>
          <span>Rs. <?php echo number_format($total_liab_equity, 2); ?></span>
        </div>
      </div>
      <?php if (abs($total_liab_equity - $total_assets) > 0.01): ?>
        <div class="text-danger small mt-1">
          Warning: Balance mismatch of Rs. <?php echo number_format($total_liab_equity - $total_assets, 2); ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <p class="text-muted small mb-0">
    Inventory includes stock adjustments (± ΔQty × Cost) once. Negative adjustments are shown in Drawings — Items for the period.
  </p>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function toggleCustomRange(val){
  const show = (val === 'custom');
  document.getElementById('startDateDiv').style.display = show ? 'block' : 'none';
  document.getElementById('endDateDiv').style.display = show ? 'block' : 'none';
}
</script>
</body>
</html>
