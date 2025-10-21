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

function col_exists(PDO $pdo, string $table, string $column): bool {
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$column]);
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return false;
    }
}

function ensure_opening_customer_cheques(PDO $pdo): void {
    $sql = "CREATE TABLE IF NOT EXISTS opening_customer_payments (
                id INT AUTO_INCREMENT PRIMARY KEY,
                customer_id INT NOT NULL,
                branch_id INT DEFAULT 0,
                amount DECIMAL(18,2) NOT NULL,
                method VARCHAR(20) NOT NULL DEFAULT 'cheque',
                cheque_number VARCHAR(120) DEFAULT NULL,
                bank_name VARCHAR(191) DEFAULT NULL,
                bank_branch VARCHAR(191) DEFAULT NULL,
                deposit_date DATE DEFAULT NULL,
                deposit_bank_id INT DEFAULT NULL,
                transfer_date DATE DEFAULT NULL,
                received_on DATE DEFAULT NULL,
                created_by INT DEFAULT NULL,
                created_at DATETIME NOT NULL,
                status VARCHAR(40) DEFAULT 'pending'
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $pdo->exec($sql);
}

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
$transfer_date  = isset($_POST['transfer_date']) ? trim((string)$_POST['transfer_date']) : '';

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
        if ($method === 'cheque') {
            if ($deposit_date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $deposit_date)) {
                throw new RuntimeException('Invalid deposit date');
            }
            if ($transfer_date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $transfer_date)) {
                throw new RuntimeException('Invalid transfer date');
            }
        }
    }

    // Ensure opening_balance column exists
    $colCheck = $pdo->query("SHOW COLUMNS FROM customers LIKE 'opening_balance'");
    if (!$colCheck || !$colCheck->fetch()) {
        throw new RuntimeException('Opening balance not supported on customers table');
    }
    // Fetch current opening balance
    $hasCustBranch = col_exists($pdo, 'customers', 'branch_id');
    $cols = 'opening_balance';
    if ($hasCustBranch) {
        $cols .= ', branch_id';
    }
    $stmt = $pdo->prepare("SELECT $cols FROM customers WHERE id=?");
    $stmt->execute([$customer_id]);
    $custRow = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$custRow) {
        throw new RuntimeException('Customer not found');
    }
    $current = (float)($custRow['opening_balance'] ?? 0.0);
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
        ensure_opening_customer_cheques($pdo);
        global $user;
        $branchForCheque = 0;
        if ($hasCustBranch) {
            $branchForCheque = (int)($custRow['branch_id'] ?? 0);
        }
        if ($branchForCheque <= 0 && isset($user['branch_id']) && (int)$user['branch_id'] > 0) {
            $branchForCheque = (int)$user['branch_id'];
        }
        $createdBy = isset($user['id']) ? (int)$user['id'] : null;
        $createdAt = date('Y-m-d H:i:s');
        $depositDt = ($deposit_date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $deposit_date)) ? $deposit_date : null;
        $transferDt = ($transfer_date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $transfer_date)) ? $transfer_date : null;
        $ins = $pdo->prepare("INSERT INTO opening_customer_payments (customer_id, branch_id, amount, method, cheque_number, bank_name, bank_branch, deposit_date, transfer_date, received_on, created_by, created_at)
                               VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
        $ins->execute([
            $customer_id,
            $branchForCheque,
            $amount,
            'cheque',
            $cheque_number !== '' ? $cheque_number : null,
            $bank_name,
            $bank_branch !== '' ? $bank_branch : null,
            $depositDt,
            $transferDt,
            date('Y-m-d'),
            $createdBy,
            $createdAt
        ]);
    }

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
        elseif ($method === 'cheque') $accType = 'cheque_in_hand';
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