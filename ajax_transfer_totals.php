<?php
/**
 * ajax_transfer_totals.php
 *
 * Compute and return summary information for a stock transfer given the
 * transfer ID and a selected payer branch.  The response includes the
 * total transfer value (sum of quantity * cost_price from the sending
 * branch), the total amount already paid against the transfer, the
 * outstanding balance, the source and destination branch IDs, and a
 * human‑readable label indicating whether the selected branch owes
 * money (Payable) or is owed money (Receivable).
 *
 * This endpoint expects two GET parameters:
 *   transfer_id       – the numeric ID of the stock_transfers row
 *   payer_branch_id   – the numeric branch ID that will settle the cost
 *
 * Returns JSON.  On error, a JSON object with an "error" field is
 * returned instead of the normal data structure.
 */

require_once 'includes/header.php';

// Always return JSON
header('Content-Type: application/json');

// Extract and validate parameters
$transferId  = isset($_GET['transfer_id']) ? (int)$_GET['transfer_id'] : 0;
$payerBranch = isset($_GET['payer_branch_id']) ? (int)$_GET['payer_branch_id'] : 0;

if ($transferId <= 0) {
    echo json_encode(['error' => 'Missing or invalid transfer_id']);
    exit;
}

try {
    // Fetch the transfer header to get from/to branch IDs
    $stmt = $pdo->prepare("SELECT id, from_branch_id, to_branch_id FROM stock_transfers WHERE id = ?");
    $stmt->execute([$transferId]);
    $hdr = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$hdr) {
        echo json_encode(['error' => 'Transfer not found']);
        exit;
    }

    // Compute total transfer value at source branch cost.  We join
    // stock_transfer_items to stock_batches and ensure we only take
    // batches belonging to the sending branch.  Without this filter
    // there is a risk of including cost from a previous destination batch.
    $stmt = $pdo->prepare(
        "SELECT SUM(sti.quantity * sb.cost_price) AS total_value
         FROM stock_transfer_items sti
         JOIN stock_batches sb ON sb.id = sti.batch_id
         JOIN stock_transfers t ON t.id = sti.transfer_id
         WHERE sti.transfer_id = ? AND sb.branch_id = t.from_branch_id"
    );
    $stmt->execute([$transferId]);
    $totalValue = (float)$stmt->fetchColumn();

    // Sum all payments recorded for this transfer
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM transfer_payments WHERE transfer_id = ?");
    $stmt->execute([$transferId]);
    $totalPaid = (float)$stmt->fetchColumn();

    // Compute outstanding balance
    $balance = $totalValue - $totalPaid;
    if ($balance < 0) {
        $balance = 0;
    }

    // Determine direction label
    //
    // In a stock transfer the receiving branch (to_branch) owes money to
    // the sending branch (from_branch).  The payer branch is the one
    // actually handing over cash.  Therefore if the selected payer is
    // the receiving branch (to_branch), this is a **payment** and is
    // labelled Payable (Cash OUT).  Conversely if the selected payer is
    // the sending branch (from_branch), this is a **receipt** of money
    // owed by the other branch and is labelled Receivable (Cash IN).
    $direction = '';
    if ($payerBranch === (int)$hdr['to_branch_id']) {
        // Receiving branch pays the sender
        $direction = 'Payable (Cash OUT)';
    } elseif ($payerBranch === (int)$hdr['from_branch_id']) {
        // Sending branch records receipt from the receiver
        $direction = 'Receivable (Cash IN)';
    } else {
        // Default to payable when payer doesn't match either branch
        $direction = 'Payable (Cash OUT)';
    }

    echo json_encode([
        'transfer_value' => number_format($totalValue, 2, '.', ''),
        'paid'           => number_format($totalPaid, 2, '.', ''),
        'balance'        => number_format($balance, 2, '.', ''),
        'from_branch_id' => (int)$hdr['from_branch_id'],
        'to_branch_id'   => (int)$hdr['to_branch_id'],
        'direction'      => $direction
    ]);
    exit;
} catch (Throwable $e) {
    // Generic error handler
    echo json_encode(['error' => 'Failed to compute transfer totals']);
    exit;
}