<?php
require_once 'includes/header.php';
// Only admin or manager can access
checkRole(['admin', 'manager']);

$report = $_GET['report'] ?? 'sales';
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$branch_filter = $_GET['branch_id'] ?? '';

// Fetch branches for filter (admin only)
if ($user['role_name'] === 'admin') {
    $branches = $pdo->query("SELECT id, name FROM branches")->fetchAll();
}

// Prepare data arrays
$sales = [];
$stock = [];
$transfers = [];

if ($report === 'sales') {
    // Fetch sales within date range
    $query = "SELECT s.*, b.name AS branch_name, u.username FROM sales s
        JOIN branches b ON s.branch_id = b.id
        JOIN users u ON s.sold_by = u.id
        WHERE DATE(s.sale_date) BETWEEN ? AND ?";
    $params = [$start_date, $end_date];
    if ($user['role_name'] !== 'admin') {
        $query .= " AND s.branch_id = ?";
        $params[] = $user['branch_id'];
    } elseif ($branch_filter) {
        $query .= " AND s.branch_id = ?";
        $params[] = $branch_filter;
    }
    $query .= " ORDER BY s.sale_date DESC";
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $sales = $stmt->fetchAll();
} elseif ($report === 'stock') {
    // Fetch current stock by product per branch
    $query = "SELECT b.name AS branch_name, p.name AS product_name, SUM(sb.quantity) AS qty, p.unit FROM stock_batches sb
        JOIN products p ON sb.product_id = p.id
        JOIN branches b ON sb.branch_id = b.id
        GROUP BY sb.branch_id, sb.product_id";
    $stmt = $pdo->query($query);
    $stock = $stmt->fetchAll();
} elseif ($report === 'transfers') {
    // Fetch transfers within date range
    $query = "SELECT st.*, fb.name AS from_branch_name, tb.name AS to_branch_name, u.username
        FROM stock_transfers st
        JOIN branches fb ON st.from_branch_id = fb.id
        JOIN branches tb ON st.to_branch_id = tb.id
        JOIN users u ON st.transferred_by = u.id
        WHERE DATE(st.transferred_at) BETWEEN ? AND ?";
    $params = [$start_date, $end_date];
    if ($user['role_name'] !== 'admin') {
        $query .= " AND st.from_branch_id = ?";
        $params[] = $user['branch_id'];
    } elseif ($branch_filter) {
        $query .= " AND st.from_branch_id = ?";
        $params[] = $branch_filter;
    }
    $query .= " ORDER BY st.transferred_at DESC";
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $transfers = $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports - POS System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- DataTables CSS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
</head>
<body>
<?php include 'includes/nav.php'; ?>
<div class="container">
    <h1 class="mb-4">Reports</h1>
    <form method="get" class="row g-3 mb-4">
        <div class="col-md-3">
            <label class="form-label">Report Type</label>
            <select name="report" class="form-select" onchange="this.form.submit()">
                <option value="sales" <?php echo $report === 'sales' ? 'selected' : ''; ?>>Sales</option>
                <option value="stock" <?php echo $report === 'stock' ? 'selected' : ''; ?>>Stock Summary</option>
                <option value="transfers" <?php echo $report === 'transfers' ? 'selected' : ''; ?>>Transfers</option>
            </select>
        </div>
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
                <?php foreach ($branches as $br): ?>
                    <option value="<?php echo $br['id']; ?>" <?php echo $branch_filter == $br['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($br['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
    </form>

    <?php if ($report === 'sales'): ?>
        <h3>Sales Report</h3>
        <div class="table-responsive">
            <table id="salesReportTable" class="table table-bordered table-striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Date</th>
                        <th>Branch</th>
                        <th>Total Amount</th>
                        <th>Sold By</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($sales as $sale): ?>
                    <tr>
                        <td><?php echo $sale['id']; ?></td>
                        <td><?php echo htmlspecialchars($sale['sale_date']); ?></td>
                        <td><?php echo htmlspecialchars($sale['branch_name']); ?></td>
                        <td>LKR <?php echo number_format($sale['total_amount'], 2); ?></td>
                        <td><?php echo htmlspecialchars($sale['username']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php elseif ($report === 'stock'): ?>
        <h3>Stock Summary</h3>
        <div class="table-responsive">
            <table id="stockReportTable" class="table table-bordered table-striped">
                <thead>
                    <tr>
                        <th>Branch</th>
                        <th>Product</th>
                        <th>Quantity</th>
                        <th>Unit</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($stock as $s): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($s['branch_name']); ?></td>
                        <td><?php echo htmlspecialchars($s['product_name']); ?></td>
                        <td><?php echo $s['qty']; ?></td>
                        <td><?php echo htmlspecialchars($s['unit']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php elseif ($report === 'transfers'): ?>
        <h3>Transfer History</h3>
        <div class="table-responsive">
            <table id="transfersReportTable" class="table table-bordered table-striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Date</th>
                        <th>From Branch</th>
                        <th>To Branch</th>
                        <th>Transferred By</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($transfers as $tr): ?>
                    <tr>
                        <td><?php echo $tr['id']; ?></td>
                        <td><?php echo htmlspecialchars($tr['transferred_at']); ?></td>
                        <td><?php echo htmlspecialchars($tr['from_branch_name']); ?></td>
                        <td><?php echo htmlspecialchars($tr['to_branch_name']); ?></td>
                        <td><?php echo htmlspecialchars($tr['username']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<!-- jQuery and DataTables JS -->
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script>
  $(document).ready(function() {
    // Initialize DataTables only for the currently visible report table
    if ($('#salesReportTable').length) {
      $('#salesReportTable').DataTable();
    }
    if ($('#stockReportTable').length) {
      $('#stockReportTable').DataTable();
    }
    if ($('#transfersReportTable').length) {
      $('#transfersReportTable').DataTable();
    }
  });
</script>
</body>
</html>