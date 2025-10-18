<?php
/**
 * sales_return_list.php
 *
 * Lists all sale return transactions in a sortable DataTable.  Administrators
 * and managers can view returns for their respective branches.  Each entry
 * includes links to view the return details or delete the return entirely.
 */
require_once 'includes/header.php';
checkRole(['admin','manager']);

// Build branch filter for managers: admins see all
$isAdmin = ($user['role_name'] === 'admin');
$branchCondition = '';
$params = [];
if (!$isAdmin) {
    $branchCondition = 'WHERE sr.branch_id = ?';
    $params[] = $user['branch_id'];
}
$sql = "SELECT sr.id, sr.sale_id, b.name AS branch_name, sr.return_date, sr.return_type, sr.total_refund, u.username AS processed_by
        FROM sale_returns sr
        JOIN branches b ON sr.branch_id = b.id
        JOIN users u ON sr.processed_by = u.id
        $branchCondition
        ORDER BY sr.return_date DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$returns = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sale Return List - POS System</title>
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
</head>
<body>
<?php include 'includes/nav.php'; ?>
<div class="container mt-4">
    <h3 class="mb-3">Sale Returns</h3>
    <table id="returnsTable" class="table table-bordered table-striped">
        <thead class="table-light">
        <tr>
            <th>ID</th>
            <th>Sale ID</th>
            <th>Branch</th>
            <th>Return Date</th>
            <th>Processed By</th>
            <th>Type</th>
            <th>Total Refund</th>
            <th>Actions</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($returns as $r): ?>
            <tr>
                <td><?= htmlspecialchars($r['id']) ?></td>
                <td><?= htmlspecialchars($r['sale_id']) ?></td>
                <td><?= htmlspecialchars($r['branch_name']) ?></td>
                <td><?= htmlspecialchars($r['return_date']) ?></td>
                <td><?= htmlspecialchars($r['processed_by']) ?></td>
                <td><?= htmlspecialchars($r['return_type']) ?></td>
                <td><?= number_format($r['total_refund'], 2) ?></td>
                <td>
                    <a href="sales_return_view.php?id=<?= $r['id'] ?>" class="btn btn-sm btn-info">View</a>
                    <?php if ($isAdmin || ($user['role_name'] === 'manager' && $r['branch_name'] === $user['branch_name'])): ?>
                        <button class="btn btn-sm btn-danger" onclick="deleteReturn(<?= $r['id'] ?>)">Delete</button>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
<script>
$(document).ready(function() {
    $('#returnsTable').DataTable();
});
function deleteReturn(id) {
    if (!confirm('Are you sure you want to delete this return?')) return;
    $.post('ajax_delete_return.php', { id: id }, function(response) {
        if (response.status === 'success') {
            location.reload();
        } else {
            alert('Delete failed: ' + (response.message || 'Unknown error'));
        }
    }, 'json').fail(function() {
        alert('Failed to communicate with the server');
    });
}
</script>
</body>
</html>