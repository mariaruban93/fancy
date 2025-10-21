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

function col_exists(PDO $pdo, string $table, string $column): bool {
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$column]);
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return false;
    }
}

function ensure_supplier_opening_cheques(PDO $pdo): void {
    $sql = "CREATE TABLE IF NOT EXISTS opening_supplier_payments (
                id INT AUTO_INCREMENT PRIMARY KEY,
                supplier_id INT NOT NULL,
                branch_id INT DEFAULT 0,
                amount DECIMAL(18,2) NOT NULL,
                method VARCHAR(20) NOT NULL DEFAULT 'cheque',
                cheque_number VARCHAR(120) DEFAULT NULL,
                bank_name VARCHAR(191) DEFAULT NULL,
                bank_branch VARCHAR(191) DEFAULT NULL,
                issued_on DATE DEFAULT NULL,
                deposit_date DATE DEFAULT NULL,
                deposit_bank_id INT DEFAULT NULL,
                created_by INT DEFAULT NULL,
                created_at DATETIME NOT NULL,
                status VARCHAR(40) DEFAULT 'issued'
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $pdo->exec($sql);
}

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

$cheque_number  = isset($_POST['cheque_number']) ? trim((string)$_POST['cheque_number']) : '';
$bank_name      = isset($_POST['bank_name']) ? trim((string)$_POST['bank_name']) : '';
$bank_branch    = isset($_POST['bank_branch']) ? trim((string)$_POST['bank_branch']) : '';
$issued_on      = isset($_POST['issued_on']) ? trim((string)$_POST['issued_on']) : '';
$bank_account_id = isset($_POST['bank_account_id']) ? (int)$_POST['bank_account_id'] : 0;

if ($supplier_id <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Missing supplier_id']);
    exit;
}
if ($amount <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Amount must be greater than zero']);
    exit;
}
if ($method === 'bank') {
    if ($bank_account_id <= 0 && $bank_name === '') {
        echo json_encode(['status' => 'error', 'message' => 'Select a bank for bank payment']);
        exit;
    }
}
if ($method === 'cheque') {
    if ($bank_name === '') {
        echo json_encode(['status' => 'error', 'message' => 'Provide bank name for cheque payment']);
        exit;
    }
    if ($issued_on !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $issued_on)) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid cheque issue date']);
        exit;
    }
}

try {
    // Ensure the suppliers table has an opening_balance column
    $colCheck = $pdo->query("SHOW COLUMNS FROM suppliers LIKE 'opening_balance'");
    if (!$colCheck || !$colCheck->fetch()) {
        throw new RuntimeException('Opening balance not supported on suppliers table');
    }
    // Fetch current opening balance for the supplier
    $hasSupplierBranch = col_exists($pdo, 'suppliers', 'branch_id');
    $cols = 'opening_balance';
    if ($hasSupplierBranch) {
        $cols .= ', branch_id';
    }
    $stmt = $pdo->prepare("SELECT $cols FROM suppliers WHERE id=?");
    $stmt->execute([$supplier_id]);
    $supplierRow = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$supplierRow) {
        throw new RuntimeException('Supplier not found');
    }
    $current = (float)($supplierRow['opening_balance'] ?? 0.0);
    if ($current <= 0) {
        throw new RuntimeException('No outstanding opening balance');
    }
    if ($amount > $current) {
        throw new RuntimeException('Payment exceeds outstanding opening balance');
    }
    $supplierBranchId = 0;
    if ($hasSupplierBranch) {
        $supplierBranchId = (int)($supplierRow['branch_id'] ?? 0);
    }
    // Deduct the amount from opening balance
    $upd = $pdo->prepare("UPDATE suppliers SET opening_balance = opening_balance - ? WHERE id=?");
    $upd->execute([$amount, $supplier_id]);

    if ($method === 'cheque') {
        ensure_supplier_opening_cheques($pdo);
        global $user;
        $branchForCheque = $supplierBranchId > 0 ? $supplierBranchId : ((isset($user['branch_id']) && (int)$user['branch_id'] > 0) ? (int)$user['branch_id'] : 0);
        $createdBy = isset($user['id']) ? (int)$user['id'] : null;
        $createdAt = date('Y-m-d H:i:s');
        $issuedDate = ($issued_on !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $issued_on)) ? $issued_on : null;
        $ins = $pdo->prepare("INSERT INTO opening_supplier_payments (supplier_id, branch_id, amount, method, cheque_number, bank_name, bank_branch, issued_on, created_by, created_at)
                               VALUES (?,?,?,?,?,?,?,?,?,?)");
        $ins->execute([
            $supplier_id,
            $branchForCheque,
            $amount,
            'cheque',
            $cheque_number !== '' ? $cheque_number : null,
            $bank_name,
            $bank_branch !== '' ? $bank_branch : null,
            $issuedDate,
            $createdBy,
            $createdAt
        ]);
    }

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
        $jeAmount = -1 * $amount;
        if ($method === 'bank') {
            $accType = 'bank';
        } elseif ($method === 'wallet') {
            $accType = 'wallet';
        } elseif ($method === 'cheque') {
            $accType = 'cheque_payable';
            $jeAmount = $amount;
        }
        $desc = 'Supplier opening payment ('.$method.')';
        $je = $pdo->prepare("INSERT INTO journal_entries (branch_id, entry_date, account_type, amount, description, created_by, created_at) VALUES (?,?,?,?,?,?,?)");
        $je->execute([$branchId, $entryDate, $accType, $jeAmount, $desc, $createdBy, $createdAt]);
    } catch (Throwable $jex) {
        // If journal entry fails we still continue but log error
    }
    echo json_encode(['status' => 'success']);
} catch (Throwable $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}