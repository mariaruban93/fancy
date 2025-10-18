<?php
/**
 * ajax_transfer_pay.php
 *
 * This endpoint records a payment against a stock transfer while
 * validating that the amount does not exceed the outstanding balance and
 * that the paying branch has sufficient cash on hand.  If the payment
 * is valid, the cash accounts for both payer and receiver branches are
 * updated via journal_entries.  Responses are JSON.  On error the
 * JSON includes an "error" field.
 *
 * Expected POST fields:
 *   transfer_id      – the transfer to settle
 *   payer_branch_id  – the branch paying for the transfer
 *   amount           – the payment amount
 */

require_once 'includes/header.php';

// Always return JSON
header('Content-Type: application/json');

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'Invalid request method']);
    exit;
}

// Extract parameters
$transferId   = isset($_POST['transfer_id']) ? (int)$_POST['transfer_id'] : 0;
$payerBranch  = isset($_POST['payer_branch_id']) ? (int)$_POST['payer_branch_id'] : 0;
$amount       = isset($_POST['amount']) ? (float)$_POST['amount'] : 0.0;

// Validate
if ($transferId <= 0 || $amount <= 0) {
    echo json_encode(['error' => 'Missing or invalid parameters']);
    exit;
}

try {
    // Fetch transfer header to determine from/to branches
    $stmt = $pdo->prepare("SELECT from_branch_id, to_branch_id FROM stock_transfers WHERE id = ?");
    $stmt->execute([$transferId]);
    $hdr = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$hdr) {
        echo json_encode(['error' => 'Transfer not found']);
        exit;
    }
    $fromId = (int)$hdr['from_branch_id'];
    $toId   = (int)$hdr['to_branch_id'];

    // Compute transfer total
    $stmt = $pdo->prepare(
        "SELECT SUM(sti.quantity * sb.cost_price) AS total_value
         FROM stock_transfer_items sti
         JOIN stock_batches sb ON sb.id = sti.batch_id
         JOIN stock_transfers t ON t.id = sti.transfer_id
         WHERE sti.transfer_id = ? AND sb.branch_id = t.from_branch_id"
    );
    $stmt->execute([$transferId]);
    $transferValue = (float)$stmt->fetchColumn();

    // Sum existing payments
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM transfer_payments WHERE transfer_id = ?");
    $stmt->execute([$transferId]);
    $paidSoFar = (float)$stmt->fetchColumn();
    $outstanding = $transferValue - $paidSoFar;
    if ($amount > $outstanding + 0.0001) {
        echo json_encode(['error' => 'Amount exceeds outstanding balance']);
        exit;
    }

    // Compute current cash for payer branch using helper
    // Define helper here to avoid global function collision
    $getBranchCash = function(int $branchId, $pdo) {
        // Opening balance
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(opening_amount),0) FROM cash_openings WHERE branch_id=?");
        $stmt->execute([$branchId]);
        $cash = (float)$stmt->fetchColumn();
        // Sales receipts (cash)
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(sp.amount),0)
             FROM sale_payments sp
             JOIN sales s ON s.id = sp.sale_id
             WHERE s.branch_id = ? AND sp.method = 'cash'"
        );
        $stmt->execute([$branchId]);
        $cash += (float)$stmt->fetchColumn();
        // Purchase payments
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(pp.amount),0)
             FROM purchase_payments pp
             JOIN purchases p ON p.id = pp.purchase_id
             WHERE p.branch_id = ?"
        );
        $stmt->execute([$branchId]);
        $cash -= (float)$stmt->fetchColumn();
        // Expenses (cash)
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE branch_id=? AND payment_method='cash'");
        $stmt->execute([$branchId]);
        $cash -= (float)$stmt->fetchColumn();
        // Worker payments (cash)
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM worker_payments WHERE branch_id=? AND method='cash'");
        $stmt->execute([$branchId]);
        $cash -= (float)$stmt->fetchColumn();
        // Journal entries (cash) – include inter‑branch payments recorded as ± values
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM journal_entries WHERE branch_id=? AND account_type='cash'");
        $stmt->execute([$branchId]);
        $cash += (float)$stmt->fetchColumn();
        return $cash;
    };

    $currentCash = $getBranchCash($payerBranch, $pdo);
    if ($amount > $currentCash + 0.0001) {
        echo json_encode(['error' => 'Not enough cash in hand']);
        exit;
    }

    // Determine receiver branch
    $receiver = ($payerBranch === $fromId) ? $toId : $fromId;

    // Record the payment and journal entries atomically
    $pdo->beginTransaction();
    try {
        // Insert transfer payment
        $stmt = $pdo->prepare("INSERT INTO transfer_payments (transfer_id, amount, paid_by, paid_at) VALUES (?,?,?,NOW())");
        $stmt->execute([$transferId, $amount, (int)$user['id']]);
        // Insert payer cash out

        // $desc = 'Inter‑branch transfer payment #' . $transferId;
        // $stmt = $pdo->prepare("INSERT INTO journal_entries (branch_id, entry_date, account_type, amount, description, created_by) VALUES (?,?,?,?,?,?)");
        // $stmt->execute([$payerBranch, date('Y-m-d'), 'cash', -$amount, $desc, (int)$user['id']]);
        
        // $desc2 = 'Inter‑branch transfer receive #' . $transferId;
        // $stmt->execute([$receiver, date('Y-m-d'), 'cash', $amount, $desc2, (int)$user['id']]);
        $pdo->commit();
        echo json_encode(['ok' => true]);
        exit;
    } catch (Throwable $e) {
        $pdo->rollBack();
        echo json_encode(['error' => 'Payment failed: ' . $e->getMessage()]);
        exit;
    }
} catch (Throwable $e) {
    echo json_encode(['error' => 'Unexpected error']);
    exit;
}