<?php
/**
 * financial_statement.php — Accrual-safe + Cash/Bank fixes (FINAL)
 * - Branch Payable computed from stock_transfers/items & transfer_payments
 * - Supplier AP: (purchases − returns − payments) using purchase_items
 * - Supplier cheque payable uses deposit_date (uncleared = outstanding)
 * - CASH: no double-count — use latest opening per branch + movements since
 * - BANK: includes journal_entries(account_type='bank') net, so EOD transfer shows up
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
  try { return (bool)$pdo->query("SHOW COLUMNS FROM `$table` LIKE '$col'")->fetch(PDO::FETCH_ASSOC); }
  catch (Throwable $e) { return false; }
}
function table_exists(PDO $pdo, string $table): bool {
  try { $q=$pdo->query("SHOW TABLES LIKE ".$pdo->quote($table)); return (bool)($q && $q->fetchColumn()); }
  catch (Throwable $e) { return false; }
}
function first_available_col(PDO $pdo, string $table, array $candidates): ?string {
  foreach ($candidates as $c) {
    try { $q = $pdo->query("SHOW COLUMNS FROM `$table` LIKE '$c'"); if ($q && $q->fetch(PDO::FETCH_ASSOC)) return $c; }
    catch (Throwable $e) {}
  }
  return null;
}

/* optional flags/columns */
$has_opening_flag_1 = col_exists($pdo,'purchases','is_opening_stock');
$has_opening_flag_2 = col_exists($pdo,'purchases','is_opening');
$pp_has_method      = col_exists($pdo,'purchase_payments','method');
$cargo_has_method   = col_exists($pdo,'cargo_payments','method');
$expenses_has_method= col_exists($pdo,'expenses','payment_method'); // for cash-only expense handling

