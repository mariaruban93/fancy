<?php
require_once 'includes/header.php';
// Inventory list by batch. Only admin can view all branches, managers/inventory officers see their own branch.
checkRole(['admin', 'manager', 'inventory_officer']);

// Determine branch filter for admin
$branchFilter = '';
if ($user['role_name'] === 'admin') {
    $branchFilter = isset($_GET['branch_id']) ? trim($_GET['branch_id']) : '';
    // Fetch branch options for filter dropdown
    $branchOptions = $pdo->query("SELECT id, name FROM branches ORDER BY name")->fetchAll();
}

// Build query for stock batches
$query = "SELECT sb.id, sb.product_id, sb.branch_id, sb.batch_no, sb.quantity, sb.cost_price, sb.selling_price, sb.expiry_date, "
        . "p.name AS product_name, b.name AS branch_name "
        . "FROM stock_batches sb "
        . "JOIN products p ON sb.product_id = p.id "
        . "JOIN branches b ON sb.branch_id = b.id "
        . "WHERE sb.quantity > 0";
$params = [];
// Apply branch restrictions based on role
if ($user['role_name'] === 'admin') {
    if ($branchFilter !== '') {
        $query .= " AND sb.branch_id = ?";
        $params[] = $branchFilter;
    }
} else {
    // Non-admin users can only view their own branch inventory
    $query .= " AND sb.branch_id = ?";
    $params[] = $user['branch_id'];
}
$query .= " ORDER BY b.name, p.name, sb.batch_no";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$stocks = $stmt->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory - POS System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- DataTables CSS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
</head>
<body>
<?php include 'includes/nav.php'; ?>
<div class="container">
    <h1 class="mb-4">Inventory (Batch Wise)</h1>
    <?php if ($user['role_name'] === 'admin'): ?>
    <form method="get" class="row g-3 mb-4">
        <div class="col-md-4">
            <label class="form-label">Branch</label>
            <select name="branch_id" class="form-select" onchange="this.form.submit()">
                <option value="">All</option>
                <?php foreach ($branchOptions as $br): ?>
                    <option value="<?php echo $br['id']; ?>" <?php echo ($branchFilter == $br['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($br['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>
    <?php endif; ?>
    <div class="table-responsive">
        <table id="inventoryTable" class="table table-bordered table-striped">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Branch</th>
                    <th>Product</th>
                    <th>Batch No.</th>
                    <th>Quantity</th>
                    <th>Cost Price</th>
                    <th>Selling Price</th>
                    <th>Expiry Date</th>
                </tr>
            </thead>
            <tbody>
                <?php $i = 1; foreach ($stocks as $st): ?>
                    <tr>
                        <td><?php echo $i++; ?></td>
                        <td><?php echo htmlspecialchars($st['branch_name']); ?></td>
                        <td><?php echo htmlspecialchars($st['product_name']); ?></td>
                        <td><?php echo htmlspecialchars($st['batch_no']); ?></td>
                        <td><?php echo (int)$st['quantity']; ?></td>
                        <td><?php echo number_format((float)$st['cost_price'], 2); ?></td>
                        <td><?php echo number_format((float)$st['selling_price'], 2); ?></td>
                        <td><?php echo $st['expiry_date'] ? htmlspecialchars($st['expiry_date']) : 'N/A'; ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<!-- jQuery and DataTables JS -->
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script>
  $(document).ready(function() {
    $('#inventoryTable').DataTable();
  });
</script>
</body>
</html>