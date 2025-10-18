<?php
// Audit Report: Shows combined list of sales, purchases and transfers
require_once 'includes/header.php';

// Only admin and manager can view audit report
checkRole(['admin', 'manager']);

// Date filters with defaults (current month)
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date   = $_GET['end_date'] ?? date('Y-m-d');
$branch_filter = $_GET['branch_id'] ?? '';

// Prepare arrays for results
$records = [];

// Fetch sales
$salesQuery = "SELECT s.id, s.sale_date AS date, s.branch_id, b.name AS branch_name, u.username AS user, s.total_amount
    FROM sales s
    JOIN branches b ON s.branch_id = b.id
    JOIN users u ON s.sold_by = u.id
    WHERE DATE(s.sale_date) BETWEEN ? AND ?";
$salesParams = [$start_date, $end_date];
// Restrict by role or branch filter
if ($user['role_name'] !== 'admin') {
    $salesQuery .= " AND s.branch_id = ?";
    $salesParams[] = $user['branch_id'];
} elseif ($branch_filter) {
    $salesQuery .= " AND s.branch_id = ?";
    $salesParams[] = $branch_filter;
}
$salesQuery .= " ORDER BY s.sale_date DESC";
$stmt = $pdo->prepare($salesQuery);
$stmt->execute($salesParams);
foreach ($stmt as $row) {
    $records[] = [
        'id' => $row['id'],
        'type' => 'Sale',
        'date' => $row['date'],
        'branch' => $row['branch_name'],
        'amount' => $row['total_amount'],
        'user' => $row['user']
    ];
}

// Fetch purchases and compute totals
$purchaseQuery = "SELECT p.id, p.purchase_date AS date, p.branch_id, b.name AS branch_name, u.username AS user
    FROM purchases p
    JOIN branches b ON p.branch_id = b.id
    JOIN users u ON p.purchased_by = u.id
    WHERE DATE(p.purchase_date) BETWEEN ? AND ?";
$purchaseParams = [$start_date, $end_date];
if ($user['role_name'] !== 'admin') {
    $purchaseQuery .= " AND p.branch_id = ?";
    $purchaseParams[] = $user['branch_id'];
} elseif ($branch_filter) {
    $purchaseQuery .= " AND p.branch_id = ?";
    $purchaseParams[] = $branch_filter;
}
$purchaseQuery .= " ORDER BY p.purchase_date DESC";
$stmt = $pdo->prepare($purchaseQuery);
$stmt->execute($purchaseParams);
// Pre-fetch purchase totals into associative array for efficiency
$totalsStmt = $pdo->prepare("SELECT p.id, SUM(pi.quantity * pi.cost_price) AS total
    FROM purchases p
    LEFT JOIN purchase_items pi ON p.id = pi.purchase_id
    WHERE p.id = ?
    GROUP BY p.id");
foreach ($stmt as $row) {
    // Compute total for this purchase
    $totalsStmt->execute([$row['id']]);
    $totalRow = $totalsStmt->fetch();
    $total = $totalRow['total'] ?? 0;
    $records[] = [
        'id' => $row['id'],
        'type' => 'Purchase',
        'date' => $row['date'],
        'branch' => $row['branch_name'],
        'amount' => $total,
        'user' => $row['user']
    ];
}

// Fetch stock transfers (optional): show quantity moved but not amount
$transferQuery = "SELECT st.id, st.transferred_at AS date, st.from_branch_id, fb.name AS branch_name, u.username AS user
    FROM stock_transfers st
    JOIN branches fb ON st.from_branch_id = fb.id
    JOIN users u ON st.transferred_by = u.id
    WHERE DATE(st.transferred_at) BETWEEN ? AND ?";
$transferParams = [$start_date, $end_date];
if ($user['role_name'] !== 'admin') {
    $transferQuery .= " AND st.from_branch_id = ?";
    $transferParams[] = $user['branch_id'];
} elseif ($branch_filter) {
    $transferQuery .= " AND st.from_branch_id = ?";
    $transferParams[] = $branch_filter;
}
$transferQuery .= " ORDER BY st.transferred_at DESC";
$stmt = $pdo->prepare($transferQuery);
$stmt->execute($transferParams);
// Compute quantities per transfer
$qtyStmt = $pdo->prepare("SELECT SUM(quantity) AS qty FROM stock_transfer_items WHERE transfer_id = ?");
foreach ($stmt as $row) {
    $qtyStmt->execute([$row['id']]);
    $q = $qtyStmt->fetch();
    $qty = $q['qty'] ?? 0;
    $records[] = [
        'id' => $row['id'],
        'type' => 'Transfer',
        'date' => $row['date'],
        'branch' => $row['branch_name'],
        'amount' => $qty, // using quantity as amount for display
        'user' => $row['user']
    ];
}

// Sort records by date descending (mix of types). Already sorted individually but need to merge sort
usort($records, function($a, $b) {
    return strtotime($b['date']) <=> strtotime($a['date']);
});

// Fetch list of branches for filter (admin only)
if ($user['role_name'] === 'admin') {
    $branchOptions = $pdo->query("SELECT id, name FROM branches ORDER BY name")->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Audit Report - POS System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- DataTables CSS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
</head>
<body>
<?php include 'includes/nav.php'; ?>
<div class="container">
    <h1 class="mb-4">Audit Report</h1>
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
        <table id="auditTable" class="table table-bordered table-striped">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Type</th>
                    <th>Date</th>
                    <th>Branch</th>
                    <th>Amount</th>
                    <th>User</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($records as $rec): ?>
                <tr>
                    <td><?php echo $rec['id']; ?></td>
                    <td><?php echo htmlspecialchars($rec['type']); ?></td>
                    <td><?php echo htmlspecialchars($rec['date']); ?></td>
                    <td><?php echo htmlspecialchars($rec['branch']); ?></td>
                    <td>
                        <?php
                        // Display currency for sales and purchases; quantity for transfers
                        if ($rec['type'] === 'Transfer') {
                            echo number_format($rec['amount']);
                        } else {
                            echo 'LKR ' . number_format($rec['amount'], 2);
                        }
                        ?>
                    </td>
                    <td><?php echo htmlspecialchars($rec['user']); ?></td>
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
    $('#auditTable').DataTable();
  });
</script>
</body>
</html>