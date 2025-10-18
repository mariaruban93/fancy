<?php
require_once 'includes/header.php';
// List of sales transactions based on role. Cashier sees their own sales, manager sees branch-wise, admin can view all or filter by branch.
checkRole(['admin', 'manager', 'cashier']);

$message = '';
// Handle sale deletion
if (isset($_GET['delete'])) {
    $delId = (int)$_GET['delete'];
    if ($delId > 0) {
        try {
            $pdo->beginTransaction();
            // Fetch sale details and verify permissions
            $stmtSale = $pdo->prepare("SELECT * FROM sales WHERE id = ?");
            $stmtSale->execute([$delId]);
            $sale = $stmtSale->fetch();
            if ($sale) {
                // Permission check: cashier can only delete own sales, manager only own branch
                $allow = false;
                if ($user['role_name'] === 'admin') {
                    $allow = true;
                } elseif ($user['role_name'] === 'manager' && $sale['branch_id'] == $user['branch_id']) {
                    $allow = true;
                } elseif ($user['role_name'] === 'cashier' && $sale['sold_by'] == $user['id']) {
                    $allow = true;
                }
                if ($allow) {
                    // Revert stock for sale items
                    $itemsStmt = $pdo->prepare("SELECT product_id, batch_id, quantity FROM sale_items WHERE sale_id = ?");
                    $itemsStmt->execute([$delId]);
                    $items = $itemsStmt->fetchAll();
                    foreach ($items as $it) {
                        $pid  = (int)$it['product_id'];
                        $batchId = (int)$it['batch_id'];
                        $qty  = (int)$it['quantity'];
                        // Add quantity back to stock batch
                        $updStmt = $pdo->prepare("UPDATE stock_batches SET quantity = quantity + ? WHERE id = ?");
                        $updStmt->execute([$qty, $batchId]);
                    }
                    // Delete sale payments
                    $delPays = $pdo->prepare("DELETE FROM sale_payments WHERE sale_id = ?");
                    $delPays->execute([$delId]);
                    // Delete sale items
                    $delItems = $pdo->prepare("DELETE FROM sale_items WHERE sale_id = ?");
                    $delItems->execute([$delId]);
                    // Delete sale
                    $delSale = $pdo->prepare("DELETE FROM sales WHERE id = ?");
                    $delSale->execute([$delId]);
                    $pdo->commit();
                    $message = 'Sale deleted and stock restored.';
                    // Log deletion of sale
                    try {
                        logActivity($pdo, $user['id'], 'Sale', 'Deleted sale #' . $delId . ' and restored stock', $sale['branch_id']);
                    } catch (Throwable $logErr) {
                        // Ignore logging errors
                    }
                } else {
                    $pdo->rollBack();
                    $message = 'You do not have permission to delete this sale.';
                }
            } else {
                $pdo->rollBack();
                $message = 'Sale not found.';
            }
        } catch (Throwable $e) {
            $pdo->rollBack();
            $message = 'Error deleting sale: ' . $e->getMessage();
        }
    }
}

// Prepare filters
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$end_date   = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
$branch_filter = '';
if ($user['role_name'] === 'admin') {
    $branch_filter = $_GET['branch_id'] ?? '';
    // Branch options for admin filter
    $branchOptions = $pdo->query("SELECT id, name FROM branches ORDER BY name")->fetchAll();
}

// Build base query
$query = "SELECT s.*, b.name AS branch_name, u.username AS sold_by_name
    FROM sales s
    JOIN branches b ON s.branch_id = b.id
    JOIN users u ON s.sold_by = u.id
    WHERE DATE(s.sale_date) BETWEEN ? AND ?";
$params = [$start_date, $end_date];

// Apply role-based restrictions
if ($user['role_name'] === 'cashier') {
    // Cashier: only their own sales
    $query .= " AND s.sold_by = ?";
    $params[] = $user['id'];
} elseif ($user['role_name'] === 'manager') {
    // Manager: only their branch
    $query .= " AND s.branch_id = ?";
    $params[] = $user['branch_id'];
} elseif ($user['role_name'] === 'admin') {
    if ($branch_filter !== '') {
        $query .= " AND s.branch_id = ?";
        $params[] = $branch_filter;
    }
}

$query .= " ORDER BY s.sale_date DESC";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$sales = $stmt->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales - POS System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- DataTables CSS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
</head>
<body>
<?php include 'includes/nav.php'; ?>
<div class="container">
    <h1 class="mb-4">Sales List</h1>
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
        <table id="salesTable" class="table table-bordered table-striped">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Invoice</th>
                    <th>Date &amp; Time</th>
                    <th>Branch</th>
                    <th>Cashier</th>
                    <th>Total Amount</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($sales as $s): ?>
                    <tr>
                        <td><?php echo $s['id']; ?></td>
                        <td>
                            <?php
                            // Display formatted invoice number if available, else fallback to blank
                            if (isset($s['invoice_no']) && $s['invoice_no'] !== null) {
                                echo 'BR' . (int)$s['branch_id'] . '-' . str_pad((int)$s['invoice_no'], 4, '0', STR_PAD_LEFT);
                            } else {
                                echo '-';
                            }
                            ?>
                        </td>
                        <td><?php echo htmlspecialchars($s['sale_date']); ?></td>
                        <td><?php echo htmlspecialchars($s['branch_name']); ?></td>
                        <td><?php echo htmlspecialchars($s['sold_by_name']); ?></td>
                        <td><?php echo number_format((float)$s['total_amount'], 2); ?></td>
                        <td>
                            <a href="sales_view.php?id=<?php echo $s['id']; ?>" class="btn btn-sm btn-info">View</a>
                            <?php
                              $canModify = false;
                              if ($user['role_name'] === 'admin') {
                                  $canModify = true;
                              } elseif ($user['role_name'] === 'manager' && $s['branch_id'] == $user['branch_id']) {
                                  $canModify = true;
                              } elseif ($user['role_name'] === 'cashier' && $s['sold_by'] == $user['id']) {
                                  $canModify = true;
                              }
                              if ($canModify):
                            ?>
                                <a href="sales_edit.php?id=<?php echo $s['id']; ?>" class="btn btn-sm btn-warning">Edit</a>
                                <a href="sales_return.php?id=<?php echo $s['id']; ?>" class="btn btn-sm btn-secondary">Return</a>
                                <a href="sales_list.php?delete=<?php echo $s['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure you want to delete this sale? This will restore the stock.');">Delete</a>
                            <?php endif; ?>
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
    $('#salesTable').DataTable();
  });
</script>
</body>
</html>