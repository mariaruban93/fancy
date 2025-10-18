<?php
/**
 * Manage workers (staff members) page.
 * Allows admin and managers to create and edit workers. Each worker belongs to a branch
 * and has a phone number. Managers can only view and manage workers in their own branch.
 */
require_once 'includes/header.php';
// Restrict access to admin and manager roles
checkRole(['admin', 'manager']);

$message = '';

// Fetch branches for admin drop-down
$branches = [];
if ($user['role_name'] === 'admin') {
    try {
        $branches = $pdo->query("SELECT id, name FROM branches WHERE is_active = 1 ORDER BY name")->fetchAll();
    } catch (Throwable $e) {
        $branches = [];
    }
}

// Add new worker
if (isset($_POST['add_worker'])) {
    $name   = trim($_POST['name'] ?? '');
    $phone  = trim($_POST['phone'] ?? '');
    $branch_id = null;
    if ($user['role_name'] === 'admin') {
        $branch_id = isset($_POST['branch_id']) && $_POST['branch_id'] !== '' ? (int)$_POST['branch_id'] : null;
    } else {
        $branch_id = (int)$user['branch_id'];
    }
    if ($name && $phone && $branch_id) {
        $stmt = $pdo->prepare("INSERT INTO workers (branch_id, name, phone) VALUES (?, ?, ?)");
        $stmt->execute([$branch_id, $name, $phone]);
        $message = 'Worker added successfully!';
    } else {
        $message = 'Name, phone and branch are required';
    }
}

// Update worker
if (isset($_POST['update_worker'])) {
    $id      = (int)($_POST['id'] ?? 0);
    $name    = trim($_POST['name'] ?? '');
    $phone   = trim($_POST['phone'] ?? '');
    $branch_id = null;
    if ($user['role_name'] === 'admin') {
        $branch_id = isset($_POST['branch_id']) && $_POST['branch_id'] !== '' ? (int)$_POST['branch_id'] : null;
    }
    if ($id && $name && $phone) {
        // If admin, update branch; if manager, ensure branch matches their own
        if ($user['role_name'] === 'admin' && $branch_id) {
            $stmt = $pdo->prepare("UPDATE workers SET branch_id = ?, name = ?, phone = ? WHERE id = ?");
            $stmt->execute([$branch_id, $name, $phone, $id]);
        } else {
            // Managers can only edit workers in their own branch
            $stmt = $pdo->prepare("UPDATE workers SET name = ?, phone = ? WHERE id = ? AND branch_id = ?");
            $stmt->execute([$name, $phone, $id, $user['branch_id']]);
        }
        $message = 'Worker updated successfully!';
    } else {
        $message = 'Name and phone are required';
    }
}

// Fetch workers based on role
$workers = [];
try {
    if ($user['role_name'] === 'admin') {
        $workers = $pdo->query("SELECT w.*, b.name AS branch_name FROM workers w JOIN branches b ON w.branch_id = b.id ORDER BY w.id DESC")->fetchAll();
    } else {
        $stmt = $pdo->prepare("SELECT w.*, b.name AS branch_name FROM workers w JOIN branches b ON w.branch_id = b.id WHERE w.branch_id = ? ORDER BY w.id DESC");
        $stmt->execute([$user['branch_id']]);
        $workers = $stmt->fetchAll();
    }
} catch (Throwable $e) {
    $workers = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Workers - POS System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- DataTables CSS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
</head>
<body>
<?php include 'includes/nav.php'; ?>
<div class="container">
    <h1 class="mb-4">Workers</h1>
    <?php if ($message): ?>
        <div class="alert alert-info"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>
    <!-- Add Worker Form -->
    <div class="card mb-4">
        <div class="card-header">Add New Worker</div>
        <div class="card-body">
            <form method="post">
                <div class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label">Name</label>
                        <input type="text" name="name" class="form-control" placeholder="Worker Name" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Phone</label>
                        <input type="text" name="phone" class="form-control" placeholder="Phone" required>
                    </div>
                    <?php if ($user['role_name'] === 'admin'): ?>
                    <div class="col-md-3">
                        <label class="form-label">Branch</label>
                        <select name="branch_id" class="form-select" required>
                            <option value="">Select Branch</option>
                            <?php foreach ($branches as $br): ?>
                                <option value="<?php echo (int)$br['id']; ?>"><?php echo htmlspecialchars($br['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php else: ?>
                    <div class="col-md-3">
                        <label class="form-label">Branch</label>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($user['branch_name']); ?>" disabled>
                    </div>
                    <?php endif; ?>
                    <div class="col-md-2">
                        <button type="submit" name="add_worker" class="btn btn-primary w-100">Add</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    <!-- Workers List -->
    <table id="workersTable" class="table table-bordered table-striped">
        <thead>
            <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Phone</th>
                <th>Branch</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($workers as $w): ?>
            <tr>
                <td><?php echo $w['id']; ?></td>
                <td><?php echo htmlspecialchars($w['name']); ?></td>
                <td><?php echo htmlspecialchars($w['phone']); ?></td>
                <td><?php echo htmlspecialchars($w['branch_name']); ?></td>
                <td>
                    <!-- View details page -->
                    <a href="worker_view.php?id=<?php echo $w['id']; ?>" class="btn btn-sm btn-info">View</a>
                    <!-- Trigger edit modal -->
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-toggle="modal" data-bs-target="#editWorkerModal<?php echo $w['id']; ?>">Edit</button>
                </td>
            </tr>
            <!-- Edit Worker Modal -->
            <div class="modal fade" id="editWorkerModal<?php echo $w['id']; ?>" tabindex="-1" aria-labelledby="editWorkerLabel<?php echo $w['id']; ?>" aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="editWorkerLabel<?php echo $w['id']; ?>">Edit Worker</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <form method="post">
                                <input type="hidden" name="id" value="<?php echo $w['id']; ?>">
                                <div class="mb-2">
                                    <label class="form-label">Name</label>
                                    <input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($w['name']); ?>" required>
                                </div>
                                <div class="mb-2">
                                    <label class="form-label">Phone</label>
                                    <input type="text" name="phone" class="form-control" value="<?php echo htmlspecialchars($w['phone']); ?>" required>
                                </div>
                                <?php if ($user['role_name'] === 'admin'): ?>
                                <div class="mb-2">
                                    <label class="form-label">Branch</label>
                                    <select name="branch_id" class="form-select" required>
                                        <?php foreach ($branches as $br): ?>
                                            <option value="<?php echo (int)$br['id']; ?>" <?php echo ($br['id'] == $w['branch_id'] ? 'selected' : ''); ?>><?php echo htmlspecialchars($br['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <?php endif; ?>
                                <div class="mt-3">
                                    <button type="submit" name="update_worker" class="btn btn-primary">Update</button>
                                </div>
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
  $('#workersTable').DataTable();
});
</script>
</body>
</html>