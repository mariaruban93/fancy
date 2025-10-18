<?php
// ajax_deposit_cheque.php
// Mark a cheque payment as deposited by setting its deposit_date (and optional bank_id).
// IMPORTANT: Do NOT create any new sale_payments row and do NOT change bank_accounts balances here.
// The financial statements will treat deposited cheques as bank automatically.

require_once __DIR__ . '/config/db.php';
session_start();

function table_exists(PDO $pdo, string $table): bool {
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE ".$pdo->quote($table));
        return (bool)($stmt && $stmt->fetchColumn());
    } catch (Throwable $e) {
        return false;
    }
}

function col_exists(PDO $pdo, string $table, string $col): bool {
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `$table` LIKE ".$pdo->quote($col));
        return (bool)($stmt && $stmt->fetch(PDO::FETCH_ASSOC));
    } catch (Throwable $e) {
        return false;
    }
}

// Minimal role check similar to checkRole() but JSON-friendly
function userHasRoleForDeposit(array $roles) {
    global $pdo;
    if (!isset($_SESSION['user_id'])) return false;
    $stmt = $pdo->prepare("SELECT r.name FROM users u JOIN roles r ON u.role_id=r.id WHERE u.id=?");
    $stmt->execute([$_SESSION['user_id']]);
    $role = $stmt->fetchColumn();
    return in_array($role, $roles, true);
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method']);
    exit;
}

if (!userHasRoleForDeposit(['admin','manager'])) {
    echo json_encode(['status' => 'error', 'message' => 'Not authorised']);
    exit;
}

// Inputs
$payment_id   = isset($_POST['payment_id'])  ? (int)$_POST['payment_id'] : 0;
$source_raw   = isset($_POST['source'])      ? strtolower(trim((string)$_POST['source'])) : 'sale';
$deposit_date = isset($_POST['deposit_date'])? trim($_POST['deposit_date']) : '';
$bank_id      = isset($_POST['bank_id'])     ? (int)$_POST['bank_id'] : 0;

$source = in_array($source_raw, ['sale','opening'], true) ? $source_raw : 'sale';

if ($payment_id <= 0 || $deposit_date === '' || $bank_id <= 0) {
    echo json_encode(['status'=>'error','message'=>'Invalid parameters']);
    exit;
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $deposit_date)) {
    echo json_encode(['status'=>'error','message'=>'Invalid date format']);
    exit;
}

try {
    if ($source === 'opening') {
        if (!table_exists($pdo, 'customer_opening_payments')) {
            echo json_encode(['status'=>'error','message'=>'Opening payment tracking table missing']);
            exit;
        }
        $branchCol = col_exists($pdo, 'customer_opening_payments', 'branch_id');
        $bankCol   = col_exists($pdo, 'customer_opening_payments', 'bank_account_id');

        $stmt = $pdo->prepare("SELECT id, method, deposit_date" . ($branchCol ? ', branch_id' : '') . " FROM customer_opening_payments WHERE id = ?");
        $stmt->execute([$payment_id]);
        $pay = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$pay || strtolower((string)$pay['method']) !== 'cheque') {
            echo json_encode(['status'=>'error','message'=>'Cheque payment not found']);
            exit;
        }
        if (!empty($pay['deposit_date']) && $pay['deposit_date'] !== '0000-00-00') {
            echo json_encode(['status'=>'error','message'=>'Cheque already deposited']);
            exit;
        }
        $paymentBranch = $branchCol ? (int)$pay['branch_id'] : 0;

        $stmtBank = $pdo->prepare("SELECT id, branch_id FROM bank_accounts WHERE id = ?");
        $stmtBank->execute([$bank_id]);
        $bank = $stmtBank->fetch(PDO::FETCH_ASSOC);
        if (!$bank) {
            echo json_encode(['status'=>'error','message'=>'Bank not found']);
            exit;
        }
        $bankBranchId = is_null($bank['branch_id']) ? 0 : (int)$bank['branch_id'];
        if ($paymentBranch > 0 && $bankBranchId > 0 && $paymentBranch !== $bankBranchId) {
            echo json_encode(['status'=>'error','message'=>'Selected bank does not belong to the cheque\'s branch']);
            exit;
        }

        $sets = ['deposit_date = :dd'];
        $params = [':dd' => $deposit_date, ':id' => $payment_id];
        if ($bankCol) {
            $sets[] = 'bank_account_id = :ba';
            $params[':ba'] = $bank_id;
        }
        $sql = "UPDATE customer_opening_payments SET " . implode(', ', $sets) . " WHERE id = :id";
        $upd = $pdo->prepare($sql);
        $upd->execute($params);

        echo json_encode(['status'=>'success']);
        exit;
    }

    // Load cheque payment; ensure not already deposited
    $stmt = $pdo->prepare("
        SELECT sp.id, sp.amount, sp.method, sp.deposit_date, s.branch_id
        FROM sale_payments sp
        JOIN sales s ON s.id = sp.sale_id
        WHERE sp.id = ? AND sp.method = 'cheque'
    ");
    $stmt->execute([$payment_id]);
    $pay = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$pay) {
        echo json_encode(['status'=>'error', 'message'=>'Cheque payment not found']);
        exit;
    }
    if (!empty($pay['deposit_date'])) {
        echo json_encode(['status'=>'error', 'message'=>'Cheque already deposited']);
        exit;
    }

    $saleBranchId = (int)$pay['branch_id'];

    // Validate bank exists (and, if your policy requires, matches the sale’s branch or is global)
    $stmtBank = $pdo->prepare("SELECT id, branch_id FROM bank_accounts WHERE id = ?");
    $stmtBank->execute([$bank_id]);
    $bank = $stmtBank->fetch(PDO::FETCH_ASSOC);
    if (!$bank) {
        echo json_encode(['status'=>'error','message'=>'Bank not found']);
        exit;
    }
    // Allow bank accounts that are global (NULL) or that match the sale’s branch
    $bankBranchId = is_null($bank['branch_id']) ? 0 : (int)$bank['branch_id'];
    if ($bankBranchId > 0 && $bankBranchId !== $saleBranchId) {
        echo json_encode(['status'=>'error','message'=>'Selected bank does not belong to the sale\'s branch']);
        exit;
    }

    // Check if sale_payments has a bank_id column
    $hasBankId = false;
    try {
        $probe = $pdo->query("SHOW COLUMNS FROM sale_payments LIKE 'bank_id'");
        $hasBankId = (bool)$probe->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { $hasBankId = false; }

    // Update ONLY the existing sale_payments row. No inserts, no bank balance updates.
    if ($hasBankId) {
        $upd = $pdo->prepare("UPDATE sale_payments SET deposit_date = ?, bank_id = ? WHERE id = ?");
        $upd->execute([$deposit_date, $bank_id, $payment_id]);
    } else {
        $upd = $pdo->prepare("UPDATE sale_payments SET deposit_date = ? WHERE id = ?");
        $upd->execute([$deposit_date, $payment_id]);
    }

    echo json_encode(['status'=>'success']);
} catch (Throwable $e) {
    echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
}