/* ------------------ Stock value as-of ------------------ */
function stock_value_as_of(string $cutoff_dt, int $branch_id, PDO $pdo): float {
  $params = [':to'=>$cutoff_dt, ':b'=>$branch_id];
  $sum = 0.0;

  try {
    $st=$pdo->prepare("SELECT COALESCE(SUM(je.amount),0) FROM journal_entries je
                       WHERE je.entry_date<=:to AND je.branch_id=:b AND je.account_type='stock'");
    $st->execute($params); $sum += (float)$st->fetchColumn();
  } catch(Throwable $e){}

  $st=$pdo->prepare("SELECT COALESCE(SUM(pi.quantity*pi.cost_price),0)
                     FROM purchase_items pi JOIN purchases p ON pi.purchase_id=p.id
                     WHERE p.purchase_date<=:to AND p.branch_id=:b");
  $st->execute($params); $sum += (float)$st->fetchColumn();

  $st=$pdo->prepare("SELECT COALESCE(SUM(sti.quantity*sb.cost_price),0)
                     FROM stock_transfer_items sti
                     JOIN stock_transfers st ON sti.transfer_id=st.id
                     JOIN stock_batches sb    ON sb.id=sti.batch_id
                     WHERE st.transferred_at<=:to AND st.to_branch_id=:b");
  $st->execute($params); $sum += (float)$st->fetchColumn();

  $st=$pdo->prepare("SELECT COALESCE(SUM(sti.quantity*sb.cost_price),0)
                     FROM stock_transfer_items sti
                     JOIN stock_transfers st ON sti.transfer_id=st.id
                     JOIN stock_batches sb    ON sb.id=sti.batch_id
                     WHERE st.transferred_at<=:to AND st.from_branch_id=:b");
  $st->execute($params); $sum -= (float)$st->fetchColumn();

  $st=$pdo->prepare("SELECT COALESCE(SUM(si.quantity*sb.cost_price),0)
                     FROM sale_items si
                     JOIN sales s ON si.sale_id=s.id
                     JOIN stock_batches sb ON sb.id=si.batch_id
                     WHERE s.sale_date<=:to AND s.branch_id=:b");
  $st->execute($params); $sum -= (float)$st->fetchColumn();

  $st=$pdo->prepare("SELECT COALESCE(SUM(sri.quantity*sb.cost_price),0)
                     FROM sale_return_items sri
                     JOIN sale_returns sr ON sri.sale_return_id=sr.id
                     JOIN sales s ON sr.sale_id=s.id
                     JOIN stock_batches sb ON sb.id=sri.batch_id
                     WHERE sr.return_date<=:to AND s.branch_id=:b");
  $st->execute($params); $sum += (float)$st->fetchColumn();

  $st=$pdo->prepare("SELECT COALESCE(SUM(pr.total_refund),0)
                     FROM purchase_returns pr
                     JOIN purchases p ON pr.purchase_id=p.id
                     WHERE pr.return_date<=:to AND p.branch_id=:b");
  $st->execute($params); $sum -= (float)$st->fetchColumn();

  $st=$pdo->prepare("SELECT COALESCE(SUM(wo.quantity*wo.cost_price),0)
                     FROM write_offs wo
                     WHERE wo.write_off_date<=:to AND wo.branch_id=:b");
  $st->execute($params); $sum -= (float)$st->fetchColumn();

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

/* Opening & Closing stock values via movements */
if ($is_admin && !$branch_id) {
  $opening_stock_value=0.0; $closing_stock_value=0.0;
  try {
    $ids=$pdo->query("SELECT id FROM branches")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $bid) {
      $opening_stock_value+=stock_value_as_of($start_dt,(int)$bid,$pdo);
      $closing_stock_value+=stock_value_as_of($end_dt,(int)$bid,$pdo);
    }
  } catch(Throwable $e){}
} else {
  $opening_stock_value=stock_value_as_of($start_dt,$branch_id,$pdo);
  $closing_stock_value=stock_value_as_of($end_dt,$branch_id,$pdo);
}

/* Purchases & transfers (period) just for the summary display */
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

$opening_in_period_val=0.0;
try{
  $st=$pdo->prepare("SELECT COALESCE(SUM(je.amount),0)
                     FROM journal_entries je
                     WHERE je.entry_date BETWEEN :f AND :t
                       AND je.account_type='stock'".$andBranchOnly);
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

/* --------- Branch Debtors / Payable (ACCRUAL FROM TRANSFERS) --------- */
$receivable_val = 0.0; // we sent goods → others owe us
$payable_val    = 0.0; // we received goods → we owe others
try {
  if ($is_admin && !$branch_id) {
    // all branches case handled later (per-branch netting is safer); keep zeros
  } else {
    // SENT (others owe us)
    $st=$pdo->prepare("SELECT COALESCE(SUM(sti.quantity*sb.cost_price),0)
                       FROM stock_transfer_items sti
                       JOIN stock_batches sb ON sb.id=sti.batch_id
                       JOIN stock_transfers st ON st.id=sti.transfer_id
                       WHERE st.transferred_at<=:t AND st.from_branch_id=:b");
    $st->execute([':t'=>$end_dt, ':b'=>$branch_id]);
    $receivable_val=(float)$st->fetchColumn();

    // RECEIVED (we owe others)
    $st=$pdo->prepare("SELECT COALESCE(SUM(sti.quantity*sb.cost_price),0)
                       FROM stock_transfer_items sti
                       JOIN stock_batches sb ON sb.id=sti.batch_id
                       JOIN stock_transfers st ON st.id=sti.transfer_id
                       WHERE st.transferred_at<=:t AND st.to_branch_id=:b");
    $st->execute([':t'=>$end_dt, ':b'=>$branch_id]);
    $payable_val=(float)$st->fetchColumn();

    // Payments reduce receivable / payable
    $st=$pdo->prepare("SELECT COALESCE(SUM(tp.amount),0)
                       FROM transfer_payments tp
                       JOIN stock_transfers st ON st.id=tp.transfer_id
                       WHERE tp.paid_at<=:t AND st.from_branch_id=:b");
    $st->execute([':t'=>$end_dt, ':b'=>$branch_id]);
    $payments_on_sent=(float)$st->fetchColumn();

    $st=$pdo->prepare("SELECT COALESCE(SUM(tp.amount),0)
                       FROM transfer_payments tp
                       JOIN stock_transfers st ON st.id=tp.transfer_id
                       WHERE tp.paid_at<=:t AND st.to_branch_id=:b");
    $st->execute([':t'=>$end_dt, ':b'=>$branch_id]);
    $payments_on_recv=(float)$st->fetchColumn();

    $receivable_outstanding = max($receivable_val - $payments_on_sent, 0.0);
    $payable_outstanding    = max($payable_val    - $payments_on_recv, 0.0);

    $branch_debtors = 0.0;
    $branch_payable = 0.0;
    $net = $receivable_outstanding - $payable_outstanding;
    if ($net >= 0) $branch_debtors = $net; else $branch_payable = -$net;
  }
} catch(Throwable $e){
  $branch_debtors=0.0; $branch_payable=0.0;
}

/* ------------------ CASH (as-of, NO DOUBLE COUNT) ------------------ */

/* helper: latest opening for a branch on/before end date */
function last_opening_for_branch(PDO $pdo, int $branch_id, string $end_date): array {
  $res = ['amount'=>0.0,'date'=>null];
  if (!$branch_id || !table_exists($pdo,'cash_openings')) return $res;
  try {
    $st=$pdo->prepare("SELECT opening_amount, opening_date
                       FROM cash_openings
                       WHERE branch_id=:b AND opening_date<=:d
                       ORDER BY opening_date DESC LIMIT 1");
    $st->execute([':b'=>$branch_id, ':d'=>$end_date]);
    $row=$st->fetch(PDO::FETCH_ASSOC);
    if ($row) { $res['amount']=(float)$row['opening_amount']; $res['date']=$row['opening_date']; }
  } catch(Throwable $e){}
  return $res;
}

/* helper: compute cash balance for ONE branch as-of end_date (no double) */
function cash_balance_for_branch(PDO $pdo, int $branch_id, string $end_date,
                                 bool $pp_has_method, bool $cargo_has_method, bool $expenses_has_method): float {
  if ($branch_id<=0) return 0.0;

  $op = last_opening_for_branch($pdo,$branch_id,$end_date);
  $opening_amount = (float)$op['amount'];
  $from_dt = ($op['date'] ? $op['date'].' 00:00:00' : '1970-01-01 00:00:00');
  $from_d  = substr($from_dt,0,10);
  $to_dt   = $end_date.' 23:59:59';

  // (+) Cash sales
  $st=$pdo->prepare("SELECT COALESCE(SUM(sp.amount),0)
                     FROM sale_payments sp
                     JOIN sales s ON sp.sale_id=s.id
                     WHERE sp.method='cash'
                       AND s.sale_date BETWEEN :f AND :t
                       AND s.branch_id=:b");
  $st->execute([':f'=>$from_dt,':t'=>$to_dt,':b'=>$branch_id]);
  $cash_sales=(float)$st->fetchColumn();

  // (−) Cash returns (if return_type exists, only 'cash' reduce cash)
  $has_return_type = col_exists($pdo,'sale_returns','return_type');
  $sql_ret = "SELECT COALESCE(SUM(sr.total_refund),0)
              FROM sale_returns sr
              JOIN sales s ON sr.sale_id=s.id
              WHERE sr.return_date BETWEEN :f AND :t
                AND s.branch_id=:b";
  if ($has_return_type) $sql_ret .= " AND sr.return_type='cash'";
  $st=$pdo->prepare($sql_ret);
  $st->execute([':f'=>$from_dt,':t'=>$to_dt,':b'=>$branch_id]);
  $cash_returns=(float)$st->fetchColumn();

  // (+/-) Net cash journals (includes EOD transfers etc.)
  $st=$pdo->prepare("SELECT COALESCE(SUM(je.amount),0)
                     FROM journal_entries je
                     WHERE je.account_type='cash'
                       AND je.entry_date BETWEEN :f AND :t
                       AND je.branch_id=:b");
  $st->execute([':f'=>$from_d, ':t'=>$end_date, ':b'=>$branch_id]);
  $cash_journal_net=(float)$st->fetchColumn();

  // (−) Expenses paid by cash (only when the column exists)
  $sql_exp="SELECT COALESCE(SUM(e.amount),0)
            FROM expenses e
            WHERE e.expense_date BETWEEN :fd AND :td
              AND e.branch_id=:b";
  $params_exp=[':fd'=>$from_d, ':td'=>$end_date, ':b'=>$branch_id];
  if ($expenses_has_method) $sql_exp .= " AND e.payment_method='cash'";
  $st=$pdo->prepare($sql_exp); $st->execute($params_exp);
  $cash_exp=(float)$st->fetchColumn();

  // (−) Supplier payments by cash
  if ($pp_has_method) {
    $sql_pp="SELECT COALESCE(SUM(pp.amount),0)
             FROM purchase_payments pp
             JOIN purchases p ON pp.purchase_id=p.id
             WHERE pp.paid_at BETWEEN :f AND :t
               AND pp.method='cash'
               AND p.branch_id=:b";
  } else {
    $sql_pp="SELECT COALESCE(SUM(pp.amount),0)
             FROM purchase_payments pp
             JOIN purchases p ON pp.purchase_id=p.id
             WHERE pp.paid_at BETWEEN :f AND :t
               AND p.branch_id=:b";
  }
  $st=$pdo->prepare($sql_pp); $st->execute([':f'=>$from_dt,':t'=>$to_dt,':b'=>$branch_id]);
  $cash_supplier=(float)$st->fetchColumn();

  // (−) Cargo payments by cash
  if ($cargo_has_method) {
    $sql_cg="SELECT COALESCE(SUM(cp.amount),0)
             FROM cargo_payments cp
             WHERE cp.paid_at BETWEEN :f AND :t
               AND cp.method='cash'
               AND cp.branch_id=:b";
  } else {
    $sql_cg="SELECT COALESCE(SUM(cp.amount),0)
             FROM cargo_payments cp
             WHERE cp.paid_at BETWEEN :f AND :t
               AND cp.branch_id=:b";
  }
  $st=$pdo->prepare($sql_cg); $st->execute([':f'=>$from_dt,':t'=>$to_dt,':b'=>$branch_id]);
  $cash_cargo=(float)$st->fetchColumn();

  // (−) Worker cash payments
  $st=$pdo->prepare("SELECT COALESCE(SUM(wp.amount),0)
                     FROM worker_payments wp
                     JOIN workers w ON wp.worker_id=w.id
                     WHERE wp.method='cash'
                       AND wp.payment_date BETWEEN :fd AND :td
                       AND w.branch_id=:b");
  $st->execute([':fd'=>$from_d, ':td'=>$end_date, ':b'=>$branch_id]);
  $worker_cash=(float)$st->fetchColumn();

  $balance = $opening_amount
           + $cash_sales
           + $cash_journal_net
           - $cash_returns
           - $cash_exp
           - $cash_supplier
           - $cash_cargo
           - $worker_cash;

  return $balance < 0 ? 0.0 : $balance;
}

/* compute cash_in_hand either per-branch or summed for all branches */
if ($is_admin && !$branch_id) {
  $cash_in_hand = 0.0;
  try {
    $ids = $pdo->query("SELECT id FROM branches")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $bid) {
      $cash_in_hand += cash_balance_for_branch(
        $pdo, (int)$bid, $end_date, $pp_has_method, $cargo_has_method, $expenses_has_method
      );
    }
  } catch(Throwable $e){}
} else {
  $cash_in_hand = cash_balance_for_branch(
    $pdo, (int)$branch_id, $end_date, $pp_has_method, $cargo_has_method, $expenses_has_method
  );
}
if ($cash_in_hand<0) $cash_in_hand=0.0;

/* ------------------ BANK (as-of, includes journal net) ------------------ */
$st=$pdo->prepare("SELECT COALESCE(SUM(opening_balance),0)
                   FROM bank_accounts".((!$is_admin||$branch_id)?' WHERE branch_id=:b':''));
$st->execute($paramB);
$bank_balance=(float)$st->fetchColumn();

/* (+) non-cash sale receipts landed to bank (incl. cleared cheques) */
$st=$pdo->prepare("SELECT COALESCE(SUM(sp.amount),0)
                   FROM sale_payments sp JOIN sales s ON sp.sale_id=s.id
                   WHERE sp.method<>'cash' AND (sp.method<>'cheque' OR sp.deposit_date IS NOT NULL)
                     AND s.sale_date<=:t".$andBranchSales);
$st->execute(array_merge([':t'=>$end_dt],$paramB));
$bank_balance += (float)$st->fetchColumn();

/* (+/-) bank journals (net) — includes EOD transfers */
$st=$pdo->prepare("SELECT COALESCE(SUM(je.amount),0)
                   FROM journal_entries je
                   WHERE je.account_type='bank' AND je.entry_date<=:t".$andBranchOnly);
$st->execute(array_merge([':t'=>$end_date],$paramB));
$bank_balance += (float)$st->fetchColumn();

/* (−) worker payments via bank */
$st=$pdo->prepare("SELECT COALESCE(SUM(wp.amount),0)
                   FROM worker_payments wp JOIN workers w ON wp.worker_id=w.id
                   WHERE wp.method='bank' AND wp.payment_date<=:t".((!$is_admin||$branch_id)?' AND w.branch_id=:b':'')); 
$st->execute(array_merge([':t'=>$end_date],$paramB));
$bank_balance -= (float)$st->fetchColumn();

/* (−) supplier payments via bank/card/upi */
if ($pp_has_method) {
  $st=$pdo->prepare("SELECT COALESCE(SUM(pp.amount),0)
                     FROM purchase_payments pp JOIN purchases p ON pp.purchase_id=p.id
                     WHERE pp.paid_at<=:t AND pp.method IN ('bank','card','upi')".((!$is_admin||$branch_id)?' AND p.branch_id=:b':'')); 
  $st->execute(array_merge([':t'=>$end_dt],$paramB));
  $bank_balance -= (float)$st->fetchColumn();
}

/* (−) cargo payments via bank/card/upi */
if ($cargo_has_method) {
  $sql="SELECT COALESCE(SUM(cp.amount),0) FROM cargo_payments cp
        WHERE cp.paid_at<=:t AND cp.method IN ('bank','card','upi')";
  if (!$is_admin || $branch_id) $sql.=" AND cp.branch_id=:b";
  $st=$pdo->prepare($sql); $st->execute(array_merge([':t'=>$end_dt],$paramB));
  $bank_balance -= (float)$st->fetchColumn();
}

/* (−) cleared supplier cheques into bank (counted as bank in) — netted below */
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
  $st=$pdo->prepare($sql); $st->execute($params);
  // We SUBTRACT here because the cheque clear is a supplier payout from bank.
  $bank_balance -= (float)$st->fetchColumn();
}

/* Wallet & customer cheques in hand */
$st=$pdo->prepare("SELECT COALESCE(SUM(je.amount),0)
                   FROM journal_entries je
                   WHERE je.account_type='wallet' AND je.entry_date<=:t".$andBranchOnly);
$st->execute(array_merge([':t'=>$end_date],$paramB));
$wallet_balance=(float)$st->fetchColumn();

$st=$pdo->prepare("SELECT COALESCE(SUM(sp.amount),0)
                   FROM sale_payments sp JOIN sales s ON sp.sale_id=s.id
                   WHERE sp.method='cheque' AND sp.deposit_date IS NULL
                     AND s.sale_date<=:t".$andBranchSales);
$st->execute(array_merge([':t'=>$end_dt],$paramB));
$cheque_in_hand=(float)$st->fetchColumn();
$cheque_return=0.0;

/* ------------------ Supplier & Cargo Payables ------------------ */
/* Supplier invoice value (from purchase_items) up to end date */
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

/* Purchase returns (monetary) */
$sql_ret="SELECT COALESCE(SUM(pr.total_refund),0)
          FROM purchase_returns pr JOIN purchases p ON pr.purchase_id=p.id
          WHERE pr.return_date<=:t";
$paramsRet=[':t'=>$end_dt];
if (!$is_admin || $branch_id) { $sql_ret.=" AND p.branch_id=:b"; $paramsRet[':b']=$branch_id; }
if ($has_opening_flag_1)     { $sql_ret.=" AND COALESCE(p.is_opening_stock,0)=0 "; }
if ($has_opening_flag_2)     { $sql_ret.=" AND COALESCE(p.is_opening,0)=0 "; }
$st=$pdo->prepare($sql_ret); $st->execute($paramsRet);
$pur_returns_to_end=(float)$st->fetchColumn();

/* Supplier payments (any method) */
$sql_pay="SELECT COALESCE(SUM(pp.amount),0)
          FROM purchase_payments pp JOIN purchases p ON pp.purchase_id=p.id
          WHERE pp.paid_at<=:t";
$paramsPay=[':t'=>$end_dt];
if (!$is_admin || $branch_id) { $sql_pay.=" AND p.branch_id=:b"; $paramsPay[':b']=$branch_id; }
if ($has_opening_flag_1)     { $sql_pay.=" AND COALESCE(p.is_opening_stock,0)=0 "; }
if ($has_opening_flag_2)     { $sql_pay.=" AND COALESCE(p.is_opening,0)=0 "; }
$st=$pdo->prepare($sql_pay); $st->execute($paramsPay);
$pur_payments_to_end=(float)$st->fetchColumn();

/* Accounts Payable (Suppliers) */
$supplier_payable=max($purch_invoices_to_end-$pur_returns_to_end-$pur_payments_to_end,0.0);

/* Supplier Cheque Payable (OUTSTANDING) — deposit_date aware */
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

/* Cargo Payable (ACCRUAL) = cargo_services − cargo_payments */
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
$total_assets     = $closing_stock_value + $customer_debtors + ($branch_debtors ?? 0.0) + $cash_in_hand + $bank_balance + $wallet_balance + $cheque_return + $cheque_in_hand;
$accounts_payable = $supplier_payable + $cheque_payable + $cargo_payable + ($branch_payable ?? 0.0);

$st=$pdo->prepare("SELECT COALESCE(SUM(wo.quantity*wo.cost_price),0)
                   FROM write_offs wo
                   WHERE wo.write_off_date BETWEEN :f AND :t".$andBranchOnly);
$st->execute(array_merge([':f'=>$start_dt,':t'=>$end_dt],$paramB));
$drawings=(float)$st->fetchColumn();

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
      <div class="row g-2 mb-1 subrow"><div class="col-6">Purchases + Transfers In (period)</div><div class="col-6 text-end">Rs. <?php echo number_format($period_purchases_display,2); ?></div></div>
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
          <div class="d-flex justify-content-between mb-1"><span>Branch Debtors</span><span>Rs. <?php echo number_format($branch_debtors ?? 0,2); ?></span></div>
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
          <div class="d-flex justify-content-between mb-1"><span>Branch Payable</span><span>Rs. <?php echo number_format($branch_payable ?? 0,2); ?></span></div>
          <div class="d-flex justify-content-between mb-3 fw-bold"><span>Total Liabilities</span><span>Rs. <?php echo number_format($accounts_payable,2); ?></span></div>
          <div class="mt-2">
            <h6 class="fw-bold">Equity</h6>
            <div class="d-flex justify-content-between mb-1"><span>Opening Equity</span><span>Rs. <?php echo number_format($opening_equity,2); ?></span></div>
            <div class="d-flex justify-content-between mb-1"><span>(+) Net Profit</span><span>Rs. <?php echo number_format($net_profit,2); ?></span></div>
            <div class="d-flex justify-content-between mb-1"><span>(-) Drawings</span><span>Rs. <?php echo number_format($drawings,2); ?></span></div>
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
    Update: Cash no longer double-counts openings; Bank now reflects journal transfers. Branch Payable accrues from transfers & payments.
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
