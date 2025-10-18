<?php
/**
 * ajax_receive_opening_payment.php
 *
 * Handles receiving a payment against a customer's opening balance.
 * It expects POST parameters:
 *   customer_id (int), amount (float), method (cash|bank|wallet|cheque)
 * Returns JSON: {status:'success'} or {status:'error',message:'...'}
 */
require_once 'includes/header.php';
header('Content-Type: application/json');

// Only admin, manager or cashier can record opening payments
checkRole(['admin','manager','cashier']);

// Sanitize inputs
$customer_id = isset($_POST['customer_id']) ? (int)$_POST['customer_id'] : 0;
$amount_raw  = $_POST['amount'] ?? '';
$method_raw  = $_POST['method'] ?? '';

// Normalize amount (allow comma separators)
$amount_str = str_replace(',', '', (string)$amount_raw);
$amount_str = preg_replace('/[^\d\.\-]/', '', $amount_str);
$amount     = is_numeric($amount_str) ? (float)$amount_str : 0.0;

$method = strtolower(trim((string)$method_raw));
if ($method === '') $method = 'cash';
// Allowed payment methods
$allowed_methods = ['cash','bank','wallet','cheque'];

// Accept optional cheque and bank details for UI consistency. These
// values are not persisted by this endpoint but are captured so that
// callers can send the same payload structure as other payment forms.
$cheque_number  = isset($_POST['cheque_number']) ? trim((string)$_POST['cheque_number']) : '';
$bank_name      = isset($_POST['bank_name'])     ? trim((string)$_POST['bank_name'])     : '';
$bank_branch    = isset($_POST['bank_branch'])   ? trim((string)$_POST['bank_branch'])   : '';
$deposit_date   = isset($_POST['deposit_date'])  ? trim((string)$_POST['deposit_date'])  : '';
$bank_account_id = isset($_POST['bank_account_id']) ? (int)$_POST['bank_account_id'] : 0;

// Validate payment method
if (!in_array($method, $allowed_methods, true)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid payment method']);
    exit;
}
if ($customer_id <= 0) {
    echo json_encode(['status'=>'error','message'=>'Missing customer_id']);
    exit;
}
if ($amount <= 0) {
    echo json_encode(['status'=>'error','message'=>'Amount must be greater than zero']);
    exit;
}

try {
    // Validate that bank/cheque methods require at least one bank account and a selected bank name
    if ($method === 'bank' || $method === 'cheque') {
        // Determine branch for current user (if any)
        $branchId = isset($user['branch_id']) && (int)$user['branch_id'] > 0 ? (int)$user['branch_id'] : 0;
        // Check bank account availability
        $stmtBA = $pdo->prepare("SELECT id FROM bank_accounts WHERE branch_id=? LIMIT 1");
        $stmtBA->execute([$branchId]);
        $hasBank = (bool)$stmtBA->fetchColumn();
        if (!$hasBank) {
            throw new RuntimeException('No bank account configured for this branch; cannot use bank/cheque payment');
        }
        // Ensure bank_name is provided for bank and cheque payments
        if ($bank_name === '') {
            throw new RuntimeException('Select a bank for '.($method==='bank'?'bank':'cheque').' payment');
        }
    }

    // Ensure opening_balance column exists
    $colCheck = $pdo->query("SHOW COLUMNS FROM customers LIKE 'opening_balance'");
    if (!$colCheck || !$colCheck->fetch()) {
        throw new RuntimeException('Opening balance not supported on customers table');
    }
    // Fetch current opening balance
    $stmt = $pdo->prepare("SELECT opening_balance FROM customers WHERE id=?");
    $stmt->execute([$customer_id]);
    $current = $stmt->fetchColumn();
    if ($current === false) {
        throw new RuntimeException('Customer not found');
    }
    $current = (float)$current;
    if ($current <= 0) {
        throw new RuntimeException('No outstanding opening balance');
    }
    if ($amount > $current) {
        throw new RuntimeException('Payment exceeds outstanding opening balance');
    }
    // Deduct the amount from opening balance
    $upd = $pdo->prepare("UPDATE customers SET opening_balance = opening_balance - ? WHERE id=?");
    $upd->execute([$amount, $customer_id]);

    // Record a journal entry to reflect the cash/bank/wallet inflow from the customer opening balance payment.
    try {
        global $user;
        $branchId  = isset($user['branch_id']) && (int)$user['branch_id'] > 0 ? (int)$user['branch_id'] : 0;
        $createdBy = isset($user['id']) ? (int)$user['id'] : 0;
        $entryDate = date('Y-m-d');
        $createdAt = date('Y-m-d H:i:s');
        $accType = 'cash';
        if ($method === 'bank') $accType = 'bank';
        elseif ($method === 'wallet') $accType = 'wallet';
        // Cheque: treat as cash inflow until deposited; adjust later when deposit occurs via deposit cheque logic.
        $desc = 'Customer opening payment ('.$method.')';
        $je = $pdo->prepare("INSERT INTO journal_entries (branch_id, entry_date, account_type, amount, description, created_by, created_at) VALUES (?,?,?,?,?,?,?)");
        // Amount positive for inflow
        $je->execute([$branchId, $entryDate, $accType, $amount, $desc, $createdBy, $createdAt]);
    } catch (Throwable $jex) {
        // Failing to record journal entry should not block payment update
    }
    echo json_encode(['status'=>'success']);
} catch (Throwable $e) {
    echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
}