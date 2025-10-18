<?php
/**
 * ajax_receive_payment.php — robust & schema-adaptive
 * Records a payment against a sale.
 * POST:
 *   sale_id, amount, method [cash|bank|cheque]
 *   cheque_number, bank_name, bank_branch
 *   deposit_date (YYYY-MM-DD, optional; for cheque)
 *   transfer_date (YYYY-MM-DD, optional; for cheque)   <-- NEW
 * Returns JSON {status: "success"|"error", message?}
 */
require_once 'includes/header.php';
header('Content-Type: application/json');
checkRole(['admin','manager','cashier']);

function col_exists(PDO $pdo, string $table, string $col): bool {
  try {
    $q = $pdo->query("SHOW COLUMNS FROM `$table` LIKE ".$pdo->quote($col));
    return (bool)($q && $q->fetch(PDO::FETCH_ASSOC));
  } catch (Throwable $e) {
    return false;
  }
}
function first_available_col(PDO $pdo, string $table, array $candidates): ?string {
  foreach ($candidates as $c) {
    if (col_exists($pdo,$table,$c)) return $c;
  }
  return null;
}
function clean_money($v): float {
  $s = str_replace(',', '', (string)$v);
  $s = preg_replace('/[^\d\.\-]/', '', $s);
  return is_numeric($s) ? (float)$s : 0.0;
}
function clean_date_or_null($v): ?string {
  $v = trim((string)$v);
  if ($v === '') return null;
  return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
}

/* -------- sanitize inputs -------- */
$sale_id_raw   = $_POST['sale_id']   ?? '';
$amount_raw    = $_POST['amount']    ?? '';
$method_raw    = $_POST['method']    ?? '';

$cheque_number = trim((string)($_POST['cheque_number'] ?? ''));
$bank_name     = trim((string)($_POST['bank_name'] ?? ''));
$bank_branch   = trim((string)($_POST['bank_branch'] ?? ''));

$deposit_date  = clean_date_or_null($_POST['deposit_date']  ?? '');
$transfer_date = clean_date_or_null($_POST['transfer_date'] ?? ''); // <-- NEW

$sale_id = (int)preg_replace('/\D+/', '', (string)$sale_id_raw);
$amount  = clean_money($amount_raw);

$method = strtolower(trim((string)$method_raw));
if ($method === '') $method = 'cash';

$allowed_methods = ['cash','bank','cheque'];
if (!in_array($method, $allowed_methods, true)) {
  echo json_encode(['status'=>'error','message'=>'Invalid payment method']); exit;
}
if (!$sale_id) {
  echo json_encode(['status'=>'error','message'=>'Missing sale_id']); exit;
}
if ($amount <= 0) {
  echo json_encode(['status'=>'error','message'=>'Amount must be greater than zero']); exit;
}

