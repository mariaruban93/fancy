<?php
require_once 'includes/header.php';
// Only admin, manager, or inventory officer can access this page
checkRole(['admin', 'manager', 'inventory_officer']);

$message = '';

// Add new product
if (isset($_POST['add_product'])) {
    $name          = trim($_POST['name'] ?? '');
    // Capture size. Keep optional so existing forms without size still work.
    $size          = trim($_POST['size'] ?? '');
    $barcode       = trim($_POST['barcode'] ?? '');
    // We no longer collect cost_pin at product level; this value is set per batch on purchase
    $category_id   = isset($_POST['category_id']) && $_POST['category_id'] !== '' ? (int)$_POST['category_id'] : null;
    $brand_id      = isset($_POST['brand_id']) && $_POST['brand_id'] !== '' ? (int)$_POST['brand_id'] : null;
    $unit_id       = isset($_POST['unit_id']) && $_POST['unit_id'] !== '' ? (int)$_POST['unit_id'] : null;
    $cost_price    = (float)($_POST['cost_price'] ?? 0);
    $selling_price = (float)($_POST['selling_price'] ?? 0);
    $description   = trim($_POST['description'] ?? '');
    if ($name) {
        $stmt = $pdo->prepare(
            // Insert product without cost_pin column (deprecated)
            "INSERT INTO products (name, size, barcode, category_id, brand_id, unit_id, cost_price, selling_price, description) " .
            "VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $name,
            $size,
            $barcode,
            $category_id,
            $brand_id,
            $unit_id,
            $cost_price,
            $selling_price,
            $description
        ]);
        $message = 'Product added successfully!';
    } else {
        $message = 'Product name is required';
    }
}

// Update product
if (isset($_POST['update_product'])) {
    $id            = (int)$_POST['id'];
    $name          = trim($_POST['name']);
    // Capture size for update
    $size          = trim($_POST['size'] ?? '');
    $barcode       = trim($_POST['barcode']);
    // We no longer collect cost_pin at product level; ignore cost_pin from the form
    $category_id   = isset($_POST['category_id']) && $_POST['category_id'] !== '' ? (int)$_POST['category_id'] : null;
    $brand_id      = isset($_POST['brand_id']) && $_POST['brand_id'] !== '' ? (int)$_POST['brand_id'] : null;
    $unit_id       = isset($_POST['unit_id']) && $_POST['unit_id'] !== '' ? (int)$_POST['unit_id'] : null;
    $cost_price    = (float)($_POST['cost_price']);
    $selling_price = (float)($_POST['selling_price']);
    $description   = trim($_POST['description']);
    if ($name) {
        $stmt = $pdo->prepare(
            "UPDATE products SET name = ?, size = ?, barcode = ?, category_id = ?, brand_id = ?, unit_id = ?, cost_price = ?, selling_price = ?, description = ? WHERE id = ?"
        );
        $stmt->execute([
            $name,
            $size,
            $barcode,
            $category_id,
            $brand_id,
            $unit_id,
            $cost_price,
            $selling_price,
            $description,
            $id
        ]);
        $message = 'Product updated successfully!';
    } else {
        $message = 'Product name is required';
    }
}

// Fetch categories, brands, and units for dropdowns
$categories = $pdo->query("SELECT id, name FROM categories WHERE is_active = 1 ORDER BY name")->fetchAll();
$brands     = $pdo->query("SELECT id, name FROM brands WHERE is_active = 1 ORDER BY name")->fetchAll();
$units      = $pdo->query("SELECT id, name FROM units ORDER BY name")->fetchAll();

