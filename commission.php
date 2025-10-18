<?php
require_once 'includes/header.php';
// Allow admin and manager to access commission report
checkRole(['admin', 'manager']);

$message = '';
$results = [];
$totalSales = 0;
$commissionAmount = 0;
$worker = '';
$startDate = '';
$endDate = '';
$percentage = '';

// Fetch list of workers for selection. Admin sees all workers; manager sees only their branch
$workerOptions = [];
try {
    if ($user['role_name'] === 'admin') {
        $stmt = $pdo->query("SELECT id, name, branch_id FROM workers ORDER BY name");
        $workerOptions = $stmt->fetchAll();
    } else {
        $stmt = $pdo->prepare("SELECT id, name, branch_id FROM workers WHERE branch_id = ? ORDER BY name");
        $stmt->execute([$user['branch_id']]);
        $workerOptions = $stmt->fetchAll();
    }
} catch (Throwable $e) {
    $workerOptions = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $worker = trim($_POST['worker_number'] ?? '');
    $startDate = trim($_POST['start_date'] ?? '');
    $endDate = trim($_POST['end_date'] ?? '');
    $percentage = trim($_POST['percentage'] ?? '');
    if ($worker === '') {
        $message = 'Worker number is required';
    } elseif ($startDate === '' || $endDate === '') {
        $message = 'Start and end dates are required';
    } elseif (!is_numeric($percentage) || $percentage < 0) {
        $message = 'Valid commission percentage is required';
    } else {
        // Prepare query based on role. Managers only see their branch.
        $params = [];
        $sql = "SELECT s.id, s.sale_date, s.branch_id, s.total_amount FROM sales s WHERE s.worker_number = ? AND DATE(s.sale_date) BETWEEN ? AND ?";
        $params[] = $worker;
        $params[] = $startDate;
        $params[] = $endDate;
        if ($user['role_name'] === 'manager') {
            // Restrict to manager's branch
            $sql .= " AND s.branch_id = ?";
            $params[] = $user['branch_id'];
        }
        $sql .= " ORDER BY s.sale_date ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $results = $stmt->fetchAll();
        // Sum total amounts
        foreach ($results as $row) {
            $totalSales += (float)$row['total_amount'];
        }
        $commissionAmount = $totalSales * ((float)$percentage / 100.0);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Commission Report - POS System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- DataTables CSS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
</head>
<body>
<?php include 'includes/nav.php'; ?>
<div class="container">
    <h1 class="mb-4">Staff Commission Report</h1>
    <?php if ($message): ?>
        <div class="alert alert-warning"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>
    <form method="post" class="row g-3 mb-4">
        <div class="col-md-3">
            <label class="form-label">Worker</label>
            <select name="worker_number" class="form-select" required>
                <option value="">Select Worker</option>
                <?php foreach ($workerOptions as $wo): ?>
                    <option value="<?php echo (int)$wo['id']; ?>" <?php echo ($worker !== '' && (int)$worker == $wo['id'] ? 'selected' : ''); ?>>
                        <?php echo htmlspecialchars($wo['name']); ?>
                        <?php if ($user['role_name'] === 'admin'): ?> (<?php
                            // show branch name for admin
                            $bid = (int)$wo['branch_id'];
                            $bnStmt = $pdo->prepare("SELECT name FROM branches WHERE id = ?");
                            $bnStmt->execute([$bid]);
                            echo htmlspecialchars($bnStmt->fetchColumn());
                        ?>)
                        <?php endif; ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label">Start Date</label>
            <input type="date" name="start_date" class="form-control" value="<?php echo htmlspecialchars($startDate); ?>" required>
        </div>
        <div class="col-md-3">
            <label class="form-label">End Date</label>
            <input type="date" name="end_date" class="form-control" value="<?php echo htmlspecialchars($endDate); ?>" required>
        </div>
        <div class="col-md-2">
            <label class="form-label">Commission (%)</label>
            <input type="number" step="0.01" name="percentage" class="form-control" value="<?php echo htmlspecialchars($percentage); ?>" required>
        </div>
        <div class="col-md-1 d-flex align-items-end">
            <button type="submit" class="btn btn-primary w-100">Calculate</button>
        </div>
    </form>
    <?php if (!empty($results)): ?>
        <h5>Total Sales: Rs. <?php echo number_format($totalSales, 2); ?></h5>
        <h5>Commission (<?php echo htmlspecialchars($percentage); ?>%): Rs. <?php echo number_format($commissionAmount, 2); ?></h5>
        <table id="commissionTable" class="table table-bordered table-striped">
            <thead>
                <tr>
                    <th>Sale ID</th>
                    <th>Date</th>
                    <th>Branch</th>
                    <th class="text-end">Total Amount (Rs.)</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($results as $row): ?>
                    <tr>
                        <td><?php echo $row['id']; ?></td>
                        <td><?php echo htmlspecialchars($row['sale_date']); ?></td>
                        <td><?php
                            $bid = (int)$row['branch_id'];
                            $bnStmt = $pdo->prepare("SELECT name FROM branches WHERE id = ?");
                            $bnStmt->execute([$bid]);
                            $bn = $bnStmt->fetchColumn();
                            echo htmlspecialchars($bn);
                        ?></td>
                        <td class="text-end"><?php echo number_format($row['total_amount'], 2); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<!-- jQuery and DataTables JS -->
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script>
$(document).ready(function() {
  $('#commissionTable').DataTable();
});
</script>
</body>
</html>