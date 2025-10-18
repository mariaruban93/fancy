<?php
/**
 * ajax_delete_return.php
 *
 * Handles deletion of sale return transactions via AJAX.  Only admin
 * and manager roles are permitted to delete returns.  When a return
 * is deleted, the quantities previously added back to stock are
 * subtracted again.  The corresponding sale_return and items are
 * removed.  A JSON response is returned.
 */
session_start();
require_once 'config/db.php';
require_once 'includes/functions.php';

header('Content-Type: application/json');
if (!isLoggedIn()) {
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
    exit;
}
$user = getUser();
// Only admin or manager can delete
if (!in_array($user['role_name'], ['admin','manager'])) {
    echo json_encode(['status' => 'error', 'message' => 'Permission denied']);
    exit;
}
$return_id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
if ($return_id <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid return ID']);
    exit;
}

try {
    // Fetch return header
    $stmt = $pdo->prepare("SELECT * FROM sale_returns WHERE id = ?");
    $stmt->execute([$return_id]);
    $ret = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$ret) {
        echo json_encode(['status' => 'error', 'message' => 'Return not found']);
        exit;
    }
    // Manager can only delete own branch
    if ($user['role_name'] === 'manager' && (int)$ret['branch_id'] !== (int)$user['branch_id']) {
        echo json_encode(['status' => 'error', 'message' => 'Permission denied']);
        exit;
    }
    $pdo->beginTransaction();
    // Get line items for this return
    $itemStmt = $pdo->prepare("SELECT batch_id, quantity FROM sale_return_items WHERE sale_return_id = ?");
    $itemStmt->execute([$return_id]);
    $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
    // Subtract quantities from stock (revert the increase done by the return)
    $updateStmt = $pdo->prepare("UPDATE stock_batches SET quantity = quantity - ? WHERE id = ?");
    foreach ($items as $it) {
        $updateStmt->execute([$it['quantity'], $it['batch_id']]);
    }
    // Delete return items and return record (sale_return_items has ON DELETE CASCADE if configured)
    $delItems = $pdo->prepare("DELETE FROM sale_return_items WHERE sale_return_id = ?");
    $delItems->execute([$return_id]);
    $delRet = $pdo->prepare("DELETE FROM sale_returns WHERE id = ?");
    $delRet->execute([$return_id]);
    // Log activity
    try {
        logActivity($pdo, $user['id'], 'Sale Return', 'Deleted return #' . $return_id, $ret['branch_id']);
    } catch (Throwable $ex) {
        // ignore logging errors
    }
    $pdo->commit();
    echo json_encode(['status' => 'success']);
} catch (Throwable $ex) {
    $pdo->rollBack();
    echo json_encode(['status' => 'error', 'message' => $ex->getMessage()]);
}