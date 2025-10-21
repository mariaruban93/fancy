<?php
// ajax_deposit_cheque.php
// Mark a cheque payment as deposited by setting its deposit_date (and optional bank_id).
// IMPORTANT: Do NOT create any new sale_payments row and do NOT change bank_accounts balances here.
// The financial statements will treat deposited cheques as bank automatically.

require_once __DIR__ . '/config/db.php';
session_start();

if (!function_exists('table_exists_cheque')) {
    function table_exists_cheque(PDO $pdo, string $table): bool {
        try {
            $stmt = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table));
            return (bool)$stmt->fetch(PDO::FETCH_NUM);
        } catch (Throwable $e) {
            return false;
        }
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
$deposit_date = isset($_POST['deposit_date'])? trim($_POST['deposit_date']) : '';
$bank_id      = isset($_POST['bank_id'])     ? (int)$_POST['bank_id'] : 0;
$source       = isset($_POST['source']) ? trim((string)$_POST['source']) : 'sale';

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
        if (!table_exists_cheque($pdo, 'opening_customer_payments')) {
            echo json_encode(['status'=>'error','message'=>'Opening cheque table not available']);
            exit;
        }
        $stmt = $pdo->prepare("SELECT ocp.id, ocp.deposit_date, COALESCE(ocp.branch_id, c.branch_id, 0) AS branch_id
                                FROM opening_customer_payments ocp
                                LEFT JOIN customers c ON ocp.customer_id = c.id
                                WHERE ocp.id = ? AND ocp.method = 'cheque'");
        $stmt->execute([$payment_id]);
        $pay = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$pay) {
            echo json_encode(['status'=>'error','message'=>'Cheque payment not found']);
            exit;
        }
        if (!empty($pay['deposit_date'])) {
            echo json_encode(['status'=>'error','message'=>'Cheque already deposited']);
            exit;
        }
        $branchId = (int)$pay['branch_id'];

        $stmtBank = $pdo->prepare("SELECT id, branch_id FROM bank_accounts WHERE id = ?");
        $stmtBank->execute([$bank_id]);
        $bank = $stmtBank->fetch(PDO::FETCH_ASSOC);
        if (!$bank) {
            echo json_encode(['status'=>'error','message'=>'Bank not found']);
            exit;
        }
        $bankBranchId = is_null($bank['branch_id']) ? 0 : (int)$bank['branch_id'];
        if ($bankBranchId > 0 && $branchId > 0 && $bankBranchId !== $branchId) {
            echo json_encode(['status'=>'error','message'=>'Selected bank does not belong to the cheque\'s branch']);
            exit;
        }

        $upd = $pdo->prepare("UPDATE opening_customer_payments SET deposit_date = ?, deposit_bank_id = ?, status = 'deposited' WHERE id = ?");
        $upd->execute([$deposit_date, $bank_id, $payment_id]);
    } else {
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
    }

    echo json_encode(['status'=>'success']);
} catch (Throwable $e) {
    echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
}
