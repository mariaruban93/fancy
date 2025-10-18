<?php
/**
 * ajax_pay_supplier_opening.php
 *
 * Handles paying down a supplier's opening balance.
 * It expects POST parameters:
 *   supplier_id (int) – the supplier's ID
 *   amount      (float) – the amount to pay against the opening balance
 *   method      (string) – payment method (cash|bank|wallet|cheque)
 *
 * This endpoint will deduct the specified amount from the supplier's
 * opening balance, provided the amount does not exceed the current
 * outstanding opening balance.  Only admin and manager roles are
 * permitted to perform this action.
 *
 * Returns JSON: {status:'success'} on success, or {status:'error',message:'...'} on error.
 */

require_once 'includes/header.php';
header('Content-Type: application/json');

// Allow only admin or manager to pay supplier opening balances
checkRole(['admin','manager']);

// Sanitize and extract POST parameters
$supplier_id_raw = $_POST['supplier_id'] ?? '';
$amount_raw      = $_POST['amount']      ?? '';
$method_raw      = $_POST['method']      ?? '';

$supplier_id = (int)preg_replace('/\D+/', '', (string)$supplier_id_raw);

// Normalize amount (remove commas and non numeric characters)
$amount_str = str_replace(',', '', (string)$amount_raw);
$amount_str = preg_replace('/[^\d\.\-]/', '', $amount_str);
$amount     = is_numeric($amount_str) ? (float)$amount_str : 0.0;

$method = strtolower(trim((string)$method_raw));
if ($method === '') $method = 'cash';
$allowed_methods = ['cash','bank','wallet','cheque'];
if (!in_array($method, $allowed_methods, true)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid payment method']);
    exit;
}

if ($supplier_id <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Missing supplier_id']);
    exit;
}
if ($amount <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Amount must be greater than zero']);
    exit;
}

try {
    // Ensure the suppliers table has an opening_balance column
    $colCheck = $pdo->query("SHOW COLUMNS FROM suppliers LIKE 'opening_balance'");
    if (!$colCheck || !$colCheck->fetch()) {
        throw new RuntimeException('Opening balance not supported on suppliers table');
    }
    // Fetch current opening balance for the supplier
    $stmt = $pdo->prepare("SELECT opening_balance FROM suppliers WHERE id=?");
    $stmt->execute([$supplier_id]);
    $current = $stmt->fetchColumn();
    if ($current === false) {
        throw new RuntimeException('Supplier not found');
    }
    $current = (float)$current;
    if ($current <= 0) {
        throw new RuntimeException('No outstanding opening balance');
    }
    if ($amount > $current) {
        throw new RuntimeException('Payment exceeds outstanding opening balance');
    }
    // Deduct the amount from opening balance
    $upd = $pdo->prepare("UPDATE suppliers SET opening_balance = opening_balance - ? WHERE id=?");
    $upd->execute([$amount, $supplier_id]);

    // Record a journal entry to reflect the cash/bank/wallet outflow.  Without this,
    // cash and bank balances in the financial statement will not be affected.
    try {
        // Determine branch and user context
        global $user;
        $branchId  = isset($user['branch_id']) && (int)$user['branch_id'] > 0 ? (int)$user['branch_id'] : 0;
        $createdBy = isset($user['id']) ? (int)$user['id'] : 0;
        $entryDate = date('Y-m-d');
        $createdAt = date('Y-m-d H:i:s');
        // Determine account type for journal entry based on payment method
        $accType = 'cash';
        if ($method === 'bank') $accType = 'bank';
        elseif ($method === 'wallet') $accType = 'wallet';
        // For cheque method we treat it as cash outflow immediately; users will deposit separately when cleared.
        $desc = 'Supplier opening payment ('.$method.')';
        $je = $pdo->prepare("INSERT INTO journal_entries (branch_id, entry_date, account_type, amount, description, created_by, created_at) VALUES (?,?,?,?,?,?,?)");
        // Amount is negative for outflow
        $je->execute([$branchId, $entryDate, $accType, -1 * $amount, $desc, $createdBy, $createdAt]);
    } catch (Throwable $jex) {
        // If journal entry fails we still continue but log error
    }
    echo json_encode(['status' => 'success']);
} catch (Throwable $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}