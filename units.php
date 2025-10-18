<?php
require_once 'includes/header.php';
// Only admin, manager, or inventory officer can access this page
checkRole(['admin', 'manager', 'inventory_officer']);

$message = '';

// Add new unit
if (isset($_POST['add_unit'])) {
    $name = trim($_POST['name'] ?? '');
    if ($name) {
        $stmt = $pdo->prepare("INSERT INTO units (name) VALUES (?)");
        $stmt->execute([$name]);
        $message = 'Unit added successfully!';
    } else {
        $message = 'Unit name is required';
    }
}

// Update unit
if (isset($_POST['update_unit'])) {
    $id = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    if ($id && $name) {
        $stmt = $pdo->prepare("UPDATE units SET name = ? WHERE id = ?");
        $stmt->execute([$name, $id]);
        $message = 'Unit updated successfully!';
    } else {
        $message = 'Unit name is required';
    }
}

// Fetch units
$units = $pdo->query("SELECT * FROM units ORDER BY id DESC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Units - POS System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- DataTables CSS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
</head>
<body>
<?php include 'includes/nav.php'; ?>
<div class="container">
    <h1 class="mb-4">Units</h1>
    <?php if ($message): ?>
        <div class="alert alert-info"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <!-- Add Unit Form -->
    <div class="card mb-4">
        <div class="card-header">Add New Unit</div>
        <div class="card-body">
            <form method="post">
                <div class="row g-3 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label">Unit Name</label>
                        <input type="text" name="name" class="form-control" placeholder="Unit Name" required>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" name="add_unit" class="btn btn-primary w-100">Add</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Units List -->
    <table id="unitsTable" class="table table-bordered table-striped">
        <thead>
        <tr>
            <th>ID</th>
            <th>Name</th>
            <th>Action</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($units as $un): ?>
            <tr>
                <td><?php echo $un['id']; ?></td>
                <td><?php echo htmlspecialchars($un['name']); ?></td>
                <td>
                    <!-- Trigger edit modal -->
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-toggle="modal" data-bs-target="#editUnitModal<?php echo $un['id']; ?>">Edit</button>
                </td>
            </tr>

            <!-- Edit Unit Modal -->
            <div class="modal fade" id="editUnitModal<?php echo $un['id']; ?>" tabindex="-1" aria-labelledby="editUnitLabel<?php echo $un['id']; ?>" aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="editUnitLabel<?php echo $un['id']; ?>">Edit Unit</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <form method="post">
                                <input type="hidden" name="id" value="<?php echo $un['id']; ?>">
                                <div class="mb-3">
                                    <label class="form-label">Unit Name</label>
                                    <input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($un['name']); ?>" required>
                                </div>
                                <button type="submit" name="update_unit" class="btn btn-primary">Update</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<!-- jQuery and DataTables JS -->
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script>
  $(document).ready(function() {
    $('#unitsTable').DataTable();
  });
</script>
</body>
</html>