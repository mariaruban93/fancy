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
$cheque_number   = isset($_POST['cheque_number']) ? trim((string)$_POST['cheque_number']) : '';
$bank_name       = isset($_POST['bank_name'])     ? trim((string)$_POST['bank_name'])     : '';
$bank_branch     = isset($_POST['bank_branch'])   ? trim((string)$_POST['bank_branch'])   : '';
$deposit_date    = isset($_POST['deposit_date'])  ? trim((string)$_POST['deposit_date'])  : '';
$bank_account_id = isset($_POST['bank_account_id']) ? (int)$_POST['bank_account_id'] : 0;

$supplier_id = (int)preg_replace('/\D+/', '', (string)$supplier_id_raw);

// Normalize amount (remove commas and non numeric characters)
$amount_str = str_replace(',', '', (string)$amount_raw);
$amount_str = preg_replace('/[^\d\.\-]/', '', $amount_str);
$amount     = is_numeric($amount_str) ? (float)$amount_str : 0.0;

$method = strtolower(trim((string)$method_raw));
if ($method === '') $method = 'cash';
$allowed_methods = ['cash','bank','wallet','cheque'];

function ensure_supplier_opening_table(PDO $pdo): void {
    static $checked = false;
    if ($checked) {
        return;
    }
    $sql = "CREATE TABLE IF NOT EXISTS supplier_opening_payments (
                id INT AUTO_INCREMENT PRIMARY KEY,
                supplier_id INT NOT NULL,
                branch_id INT DEFAULT NULL,
                method VARCHAR(20) NOT NULL,
                amount DECIMAL(12,2) NOT NULL,
                cheque_number VARCHAR(120) DEFAULT NULL,
                bank_name VARCHAR(150) DEFAULT NULL,
                bank_branch VARCHAR(150) DEFAULT NULL,
                expected_deposit_date DATE DEFAULT NULL,
                deposit_date DATE DEFAULT NULL,
                bank_account_id INT DEFAULT NULL,
                paid_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                created_by INT DEFAULT NULL,
                INDEX idx_supplier_method (supplier_id, method)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    try {
        $pdo->exec($sql);
    } catch (Throwable $ignore) {}
    $checked = true;
}

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

    if ($method === 'cheque') {
        ensure_supplier_opening_table($pdo);
        $paidAt = date('Y-m-d H:i:s');
        $ins = $pdo->prepare(
            "INSERT INTO supplier_opening_payments
                (supplier_id, branch_id, method, amount, cheque_number, bank_name, bank_branch,
                 expected_deposit_date, deposit_date, bank_account_id, paid_at, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)"
        );
        $ins->execute([
            $supplier_id,
            $branchIdContext,
            $method,
            $amount,
            $cheque_number !== '' ? $cheque_number : null,
            $bank_name !== '' ? $bank_name : null,
            $bank_branch !== '' ? $bank_branch : null,
            $expected_deposit_date,
            null,
            $bank_account_id > 0 ? $bank_account_id : null,
            $paidAt,
            $createdByUser
        ]);
    }

    // Record a journal entry to reflect the cash/bank/wallet outflow.  Without this,
    // cash and bank balances in the financial statement will not be affected.
    try {
        $branchId  = $branchIdContext ?? 0;
        $createdBy = $createdByUser ?? 0;
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