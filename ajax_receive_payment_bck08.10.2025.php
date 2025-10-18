<?php
/**
 * ajax_receive_payment.php — robust & schema-adaptive
 * Records a payment against a sale.
 * POST:
 *   sale_id, amount, method [cash|bank|cheque]
 *   cheque_number, bank_name, bank_branch, deposit_date (optional; for cheque)
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

/* -------- sanitize inputs -------- */
$sale_id_raw  = $_POST['sale_id']  ?? '';
$amount_raw   = $_POST['amount']   ?? '';
$method_raw   = $_POST['method']   ?? '';

$cheque_number = trim((string)($_POST['cheque_number'] ?? ''));
$bank_name     = trim((string)($_POST['bank_name'] ?? ''));
$bank_branch   = trim((string)($_POST['bank_branch'] ?? ''));
$deposit_date  = trim((string)($_POST['deposit_date'] ?? '')); // YYYY-mm-dd or ''

$sale_id = (int)preg_replace('/\D+/', '', (string)$sale_id_raw);

$amount_str = str_replace(',', '', (string)$amount_raw);
$amount_str = preg_replace('/[^\d\.\-]/', '', $amount_str);
$amount     = is_numeric($amount_str) ? (float)$amount_str : 0.0;

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

  // Detect optional columns in sale_payments
  $dateCol   = first_available_col($pdo, 'sale_payments', ['paid_at','payment_date','date','created_at']); // may be null
  $methodCol = first_available_col($pdo, 'sale_payments', ['method','payment_method','type']);
  $noteCol   = first_available_col($pdo, 'sale_payments', ['note','remarks','comment']);
  $userCol   = first_available_col($pdo, 'sale_payments', ['user_id','received_by','created_by']);
  $branchCol = first_available_col($pdo, 'sale_payments', ['branch_id']);
  $chequeNoC = col_exists($pdo, 'sale_payments', 'cheque_number') ? 'cheque_number' : null;
  $bankNameC = col_exists($pdo, 'sale_payments', 'bank_name')     ? 'bank_name'     : null;
  $bankBranC = col_exists($pdo, 'sale_payments', 'bank_branch')   ? 'bank_branch'   : null;
  $depDateC  = col_exists($pdo, 'sale_payments', 'deposit_date')  ? 'deposit_date'  : null;

  $pdo->beginTransaction();

  // Build INSERT dynamically
  $cols = ['sale_id','amount'];
  $vals = [':sale_id',':amount'];
  $par  = [
    ':sale_id' => (int)$sale_id,
    ':amount'  => (float)$amount,
  ];

  if ($dateCol) { $cols[] = $dateCol; $vals[]=':paid_at'; $par[':paid_at']=date('Y-m-d H:i:s'); }
  if ($methodCol) { $cols[]=$methodCol; $vals[]=':method'; $par[':method']=$method; }
  if ($branchCol) { $cols[]=$branchCol; $vals[]=':branch_id'; $par[':branch_id']=(int)$sale['branch_id']; }
  if ($userCol && isset($user['id'])) { $cols[]=$userCol; $vals[]=':uid'; $par[':uid']=(int)$user['id']; }

  // Cheque fields only when present; values only for cheque (else NULL)
  if ($chequeNoC) { $cols[]=$chequeNoC; $vals[]=':cheque_number'; $par[':cheque_number']=($method==='cheque' ? $cheque_number : null); }
  if ($bankNameC) { $cols[]=$bankNameC; $vals[]=':bank_name';     $par[':bank_name']=($method==='cheque' ? $bank_name : null); }
  if ($bankBranC) { $cols[]=$bankBranC; $vals[]=':bank_branch';   $par[':bank_branch']=($method==='cheque' ? $bank_branch : null); }
  if ($depDateC)  { $cols[]=$depDateC;  $vals[]=':deposit_date';  $par[':deposit_date']=($method==='cheque' ? ($deposit_date ?: null) : null); }

  if ($noteCol) { $cols[]=$noteCol; $vals[]=':note'; $par[':note']='Recorded via customer_report'; }

  $sql = "INSERT INTO sale_payments (".implode(',',$cols).") VALUES (".implode(',',$vals).")";
  $ins = $pdo->prepare($sql);
  $ins->execute($par);

  $pdo->commit();
  echo json_encode(['status'=>'success']);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
}
