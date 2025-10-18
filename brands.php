<?php
require_once 'includes/header.php';
// Only admin, manager, or inventory officer can access this page
checkRole(['admin', 'manager', 'inventory_officer']);

$message = '';

// Add new brand
if (isset($_POST['add_brand'])) {
    $name = trim($_POST['name'] ?? '');
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    if ($name) {
        $stmt = $pdo->prepare("INSERT INTO brands (name, is_active) VALUES (?, ?)");
        $stmt->execute([$name, $is_active]);
        $message = 'Brand added successfully!';
    } else {
        $message = 'Brand name is required';
    }
}

// Update brand
if (isset($_POST['update_brand'])) {
    $id = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    if ($id && $name) {
        $stmt = $pdo->prepare("UPDATE brands SET name = ?, is_active = ? WHERE id = ?");
        $stmt->execute([$name, $is_active, $id]);
        $message = 'Brand updated successfully!';
    } else {
        $message = 'Brand name is required';
    }
}

// Fetch brands
$brands = $pdo->query("SELECT * FROM brands ORDER BY id DESC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Brands - POS System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- DataTables CSS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
</head>
<body>
<?php include 'includes/nav.php'; ?>
<div class="container">
    <h1 class="mb-4">Brands</h1>
    <?php if ($message): ?>
        <div class="alert alert-info"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <!-- Add Brand Form -->
    <div class="card mb-4">
        <div class="card-header">Add New Brand</div>
        <div class="card-body">
            <form method="post">
                <div class="row g-3 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label">Brand Name</label>
                        <input type="text" name="name" class="form-control" placeholder="Brand Name" required>
                    </div>
                    <div class="col-md-2 form-check pt-4">
                        <input class="form-check-input" type="checkbox" name="is_active" id="activeCheck" checked>
                        <label class="form-check-label" for="activeCheck">Active</label>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" name="add_brand" class="btn btn-primary w-100">Add</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Brands List -->
    <table id="brandsTable" class="table table-bordered table-striped">
        <thead>
        <tr>
            <th>ID</th>
            <th>Name</th>
            <th>Status</th>
            <th>Action</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($brands as $br): ?>
            <tr>
                <td><?php echo $br['id']; ?></td>
                <td><?php echo htmlspecialchars($br['name']); ?></td>
                <td><?php echo ($br['is_active'] ? 'Active' : 'Inactive'); ?></td>
                <td>
                    <!-- Trigger edit modal -->
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-toggle="modal" data-bs-target="#editBrandModal<?php echo $br['id']; ?>">Edit</button>
                </td>
            </tr>

            <!-- Edit Brand Modal -->
            <div class="modal fade" id="editBrandModal<?php echo $br['id']; ?>" tabindex="-1" aria-labelledby="editBrandLabel<?php echo $br['id']; ?>" aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="editBrandLabel<?php echo $br['id']; ?>">Edit Brand</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <form method="post">
                                <input type="hidden" name="id" value="<?php echo $br['id']; ?>">
                                <div class="mb-3">
                                    <label class="form-label">Brand Name</label>
                                    <input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($br['name']); ?>" required>
                                </div>
                                <div class="mb-3 form-check">
                                    <input class="form-check-input" type="checkbox" name="is_active" id="activeEdit<?php echo $br['id']; ?>" <?php echo ($br['is_active'] ? 'checked' : ''); ?>>
                                    <label class="form-check-label" for="activeEdit<?php echo $br['id']; ?>">Active</label>
                                </div>
                                <button type="submit" name="update_brand" class="btn btn-primary">Update</button>
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
  // Initialize DataTable for brands
  $(document).ready(function() {
    $('#brandsTable').DataTable();
  });
</script>
</body>
</html>