// Fetch products with category, brand, and unit names
$products = $pdo->query(
    "SELECT p.*, c.name AS category_name, b.name AS brand_name, u.name AS unit_name " .
    "FROM products p " .
    "LEFT JOIN categories c ON p.category_id = c.id " .
    "LEFT JOIN brands b ON p.brand_id = b.id " .
    "LEFT JOIN units u ON p.unit_id = u.id " .
    "ORDER BY p.id DESC"
)->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Products - POS System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<?php include 'includes/nav.php'; ?>
<div class="container">
    <h1 class="mb-4">Products</h1>
    <?php if ($message): ?>
        <div class="alert alert-info"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>
    <!-- Add Product Form -->
    <div class="card mb-4">
        <div class="card-header">Add New Product  <span style="float:right;"><a href="bulk_upload.php">Bulk Upload</a></span></div>
        <div class="card-body">
            <form method="post">
                <div class="row g-3">
                    <div class="col-md-3">
                        <input type="text" name="name" class="form-control" placeholder="Product Name" required>
                    </div>
                    <!-- Size field: optional text; added after name -->
                    <div class="col-md-2">
                        <input type="text" name="size" class="form-control" placeholder="Size">
                    </div>
                    <div class="col-md-2">
                        <input type="text" name="barcode" class="form-control" placeholder="Barcode">
                    </div>
                    <!-- Cost Pin is now managed per batch on purchase; removed from product form -->
                    <div class="col-md-2">
                        <select name="category_id" class="form-select" aria-label="Category">
                            <option value="">Category</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select name="brand_id" class="form-select" aria-label="Brand">
                            <option value="">Brand</option>
                            <?php foreach ($brands as $br): ?>
                                <option value="<?php echo $br['id']; ?>"><?php echo htmlspecialchars($br['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select name="unit_id" class="form-select" aria-label="Unit">
                            <option value="">Unit</option>
                            <?php foreach ($units as $un): ?>
                                <option value="<?php echo $un['id']; ?>"><?php echo htmlspecialchars($un['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-1">
                        <input type="number" step="0.01" name="cost_price" class="form-control" placeholder="Cost" required>
                    </div>
                    <div class="col-md-1">
                        <input type="number" step="0.01" name="selling_price" class="form-control" placeholder="Price" required>
                    </div>
                    <div class="col-md-1">
                        <button type="submit" name="add_product" class="btn btn-primary w-100">Add</button>
                    </div>
                    <div class="col-md-12">
                        <textarea name="description" class="form-control" rows="2" placeholder="Description"></textarea>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Products List -->
    <table class="table table-bordered table-striped">
        <thead>
        <tr>
            <th>ID</th>
            <th>Name</th>
            <th>Size</th>
            <th>Barcode</th>
            <th>Category</th>
            <th>Brand</th>
            <th>Unit</th>
            <th>Cost</th>
            <th>Price</th>
            <th>Description</th>
            <th>Action</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($products as $p): ?>
            <tr>
                <td><?php echo $p['id']; ?></td>
                <td><?php echo htmlspecialchars($p['name']); ?></td>
                <td><?php echo htmlspecialchars($p['size'] ?? ''); ?></td>
                <td><?php echo htmlspecialchars($p['barcode']); ?></td>
                <td><?php echo htmlspecialchars($p['category_name'] ?? ''); ?></td>
                <td><?php echo htmlspecialchars($p['brand_name'] ?? ''); ?></td>
                <td><?php echo htmlspecialchars($p['unit_name'] ?? ''); ?></td>
                <td><?php echo number_format($p['cost_price'], 2); ?></td>
                <td><?php echo number_format($p['selling_price'], 2); ?></td>
                <td><?php echo htmlspecialchars($p['description']); ?></td>
                <td>
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-toggle="modal" data-bs-target="#editProductModal<?php echo $p['id']; ?>">Edit</button>
                </td>
            </tr>

            <!-- Edit Product Modal -->
            <div class="modal fade" id="editProductModal<?php echo $p['id']; ?>" tabindex="-1" aria-labelledby="editProductLabel<?php echo $p['id']; ?>" aria-hidden="true">
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="editProductLabel<?php echo $p['id']; ?>">Edit Product</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <form method="post">
                                <input type="hidden" name="id" value="<?php echo $p['id']; ?>">
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <label class="form-label">Product Name</label>
                                        <input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($p['name']); ?>" required>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Size</label>
                                        <input type="text" name="size" class="form-control" value="<?php echo htmlspecialchars($p['size'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Barcode</label>
                                        <input type="text" name="barcode" class="form-control" value="<?php echo htmlspecialchars($p['barcode']); ?>">
                                    </div>
                                    <!-- Cost Pin removed; managed per batch on purchase -->
                                    <div class="col-md-3">
                                        <label class="form-label">Category</label>
                                        <select name="category_id" class="form-select">
                                            <option value="">None</option>
                                            <?php foreach ($categories as $cat): ?>
                                                <option value="<?php echo $cat['id']; ?>" <?php echo ($p['category_id'] == $cat['id']) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($cat['name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Brand</label>
                                        <select name="brand_id" class="form-select">
                                            <option value="">None</option>
                                            <?php foreach ($brands as $br): ?>
                                                <option value="<?php echo $br['id']; ?>" <?php echo ($p['brand_id'] == $br['id']) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($br['name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Unit</label>
                                        <select name="unit_id" class="form-select">
                                            <option value="">None</option>
                                            <?php foreach ($units as $un): ?>
                                                <option value="<?php echo $un['id']; ?>" <?php echo ($p['unit_id'] == $un['id']) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($un['name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Cost Price</label>
                                        <input type="number" step="0.01" name="cost_price" class="form-control" value="<?php echo $p['cost_price']; ?>" required>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Selling Price</label>
                                        <input type="number" step="0.01" name="selling_price" class="form-control" value="<?php echo $p['selling_price']; ?>" required>
                                    </div>
                                    <div class="col-md-12">
                                        <label class="form-label">Description</label>
                                        <textarea name="description" class="form-control" rows="3"><?php echo htmlspecialchars($p['description']); ?></textarea>
                                    </div>
                                </div>
                                <div class="mt-3">
                                    <button type="submit" name="update_product" class="btn btn-primary">Update Product</button>
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
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>