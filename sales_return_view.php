<?php
/**
 * sales_return_view.php
 *
 * Displays detailed information about a single sale return, including
 * the return header and line items.  Restricted to admin and manager roles.
 */
require_once 'includes/header.php';
checkRole(['admin','manager']);

$return_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($return_id <= 0) {
    echo '<p>Invalid return ID.</p>';
    exit;
}

// Fetch the return record with branch and user info
$stmt = $pdo->prepare("SELECT sr.*, b.name AS branch_name, u.username AS processed_by, s.sale_date
                       FROM sale_returns sr
                       JOIN branches b ON sr.branch_id=b.id
                       JOIN users u ON sr.processed_by=u.id
                       JOIN sales s ON sr.sale_id = s.id
                       WHERE sr.id = ?");
$stmt->execute([$return_id]);
$ret = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$ret) {
    echo '<p>Return not found.</p>';
    exit;
}
// Manager should only view their branch
if ($user['role_name'] === 'manager' && (int)$ret['branch_id'] !== (int)$user['branch_id']) {
    echo '<p>You do not have permission to view this return.</p>';
    exit;
}
// Fetch return items
$itemStmt = $pdo->prepare("SELECT sri.product_id, sri.batch_id, sri.quantity, sri.unit_price, sri.line_total,
                                  p.name AS product_name, sb.batch_no
                           FROM sale_return_items sri
                           JOIN products p ON p.id = sri.product_id
                           JOIN stock_batches sb ON sb.id = sri.batch_id
                           WHERE sri.sale_return_id = ?");
$itemStmt->execute([$return_id]);
$items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Sale Return #<?= htmlspecialchars($ret['id']) ?> - POS System</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
</head>
<body>
<?php include 'includes/nav.php'; ?>
<div class="container mt-4">
    <h3 class="mb-3">Sale Return #<?= htmlspecialchars($ret['id']) ?> Details</h3>
    <div class="mb-3">
        <strong>Return Date:</strong> <?= htmlspecialchars($ret['return_date']) ?><br>
        <strong>Branch:</strong> <?= htmlspecialchars($ret['branch_name']) ?><br>
        <strong>Processed By:</strong> <?= htmlspecialchars($ret['processed_by']) ?><br>
        <strong>Original Sale ID:</strong> <?= htmlspecialchars($ret['sale_id']) ?><br>
        <strong>Original Sale Date:</strong> <?= htmlspecialchars($ret['sale_date']) ?><br>
        <strong>Return Type:</strong> <?= htmlspecialchars($ret['return_type']) ?><br>
        <strong>Total Refund:</strong> Rs. <?= number_format($ret['total_refund'],2) ?><br>
        <?php
        // Show cash received from customer and change given when available.
        if (isset($ret['amount_received']) && (float)$ret['amount_received'] > 0): ?>
            <strong>Amount Received:</strong> Rs. <?= number_format($ret['amount_received'],2) ?><br>
        <?php endif; ?>
        <?php if (isset($ret['change_given']) && (float)$ret['change_given'] > 0): ?>
            <strong>Change Given:</strong> Rs. <?= number_format($ret['change_given'],2) ?><br>
        <?php endif; ?>
        <?php if ($ret['remarks']): ?>
            <strong>Remarks:</strong> <?= htmlspecialchars($ret['remarks']) ?><br>
        <?php endif; ?>
    </div>
    <h5>Returned Items</h5>
    <table class="table table-bordered">
        <thead class="table-light">
        <tr>
            <th>Product</th>
            <th>Batch</th>
            <th>Quantity</th>
            <th>Unit Price</th>
            <th>Total</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($items as $it): ?>
            <tr>
                <td><?= htmlspecialchars($it['product_name']) ?></td>
                <td><?= htmlspecialchars($it['batch_no']) ?></td>
                <td><?= htmlspecialchars($it['quantity']) ?></td>
                <td><?= number_format($it['unit_price'],2) ?></td>
                <td><?= number_format($it['line_total'],2) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <a href="sales_return_list.php" class="btn btn-secondary">Back to Return List</a>
</div>
</body>
</html>