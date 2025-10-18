<?php
require_once 'includes/header.php';
// Only admin, manager, or inventory officer can access this page
checkRole(['admin', 'manager', 'inventory_officer']);

$message = '';

// Add new category
if (isset($_POST['add_category'])) {
    $name = trim($_POST['name'] ?? '');
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    if ($name) {
        $stmt = $pdo->prepare("INSERT INTO categories (name, is_active) VALUES (?, ?)");
        $stmt->execute([$name, $is_active]);
        $message = 'Category added successfully!';
    } else {
        $message = 'Category name is required';
    }
}

// Update category
if (isset($_POST['update_category'])) {
    $id = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    if ($id && $name) {
        $stmt = $pdo->prepare("UPDATE categories SET name = ?, is_active = ? WHERE id = ?");
        $stmt->execute([$name, $is_active, $id]);
        $message = 'Category updated successfully!';
    } else {
        $message = 'Category name is required';
    }
}

// Fetch categories
$categories = $pdo->query("SELECT * FROM categories ORDER BY id DESC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Categories - POS System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- DataTables CSS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
</head>
<body>
<?php include 'includes/nav.php'; ?>
<div class="container">
    <h1 class="mb-4">Categories</h1>
    <?php if ($message): ?>
        <div class="alert alert-info"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <!-- Add Category Form -->
    <div class="card mb-4">
        <div class="card-header">Add New Category</div>
        <div class="card-body">
            <form method="post">
                <div class="row g-3 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label">Category Name</label>
                        <input type="text" name="name" class="form-control" placeholder="Category Name" required>
                    </div>
                    <div class="col-md-2 form-check pt-4">
                        <input class="form-check-input" type="checkbox" name="is_active" id="activeCheck" checked>
                        <label class="form-check-label" for="activeCheck">Active</label>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" name="add_category" class="btn btn-primary w-100">Add</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Categories List -->
    <table id="categoriesTable" class="table table-bordered table-striped">
        <thead>
        <tr>
            <th>ID</th>
            <th>Name</th>
            <th>Status</th>
            <th>Action</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($categories as $cat): ?>
            <tr>
                <td><?php echo $cat['id']; ?></td>
                <td><?php echo htmlspecialchars($cat['name']); ?></td>
                <td><?php echo ($cat['is_active'] ? 'Active' : 'Inactive'); ?></td>
                <td>
                    <!-- Trigger edit modal -->
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-toggle="modal" data-bs-target="#editCategoryModal<?php echo $cat['id']; ?>">Edit</button>
                </td>
            </tr>

            <!-- Edit Category Modal -->
            <div class="modal fade" id="editCategoryModal<?php echo $cat['id']; ?>" tabindex="-1" aria-labelledby="editCategoryLabel<?php echo $cat['id']; ?>" aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="editCategoryLabel<?php echo $cat['id']; ?>">Edit Category</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <form method="post">
                                <input type="hidden" name="id" value="<?php echo $cat['id']; ?>">
                                <div class="mb-3">
                                    <label class="form-label">Category Name</label>
                                    <input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($cat['name']); ?>" required>
                                </div>
                                <div class="mb-3 form-check">
                                    <input class="form-check-input" type="checkbox" name="is_active" id="activeEdit<?php echo $cat['id']; ?>" <?php echo ($cat['is_active'] ? 'checked' : ''); ?>>
                                    <label class="form-check-label" for="activeEdit<?php echo $cat['id']; ?>">Active</label>
                                </div>
                                <button type="submit" name="update_category" class="btn btn-primary">Update</button>
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
  // Initialize DataTable for categories
  $(document).ready(function() {
    $('#categoriesTable').DataTable();
  });
</script>
</body>
</html>