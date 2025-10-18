<?php
require_once 'includes/header.php';
// Only admin, manager, or inventory officer can access purchase list
checkRole(['admin', 'manager', 'inventory_officer']);

$message = '';

// Handle deletion via GET parameter (purchase_id)
if (isset($_GET['delete'])) {
    $delId = (int)$_GET['delete'];
    if ($delId > 0) {
        try {
            $pdo->beginTransaction();
            // Fetch purchase and items
            $stmt = $pdo->prepare("SELECT branch_id FROM purchases WHERE id = ?");
            $stmt->execute([$delId]);
            $purchase = $stmt->fetch();
            if ($purchase) {
                $branchId = (int)$purchase['branch_id'];
                // Fetch items to reverse stock
                $items = $pdo->prepare("SELECT product_id, batch_no, quantity, cost_price, selling_price, expiry_date FROM purchase_items WHERE purchase_id = ?");
                $items->execute([$delId]);
                foreach ($items as $it) {
                    $pid   = (int)$it['product_id'];
                    $batch = trim($it['batch_no'] ?? '');
                    $qty   = (int)$it['quantity'];
                    // Reduce quantity from stock_batches
                    $upd = $pdo->prepare("UPDATE stock_batches SET quantity = quantity - ? WHERE product_id = ? AND branch_id = ? AND batch_no = ?");
                    $upd->execute([$qty, $pid, $branchId, $batch]);
                }
                // Delete purchase items and purchase
                $delItems = $pdo->prepare("DELETE FROM purchase_items WHERE purchase_id = ?");
                $delItems->execute([$delId]);
                $delPur = $pdo->prepare("DELETE FROM purchases WHERE id = ?");
                $delPur->execute([$delId]);
                    $pdo->commit();
                    $message = 'Purchase deleted and stock reverted.';
                    // Log deletion activity for purchases
                    try {
                        logActivity($pdo, $user['id'], 'Purchase', 'Deleted purchase #' . $delId . ' and reverted stock', $branchId);
                    } catch (Throwable $logErr) {
                        // Suppress any logging errors
                    }
            } else {
                $pdo->rollBack();
                $message = 'Purchase not found.';
            }
        } catch (Throwable $e) {
            $pdo->rollBack();
            $message = 'Error deleting purchase: ' . $e->getMessage();
        }
    }
}

// Filters: date range and branch (admin only)
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date   = $_GET['end_date'] ?? date('Y-m-d');
$branch_filter = $_GET['branch_id'] ?? '';

// Fetch list of branches for admin filter
if ($user['role_name'] === 'admin') {
    $branchOptions = $pdo->query("SELECT id, name FROM branches ORDER BY name")->fetchAll();
}

// Fetch purchases based on filters and role
$query = "SELECT p.*, b.name AS branch_name, s.name AS supplier_name, u.username AS purchaser_name
    FROM purchases p
    JOIN branches b ON p.branch_id = b.id
    LEFT JOIN suppliers s ON p.supplier_id = s.id
    JOIN users u ON p.purchased_by = u.id
    WHERE DATE(p.purchase_date) BETWEEN ? AND ?";
$params = [$start_date, $end_date];
// Restrict by role
if ($user['role_name'] !== 'admin') {
    $query .= " AND p.branch_id = ?";
    $params[] = $user['branch_id'];
} elseif ($branch_filter) {
    $query .= " AND p.branch_id = ?";
    $params[] = $branch_filter;
}
$query .= " ORDER BY p.purchase_date DESC";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$purchases = $stmt->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Purchases - POS System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- DataTables CSS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
</head>
<body>
<?php include 'includes/nav.php'; ?>
<div class="container">
    <h1 class="mb-4">Purchase List</h1>
    <?php if ($message): ?>
        <div class="alert alert-info"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>
    <form method="get" class="row g-3 mb-4">
        <div class="col-md-3">
            <label class="form-label">Start Date</label>
            <input type="date" name="start_date" class="form-control" value="<?php echo htmlspecialchars($start_date); ?>" onchange="this.form.submit()">
        </div>
        <div class="col-md-3">
            <label class="form-label">End Date</label>
            <input type="date" name="end_date" class="form-control" value="<?php echo htmlspecialchars($end_date); ?>" onchange="this.form.submit()">
        </div>
        <?php if ($user['role_name'] === 'admin'): ?>
            <div class="col-md-3">
                <label class="form-label">Branch</label>
                <select name="branch_id" class="form-select" onchange="this.form.submit()">
                    <option value="">All</option>
                    <?php foreach ($branchOptions as $br): ?>
                        <option value="<?php echo $br['id']; ?>" <?php echo ($branch_filter == $br['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($br['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>
    </form>
    <div class="table-responsive">
        <table id="purchaseTable" class="table table-bordered table-striped">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Date</th>
                    <th>Branch</th>
                    <th>Supplier</th>
                    <th>Purchased By</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($purchases as $pur): ?>
                <tr>
                    <td><?php echo $pur['id']; ?></td>
                    <td><?php echo htmlspecialchars($pur['purchase_date']); ?></td>
                    <td><?php echo htmlspecialchars($pur['branch_name']); ?></td>
                    <td><?php echo htmlspecialchars($pur['supplier_name'] ?? 'N/A'); ?></td>
                    <td><?php echo htmlspecialchars($pur['purchaser_name']); ?></td>
                    <td>
                        <a href="purchase_view.php?id=<?php echo $pur['id']; ?>" class="btn btn-sm btn-info">View</a>
                        <a href="purchase_edit.php?id=<?php echo $pur['id']; ?>" class="btn btn-sm btn-warning">Edit</a>
                        <a href="purchase_list.php?delete=<?php echo $pur['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure you want to delete this purchase? This will revert the stock.');">Delete</a>
                    </td>
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
    $('#purchaseTable').DataTable();
  });
</script>
</body>
</html>