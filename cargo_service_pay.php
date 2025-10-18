<?php
require_once 'includes/header.php';
checkRole(['admin','manager']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header('Location: cargo_service.php');
  exit;
}

$branchId = (int)($user['branch_id'] ?? 0);

$provider_id = (int)($_POST['provider_id'] ?? 0);
$amount      = (float)($_POST['amount'] ?? 0);
$method      = trim($_POST['method'] ?? 'cash');

$bank_account_id = isset($_POST['bank_account_id']) && $_POST['bank_account_id'] !== '' ? (int)$_POST['bank_account_id'] : null;
$cheque_no  = trim($_POST['cheque_no'] ?? '');
$txn_ref    = trim($_POST['txn_ref'] ?? '');
$notes      = trim($_POST['notes'] ?? '');

// UI collects cheque bank/branch/deposit_date but the table only has cheque_no.
// We'll append extras into notes so you still keep the info.
if ($method === 'cheque') {
  $deposit_date = trim($_POST['deposit_date'] ?? '');
  $bank_name    = trim($_POST['bank_name'] ?? '');
  $bank_branch  = trim($_POST['bank_branch'] ?? '');
  $extra = [];
  if ($deposit_date) $extra[] = "DepositDate:$deposit_date";
  if ($bank_name)    $extra[] = "Bank:$bank_name";
  if ($bank_branch)  $extra[] = "Branch:$bank_branch";
  if (!empty($extra)) {
    $notes = ($notes ? ($notes.'; ') : '') . implode(', ', $extra);
  }
}

$paid_at_in = trim($_POST['paid_at'] ?? '');
if ($paid_at_in) {
  // combine with current time for better traceability
  $paid_at = $paid_at_in . ' ' . date('H:i:s');
} else {
  $paid_at = date('Y-m-d H:i:s');
}

if ($branchId <= 0 || $provider_id <= 0 || $amount <= 0) {
  $_SESSION['flash'] = ['type'=>'danger','msg'=>'Please provide a valid provider and amount.'];
  header('Location: cargo_service.php');
  exit;
}

// Server-side check: do not allow paying more than provider balance
try {
  // total transfers to this provider in this branch
  $st = $pdo->prepare("
    SELECT COALESCE(SUM(cs.amount),0)
    FROM cargo_services cs
    WHERE cs.branch_id = :b AND cs.cargo_provider_id = :p
  ");
  $st->execute([':b'=>$branchId, ':p'=>$provider_id]);
  $sumTransfers = (float)$st->fetchColumn();

  // total payments already made
  $st = $pdo->prepare("
    SELECT COALESCE(SUM(cp.amount),0)
    FROM cargo_payments cp
    WHERE cp.branch_id = :b AND cp.cargo_provider_id = :p
  ");
  $st->execute([':b'=>$branchId, ':p'=>$provider_id]);
  $sumPayments = (float)$st->fetchColumn();

  $currentBalance = max($sumTransfers - $sumPayments, 0.0);

  if ($amount - $currentBalance > 0.00001) {
    $_SESSION['flash'] = ['type'=>'danger','msg'=>'Payment exceeds current provider balance.'];
    header('Location: cargo_service.php');
    exit;
  }

  // Minimal validation per method
  if (($method === 'bank' || $method === 'card') && !$bank_account_id) {
    $_SESSION['flash'] = ['type'=>'danger','msg'=>'Please select a bank account for bank/card payments.'];
    header('Location: cargo_service.php');
    exit;
  }
  if ($method === 'cheque' && $cheque_no === '') {
    $_SESSION['flash'] = ['type'=>'danger','msg'=>'Please enter cheque number for cheque payments.'];
    header('Location: cargo_service.php');
    exit;
  }

  // Insert payment (NOTE: table name is cargo_payments)
  $stmt = $pdo->prepare("
    INSERT INTO cargo_payments
      (cargo_provider_id, branch_id, method, bank_account_id, cheque_no, amount, paid_by, paid_at, notes)
    VALUES
      (:pid, :bid, :m, :bank, :chq, :amt, :by, :dt, :nt)
  ");
  $stmt->execute([
    ':pid'  => $provider_id,
    ':bid'  => $branchId,
    ':m'    => $method,
    ':bank' => $bank_account_id,
    ':chq'  => $cheque_no ?: null,
    ':amt'  => $amount,
    ':by'   => (int)$user['id'],
    ':dt'   => $paid_at,
    ':nt'   => $notes ?: ($txn_ref ? ('TXN: '.$txn_ref) : null)
  ]);

  $_SESSION['flash'] = ['type'=>'success','msg'=>'Payment saved successfully.'];
} catch (Throwable $e) {
  $_SESSION['flash'] = ['type'=>'danger','msg'=>'Error saving payment: '.$e->getMessage()];
}

header('Location: cargo_service.php');
exit;