try {
  // Fetch sale & branch
  $st = $pdo->prepare("SELECT id, total_amount, branch_id FROM sales WHERE id=:id");
  $st->execute([':id'=>$sale_id]);
  $sale = $st->fetch(PDO::FETCH_ASSOC);
  if (!$sale) throw new RuntimeException('Sale not found');

  // Non-admin restricted to their branch
  if (($user['role_name'] ?? '') !== 'admin') {
    $myb = (int)($user['branch_id'] ?? 0);
    if ($myb && $myb !== (int)$sale['branch_id']) {
      throw new RuntimeException('Not allowed for this branch');
    }
  }

  // ------------------- Payment balance validations -------------------
  // Enforce that cash payments do not exceed available cash and that
  // bank/cheque payments cannot be recorded unless at least one bank
  // account exists for the branch.  This prevents negative balances in
  // financial statements when paying out more than you have.
  $branchIdForPay = (int)$sale['branch_id'];
  // Helper to compute current cash balance for branch (same as used in transfer payments)
  $getBranchCash = function(int $branchId, PDO $pdo) {
    $cash = 0.0;
    // Opening amounts
    try {
      $stmt = $pdo->prepare("SELECT COALESCE(SUM(opening_amount),0) FROM cash_openings WHERE branch_id=?");
      $stmt->execute([$branchId]);
      $cash += (float)$stmt->fetchColumn();
    } catch (Throwable $e) {}
    // Sales receipts (cash)
    try {
      $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(sp.amount),0)
         FROM sale_payments sp
         JOIN sales s ON s.id = sp.sale_id
         WHERE s.branch_id = ? AND sp.method = 'cash'"
      );
      $stmt->execute([$branchId]);
      $cash += (float)$stmt->fetchColumn();
    } catch (Throwable $e) {}
    // Purchase payments (cash)
    try {
      $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(pp.amount),0)
         FROM purchase_payments pp
         JOIN purchases p ON p.id = pp.purchase_id
         WHERE p.branch_id = ?"
      );
      $stmt->execute([$branchId]);
      $cash -= (float)$stmt->fetchColumn();
    } catch (Throwable $e) {}
    // Expenses (cash)
    try {
      $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE branch_id=? AND payment_method='cash'");
      $stmt->execute([$branchId]);
      $cash -= (float)$stmt->fetchColumn();
    } catch (Throwable $e) {}
    // Worker payments (cash)
    try {
      $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM worker_payments WHERE branch_id=? AND method='cash'");
      $stmt->execute([$branchId]);
      $cash -= (float)$stmt->fetchColumn();
    } catch (Throwable $e) {}
    // Journal entries (cash) – include any manual adjustments (+/-)
    try {
      $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM journal_entries WHERE branch_id=? AND account_type='cash'");
      $stmt->execute([$branchId]);
      $cash += (float)$stmt->fetchColumn();
    } catch (Throwable $e) {}
    return $cash;
  };
  // Helper to check bank accounts existence
  $branchHasBank = function(int $branchId, PDO $pdo) {
    try {
      $stmt = $pdo->prepare("SELECT id FROM bank_accounts WHERE branch_id=? LIMIT 1");
      $stmt->execute([$branchId]);
      return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
      return false;
    }
  };

  if ($method === 'cash') {
    $availableCash = $getBranchCash($branchIdForPay, $pdo);
    if ($amount > $availableCash + 0.0001) {
      throw new RuntimeException('Not enough cash in hand for this payment');
    }
  }
  elseif ($method === 'bank' || $method === 'cheque') {
    // Require at least one bank account for branch
    if (!$branchHasBank($branchIdForPay, $pdo)) {
      throw new RuntimeException('No bank account configured for this branch; cannot use bank/cheque payment');
    }
    // If schema supports bank_account_id on sale_payments, ensure bank details provided
    // We can't reliably compute available bank balance here due to missing bank_account_id column.
    if ($method === 'bank') {
      // If bank_name is empty, instruct user to select
      if (empty($bank_name)) {
        throw new RuntimeException('Select a bank for bank payment');
      }
    }
    if ($method === 'cheque') {
      if (empty($bank_name)) {
        throw new RuntimeException('Select a bank for cheque payment');
      }
    }
  }

  // Detect optional columns in sale_payments
  $dateCol     = first_available_col($pdo, 'sale_payments', ['paid_at','payment_date','date','created_at']); // may be null
  $methodCol   = first_available_col($pdo, 'sale_payments', ['method','payment_method','type']);
  $noteCol     = first_available_col($pdo, 'sale_payments', ['note','remarks','comment']);
  $userCol     = first_available_col($pdo, 'sale_payments', ['user_id','received_by','created_by']);
  $branchCol   = first_available_col($pdo, 'sale_payments', ['branch_id']);

  $chequeNoC   = col_exists($pdo, 'sale_payments', 'cheque_number') ? 'cheque_number' : null;
  $bankNameC   = col_exists($pdo, 'sale_payments', 'bank_name')     ? 'bank_name'     : null;
  $bankBranC   = col_exists($pdo, 'sale_payments', 'bank_branch')   ? 'bank_branch'   : null;
  $depDateC    = col_exists($pdo, 'sale_payments', 'deposit_date')  ? 'deposit_date'  : null;
  $transDateC  = col_exists($pdo, 'sale_payments', 'transfer_date') ? 'transfer_date' : null; // <-- NEW

  $pdo->beginTransaction();

  // Build INSERT dynamically
  $cols = ['sale_id','amount'];
  $vals = [':sale_id',':amount'];
  $par  = [
    ':sale_id' => (int)$sale_id,
    ':amount'  => (float)$amount,
  ];

  if ($dateCol)   { $cols[] = $dateCol;   $vals[]=':paid_at';   $par[':paid_at']   = date('Y-m-d H:i:s'); }
  if ($methodCol) { $cols[] = $methodCol; $vals[]=':method';    $par[':method']    = $method; }
  if ($branchCol) { $cols[] = $branchCol; $vals[]=':branch_id'; $par[':branch_id'] = (int)$sale['branch_id']; }
  if ($userCol && isset($user['id'])) { $cols[]=$userCol; $vals[]=':uid'; $par[':uid']=(int)$user['id']; }

  // Cheque fields only when present; values only for cheque (else NULL)
  if ($chequeNoC) { $cols[]=$chequeNoC; $vals[]=':cheque_number'; $par[':cheque_number']=($method==='cheque' ? $cheque_number : null); }
  if ($bankNameC) {
    $cols[] = $bankNameC;
    $vals[] = ':bank_name';
    // Allow storing bank_name for both bank and cheque methods.  For other methods leave null.
    $par[':bank_name'] = ($method === 'cheque' || $method === 'bank') ? $bank_name : null;
  }
  if ($bankBranC) { $cols[]=$bankBranC; $vals[]=':bank_branch';   $par[':bank_branch']=($method==='cheque' ? $bank_branch : null); }
  if ($depDateC)  { $cols[]=$depDateC;  $vals[]=':deposit_date';  $par[':deposit_date']=($method==='cheque' ? ($deposit_date ?: null) : null); }
  if ($transDateC){ $cols[]=$transDateC;$vals[]=':transfer_date'; $par[':transfer_date']=($method==='cheque' ? ($transfer_date ?: null) : null); } // <-- NEW

  if ($noteCol)   { $cols[]=$noteCol;    $vals[]=':note';         $par[':note']='Recorded via customer_report'; }

  $sql = "INSERT INTO sale_payments (".implode(',',$cols).") VALUES (".implode(',',$vals).")";
  $ins = $pdo->prepare($sql);
  $ins->execute($par);

  $pdo->commit();
  echo json_encode(['status'=>'success']);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
}
