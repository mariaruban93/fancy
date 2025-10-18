<?php
require_once 'includes/header.php';
// View purchase details. Only admin, manager, or inventory officer have access.
checkRole(['admin', 'manager', 'inventory_officer']);

// Get purchase ID
$purchaseId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($purchaseId <= 0) {
    echo "<p>Invalid purchase ID.</p>";
    exit;
}

// Fetch purchase information
$stmt = $pdo->prepare("SELECT p.*, b.name AS branch_name, s.name AS supplier_name, u.username AS purchaser_name
    FROM purchases p
    JOIN branches b ON p.branch_id = b.id
    LEFT JOIN suppliers s ON p.supplier_id = s.id
    JOIN users u ON p.purchased_by = u.id
    WHERE p.id = ?");
$stmt->execute([$purchaseId]);
$purchase = $stmt->fetch();
if (!$purchase) {
    echo "<p>Purchase not found.</p>";
    exit;
}

// Restrict access: non-admin cannot view other branch purchases
if ($user['role_name'] !== 'admin' && $purchase['branch_id'] != $user['branch_id']) {
    echo "<p>You do not have permission to view this purchase.</p>";
    exit;
}

// Fetch items associated with this purchase
$stmtItems = $pdo->prepare("SELECT pi.quantity, pi.batch_no, pi.cost_price, pi.selling_price, pi.expiry_date, p.name AS product_name
    FROM purchase_items pi
    JOIN products p ON pi.product_id = p.id
    WHERE pi.purchase_id = ?");
$stmtItems->execute([$purchaseId]);
$items = $stmtItems->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Purchase Details - POS System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- DataTables CSS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
</head>
<body>
<?php include 'includes/nav.php'; ?>
<div class="container">
    <h1 class="mb-4">Purchase Details</h1>
    <a href="purchase_list.php" class="btn btn-secondary mb-3">Back to Purchase List</a>
    <div class="card mb-4">
        <div class="card-body">
            <h5 class="card-title">Purchase #<?php echo $purchase['id']; ?></h5>
            <p><strong>Date:</strong> <?php echo htmlspecialchars($purchase['purchase_date']); ?></p>
            <p><strong>Branch:</strong> <?php echo htmlspecialchars($purchase['branch_name']); ?></p>
            <p><strong>Supplier:</strong> <?php echo htmlspecialchars($purchase['supplier_name'] ?? 'N/A'); ?></p>
            <p><strong>Purchased By:</strong> <?php echo htmlspecialchars($purchase['purchaser_name']); ?></p>
        </div>
    </div>
    <h3>Items</h3>
    <div class="table-responsive">
        <table id="purchaseItemsTable" class="table table-bordered table-striped">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Product</th>
                    <th>Batch No.</th>
                    <th>Quantity</th>
                    <th>Cost Price</th>
                    <th>Selling Price</th>
                    <th>Expiry Date</th>
                </tr>
            </thead>
            <tbody>
                <?php $i = 1; foreach ($items as $it): ?>
                    <tr>
                        <td><?php echo $i++; ?></td>
                        <td><?php echo htmlspecialchars($it['product_name']); ?></td>
                        <td><?php echo htmlspecialchars($it['batch_no']); ?></td>
                        <td><?php echo (int)$it['quantity']; ?></td>
                        <td><?php echo number_format((float)$it['cost_price'], 2); ?></td>
                        <td><?php echo number_format((float)$it['selling_price'], 2); ?></td>
                        <td><?php echo $it['expiry_date'] ? htmlspecialchars($it['expiry_date']) : 'N/A'; ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <a href="purchase_edit.php?id=<?php echo $purchase['id']; ?>" class="btn btn-warning">Edit Purchase</a>
    <a href="purchase_list.php?delete=<?php echo $purchase['id']; ?>" class="btn btn-danger" onclick="return confirm('Are you sure you want to delete this purchase? This will revert the stock.');">Delete Purchase</a>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<!-- jQuery and DataTables JS -->
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script>
  $(document).ready(function() {
    $('#purchaseItemsTable').DataTable();
  });
</script>
</body>
</html>