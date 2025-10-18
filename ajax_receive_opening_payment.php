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

/**
 * Ensure the customer_opening_payments table exists so that cheque based
 * opening payments can be surfaced in cheque reports and financials.
 */
function ensure_customer_opening_table(PDO $pdo): void {
    static $checked = false;
    if ($checked) {
        return;
    }
    $sql = "CREATE TABLE IF NOT EXISTS customer_opening_payments (
                id INT AUTO_INCREMENT PRIMARY KEY,
                customer_id INT NOT NULL,
                branch_id INT DEFAULT NULL,
                method VARCHAR(20) NOT NULL,
                amount DECIMAL(12,2) NOT NULL,
                cheque_number VARCHAR(120) DEFAULT NULL,
                bank_name VARCHAR(150) DEFAULT NULL,
                bank_branch VARCHAR(150) DEFAULT NULL,
                expected_deposit_date DATE DEFAULT NULL,
                deposit_date DATE DEFAULT NULL,
                bank_account_id INT DEFAULT NULL,
                received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                created_by INT DEFAULT NULL,
                INDEX idx_customer_method (customer_id, method)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    try {
        $pdo->exec($sql);
    } catch (Throwable $ignore) {
        // Swallow errors; inserts will fail later and bubble up if the table cannot be created.
    }
    $checked = true;
}

// Accept optional cheque and bank details for UI consistency. These
// values are not persisted by this endpoint but are captured so that
// callers can send the same payload structure as other payment forms.
$cheque_number   = isset($_POST['cheque_number']) ? trim((string)$_POST['cheque_number']) : '';
$bank_name_input = isset($_POST['bank_name'])     ? trim((string)$_POST['bank_name'])     : '';
$bank_branch     = isset($_POST['bank_branch'])   ? trim((string)$_POST['bank_branch'])   : '';
$deposit_date    = isset($_POST['deposit_date'])  ? trim((string)$_POST['deposit_date'])  : '';
$bank_account_id = isset($_POST['bank_account_id']) ? (int)$_POST['bank_account_id'] : 0;

// Normalise bank name depending on method. For bank payments the UI passes an
// ID from the select element, whereas cheque payments capture a free-form name.
$bank_name = '';
if ($method === 'bank') {
    $bank_name = $bank_name_input;
} elseif ($method === 'cheque') {
    $bank_name = $bank_name_input;
}

// Expected deposit date is captured so the finance team knows the promised
// deposit timeline, while the actual deposit date will be filled when the
// cheque is deposited through the cheque management screens.
$expected_deposit_date = null;
if ($deposit_date !== '') {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $deposit_date)) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid deposit date format']);
        exit;
    }
    $expected_deposit_date = $deposit_date;
}

global $user;
$branchIdContext = isset($user['branch_id']) && (int)$user['branch_id'] > 0 ? (int)$user['branch_id'] : null;
$createdByUser   = isset($user['id']) ? (int)$user['id'] : null;

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

    if ($method === 'cheque') {
        ensure_customer_opening_table($pdo);
        $receivedAt = date('Y-m-d H:i:s');
        $insert = $pdo->prepare(
            "INSERT INTO customer_opening_payments
                (customer_id, branch_id, method, amount, cheque_number, bank_name, bank_branch,
                 expected_deposit_date, deposit_date, bank_account_id, received_at, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)"
        );
        $insert->execute([
            $customer_id,
            $branchIdContext,
            $method,
            $amount,
            $cheque_number !== '' ? $cheque_number : null,
            $bank_name !== '' ? $bank_name : null,
            $bank_branch !== '' ? $bank_branch : null,
            $expected_deposit_date,
            null,
            $bank_account_id > 0 ? $bank_account_id : null,
            $receivedAt,
            $createdByUser
        ]);
    }

    // Record a journal entry to reflect the cash/bank/wallet inflow from the customer opening balance payment.
    try {
        $branchId  = $branchIdContext ?? 0;
        $createdBy = $createdByUser ?? 0;
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