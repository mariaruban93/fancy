<?php
require_once 'includes/header.php';
// Edit an existing purchase record. Only admin, manager, or inventory officer can access.
checkRole(['admin', 'manager', 'inventory_officer']);

// Get purchase ID
$purchaseId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($purchaseId <= 0) {
    echo "<p>Invalid purchase ID.</p>";
    exit;
}

// Fetch purchase data
$stmt = $pdo->prepare("SELECT p.*, b.name AS branch_name
    FROM purchases p
    JOIN branches b ON p.branch_id = b.id
    WHERE p.id = ?");
$stmt->execute([$purchaseId]);
$purchase = $stmt->fetch();
if (!$purchase) {
    echo "<p>Purchase not found.</p>";
    exit;
}

// Access control: non-admin cannot edit purchases from other branches
if ($user['role_name'] !== 'admin' && $purchase['branch_id'] != $user['branch_id']) {
    echo "<p>You do not have permission to edit this purchase.</p>";
    exit;
}

// Fetch purchase items for pre-filling
$stmtItems = $pdo->prepare("SELECT pi.id, pi.product_id, p.name AS product_name, pi.quantity, pi.batch_no, pi.cost_price, pi.selling_price, pi.expiry_date
    FROM purchase_items pi
    JOIN products p ON pi.product_id = p.id
    WHERE pi.purchase_id = ?");
$stmtItems->execute([$purchaseId]);
$purchaseItems = $stmtItems->fetchAll();

// Fetch all products for live search (id, name, barcode)
$productsList = $pdo->query("SELECT id, name, COALESCE(barcode, '') AS barcode FROM products ORDER BY name ASC")->fetchAll();

// Fetch suppliers for dropdown
$suppliers = [];
try {
    // Restrict suppliers to current branch if branch_id column exists
    $supBranchCheck = $pdo->prepare("SHOW COLUMNS FROM suppliers LIKE 'branch_id'");
    $supBranchCheck->execute();
    $hasSupBranch = (bool)$supBranchCheck->fetch();
    if ($hasSupBranch) {
        $stmtSup = $pdo->prepare("SELECT id, name FROM suppliers WHERE branch_id = ? ORDER BY name ASC");
        $stmtSup->execute([$current_branch_id]);
        $suppliers = $stmtSup->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $suppliers = $pdo->query("SELECT id, name FROM suppliers ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    $suppliers = [];
}

$message = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update'])) {
    // Branch cannot be changed in edit
    $branch_id   = (int)$purchase['branch_id'];
    $supplier_id = isset($_POST['supplier_id']) && $_POST['supplier_id'] !== '' ? (int)$_POST['supplier_id'] : null;
    // New item arrays
    $product_ids    = $_POST['product_id']    ?? [];
    $quantities     = $_POST['quantity']      ?? [];
    $batch_nos      = $_POST['batch_no']      ?? [];
    $cost_prices    = $_POST['cost_price']    ?? [];
    $selling_prices = $_POST['selling_price'] ?? [];
    $expiry_dates   = $_POST['expiry_date']   ?? [];

    // Validate at least one item with positive quantity
    $hasValidItem = false;
    foreach ($product_ids as $idx => $pid) {
        $pid = (int)$pid;
        $qty = isset($quantities[$idx]) ? (int)$quantities[$idx] : 0;
        if ($pid > 0 && $qty > 0) {
            $hasValidItem = true;
            break;
        }
    }

    if ($hasValidItem) {
        try {
            $pdo->beginTransaction();
            // Reverse stock for old items
            $oldItemsStmt = $pdo->prepare("SELECT product_id, batch_no, quantity, cost_price, selling_price, expiry_date FROM purchase_items WHERE purchase_id = ?");
            $oldItemsStmt->execute([$purchaseId]);
            $oldItems = $oldItemsStmt->fetchAll();
            foreach ($oldItems as $old) {
                $pid   = (int)$old['product_id'];
                $batch = trim($old['batch_no'] ?? '');
                $qty   = (int)$old['quantity'];
                // Subtract old quantities from stock
                $upd = $pdo->prepare("UPDATE stock_batches SET quantity = quantity - ? WHERE product_id = ? AND branch_id = ? AND batch_no = ?");
                $upd->execute([$qty, $pid, $branch_id, $batch]);
            }
            // Delete old purchase items
            $delItemsStmt = $pdo->prepare("DELETE FROM purchase_items WHERE purchase_id = ?");
            $delItemsStmt->execute([$purchaseId]);
            // Update purchase record: supplier_id and purchase_date
            $updPurStmt = $pdo->prepare("UPDATE purchases SET supplier_id = ?, purchase_date = NOW(), purchased_by = ? WHERE id = ?");
            $updPurStmt->execute([$supplier_id, $user['id'], $purchaseId]);
            // Insert new items and update stock
            foreach ($product_ids as $idx => $pid) {
                $pid = (int)$pid;
                $qty = isset($quantities[$idx]) ? (int)$quantities[$idx] : 0;
                if ($pid <= 0 || $qty <= 0) continue;
                $batchNo   = trim($batch_nos[$idx] ?? '');
                $costPrice = isset($cost_prices[$idx]) ? (float)$cost_prices[$idx] : 0;
                $sellPrice = isset($selling_prices[$idx]) ? (float)$selling_prices[$idx] : 0;
                $expiry    = trim($expiry_dates[$idx] ?? '');
                // Insert into purchase_items
                $insItemStmt = $pdo->prepare("INSERT INTO purchase_items (purchase_id, product_id, batch_no, quantity, cost_price, selling_price, expiry_date) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $insItemStmt->execute([$purchaseId, $pid, $batchNo, $qty, $costPrice, $sellPrice, $expiry ?: null]);
                // Update or insert stock batch
                $checkStmt = $pdo->prepare("SELECT id FROM stock_batches WHERE product_id = ? AND branch_id = ? AND batch_no = ? LIMIT 1");
                $checkStmt->execute([$pid, $branch_id, $batchNo]);
                $existing = $checkStmt->fetch();
                if ($existing) {
                    $updStmt = $pdo->prepare("UPDATE stock_batches SET quantity = quantity + ?, cost_price = ?, selling_price = ?, expiry_date = ? WHERE id = ?");
                    $updStmt->execute([$qty, $costPrice, $sellPrice, ($expiry ?: null), $existing['id']]);
                } else {
                    $insStmt = $pdo->prepare("INSERT INTO stock_batches (product_id, branch_id, batch_no, quantity, cost_price, selling_price, expiry_date, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
                    $insStmt->execute([$pid, $branch_id, $batchNo, $qty, $costPrice, $sellPrice, ($expiry ?: null)]);
                }
            }
            $pdo->commit();
            $message = 'Purchase updated successfully.';
            // Log the purchase update activity
            try {
                logActivity($pdo, $user['id'], 'Purchase', 'Updated purchase #' . $purchaseId, $branch_id);
            } catch (Throwable $logErr) {
                // Ignore logging errors
            }
            // Refresh purchase data and items after update
            header('Location: purchase_view.php?id=' . $purchaseId);
            exit;
        } catch (Throwable $e) {
            $pdo->rollBack();
            $message = 'Error updating purchase: ' . $e->getMessage();
        }
    } else {
        $message = 'Please provide at least one valid item.';
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Purchase - POS System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Custom styles for live search -->
    <style>
    #purchaseSearchResults .search-item { cursor: pointer; background-color: #fff; }
    #purchaseSearchResults .search-item:hover { background-color: #f5f5f5; }
    </style>
</head>
<body>
<?php include 'includes/nav.php'; ?>
<div class="container">
    <h1 class="mb-4">Edit Purchase #<?php echo $purchase['id']; ?></h1>
    <?php if ($message): ?>
        <div class="alert alert-info"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>
    <div class="card">
        <div class="card-header">Update Purchase</div>
        <div class="card-body">
            <form method="post" id="purchaseForm">
                <input type="hidden" name="update" value="1">
                <!-- Batch info for selected product -->
                <div id="batchInfo" class="mb-3" style="display:none;"></div>
                <!-- Branch is fixed for editing -->
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Branch</label>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($purchase['branch_name']); ?>" readonly>
                        <input type="hidden" name="branch_id" value="<?php echo (int)$purchase['branch_id']; ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Supplier</label>
                        <select name="supplier_id" class="form-select" required>
                            <option value="">Select Supplier</option>
                            <?php foreach ($suppliers as $sup): ?>
                                <option value="<?php echo (int)$sup['id']; ?>" <?php echo ($purchase['supplier_id'] == $sup['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($sup['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <!-- Purchase items table -->
                <div class="table-responsive mt-4">
                    <table class="table table-bordered align-middle">
                        <thead class="table-light">
                            <tr>
                                <th style="min-width:200px;">Product</th>
                                <th style="width:80px;">Qty</th>
                                <th style="width:120px;">Batch No</th>
                                <th style="width:120px;">Cost Price</th>
                                <th style="width:120px;">Selling Price</th>
                                <th style="width:140px;">Expiry Date</th>
                                <th style="width:80px;">Action</th>
                            </tr>
                        </thead>
                        <tbody id="purchaseRows">
                            <!-- Pre-populate existing items -->
                            <?php $rowIndex = 0; foreach ($purchaseItems as $it): ?>
                                <tr data-row-index="<?php echo $rowIndex; ?>">
                                    <td>
                                        <div class="search-container position-relative">
                                            <input type="text" class="form-control product-search" id="productSearch_<?php echo $rowIndex; ?>" placeholder="Search product" autocomplete="off" value="<?php echo htmlspecialchars($it['product_name']); ?>">
                                            <div class="search-results" id="searchResults_<?php echo $rowIndex; ?>" style="position:absolute;top:100%;left:0;right:0;background:#fff;border:1px solid #ddd;border-top:none;border-radius:0 0 5px 5px;max-height:250px;overflow-y:auto;z-index:1000;box-shadow:0 4px 10px rgba(0,0,0,.1);display:none"></div>
                                        </div>
                                        <input type="hidden" name="product_id[]" id="productId_<?php echo $rowIndex; ?>" value="<?php echo (int)$it['product_id']; ?>" required>
                                    </td>
                                    <td><input type="number" name="quantity[]" class="form-control" min="1" value="<?php echo (int)$it['quantity']; ?>" required></td>
                                    <td><input type="text" name="batch_no[]" class="form-control" value="<?php echo htmlspecialchars($it['batch_no']); ?>" required></td>
                                    <td><input type="number" step="0.01" name="cost_price[]" class="form-control" value="<?php echo number_format((float)$it['cost_price'], 2, '.', ''); ?>" required></td>
                                    <td><input type="number" step="0.01" name="selling_price[]" class="form-control" value="<?php echo number_format((float)$it['selling_price'], 2, '.', ''); ?>" required></td>
                                    <td><input type="date" name="expiry_date[]" class="form-control" value="<?php echo $it['expiry_date'] ? htmlspecialchars($it['expiry_date']) : ''; ?>"></td>
                                    <td class="text-center"><button type="button" class="btn btn-sm btn-danger remove-row">Remove</button></td>
                                </tr>
                                <?php $rowIndex++; endforeach; ?>
                        </tbody>
                    </table>
                    <button type="button" id="addRow" class="btn btn-secondary btn-sm mt-2">Add Item</button>
                </div>
                <div class="mt-3">
                    <button type="submit" class="btn btn-primary">Update Purchase</button>
                    <a href="purchase_view.php?id=<?php echo $purchase['id']; ?>" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Provide the product list for live search
const purchaseProducts = <?php echo json_encode($productsList, JSON_UNESCAPED_UNICODE); ?>;
let rowCounter = <?php echo $rowIndex; ?>;
let lastSelectedRowIndex = null;
let lastSelectedProductId = null;

// Function to add a new row
function addPurchaseRow() {
    const tbody = document.getElementById('purchaseRows');
    const rIdx = rowCounter++;
    const tr = document.createElement('tr');
    tr.setAttribute('data-row-index', rIdx);
    tr.innerHTML = `
        <td>
            <div class="search-container position-relative">
                <input type="text" class="form-control product-search" id="productSearch_${rIdx}" placeholder="Search product" autocomplete="off">
                <div class="search-results" id="searchResults_${rIdx}" style="position:absolute;top:100%;left:0;right:0;background:#fff;border:1px solid #ddd;border-top:none;border-radius:0 0 5px 5px;max-height:250px;overflow-y:auto;z-index:1000;box-shadow:0 4px 10px rgba(0,0,0,.1);display:none"></div>
            </div>
            <input type="hidden" name="product_id[]" id="productId_${rIdx}" required>
        </td>
        <td><input type="number" name="quantity[]" class="form-control" min="1" required></td>
        <td><input type="text" name="batch_no[]" class="form-control" required></td>
        <td><input type="number" step="0.01" name="cost_price[]" class="form-control" required></td>
        <td><input type="number" step="0.01" name="selling_price[]" class="form-control" required></td>
        <td><input type="date" name="expiry_date[]" class="form-control"></td>
        <td class="text-center"><button type="button" class="btn btn-sm btn-danger remove-row">Remove</button></td>
    `;
    tbody.appendChild(tr);
    attachSearchEvents(rIdx);
}

// Attach search events for a given row
function attachSearchEvents(index) {
    const searchInput = document.getElementById('productSearch_' + index);
    const resultsDiv  = document.getElementById('searchResults_' + index);
    const hiddenId    = document.getElementById('productId_' + index);
    searchInput.addEventListener('input', function() {
        const query = this.value.trim().toLowerCase();
        if (query.length < 1) {
            resultsDiv.style.display = 'none';
            hiddenId.value = '';
            return;
        }
        const matches = purchaseProducts.filter(p => {
            const nameMatch = p.name && p.name.toLowerCase().includes(query);
            const idMatch   = String(p.id).toLowerCase().includes(query);
            const barMatch  = p.barcode && p.barcode.toLowerCase().includes(query);
            return nameMatch || idMatch || barMatch;
        });
        if (!matches.length) {
            resultsDiv.innerHTML = '<div class="search-item p-2">No products found</div>';
            resultsDiv.style.display = 'block';
            return;
        }
        resultsDiv.innerHTML = matches.map(p => `
            <div class="search-item p-2 border-bottom" data-product-id="${p.id}">
                <div><strong>${p.name}</strong> <small class="text-muted">(ID: ${p.id})</small><br><small class="text-muted">Barcode: ${p.barcode || ''}</small></div>
            </div>
        `).join('');
        resultsDiv.style.display = 'block';
        Array.from(resultsDiv.querySelectorAll('.search-item')).forEach(item => {
            item.addEventListener('click', () => {
                const pid = parseInt(item.getAttribute('data-product-id'));
                const prod = purchaseProducts.find(x => x.id === pid);
                if (prod) {
                    searchInput.value = prod.name;
                    hiddenId.value = prod.id;
                    resultsDiv.style.display = 'none';
                    lastSelectedRowIndex = index;
                    lastSelectedProductId = prod.id;
                    loadExistingBatches(index, prod.id);
                }
            });
        });
    });
}

// Hide search results when clicking outside
document.addEventListener('click', function(e) {
    if (!e.target.closest('.search-container')) {
        document.querySelectorAll('.search-results').forEach(div => { div.style.display = 'none'; });
    }
});

// Load existing stock batches for product and branch
function loadExistingBatches(rowIndex, productId) {
    const container = document.getElementById('batchInfo');
    const branchId = <?php echo (int)$purchase['branch_id']; ?>;
    if (!branchId || !productId) {
        container.style.display = 'none';
        container.innerHTML = '';
        return;
    }
    fetch('ajax_get_product_batches.php?product_id=' + productId + '&branch_id=' + branchId)
        .then(resp => resp.json())
        .then(data => {
            if (!data || !Array.isArray(data.batches) || data.batches.length === 0) {
                container.innerHTML = '<div class="alert alert-info">No existing batches for this product in the selected branch.</div>';
                container.style.display = 'block';
                return;
            }
            let html = '<div class="card"><div class="card-header"><strong>Existing Batches</strong></div><div class="card-body p-2"><div class="table-responsive"><table class="table table-sm table-bordered mb-0"><thead><tr><th>Batch No</th><th>Quantity</th><th>Cost Price</th><th>Selling Price</th><th>Expiry Date</th></tr></thead><tbody>';
            data.batches.forEach(b => {
                html += `<tr><td>${b.batch_no || 'N/A'}</td><td>${b.quantity}</td><td>Rs. ${Number(b.cost_price).toFixed(2)}</td><td>Rs. ${Number(b.selling_price).toFixed(2)}</td><td>${b.expiry_date || 'N/A'}</td></tr>`;
            });
            html += '</tbody></table></div></div></div>';
            container.innerHTML = html;
            container.style.display = 'block';
        })
        .catch(err => {
            console.error('Failed to load existing batches', err);
        });
}

// Initialize
document.addEventListener('DOMContentLoaded', function() {
    // Attach search events for existing rows
    for (let i = 0; i < rowCounter; i++) {
        attachSearchEvents(i);
    }
    // Add new row button handler
    document.getElementById('addRow').addEventListener('click', function() {
        addPurchaseRow();
    });
    // Remove row handler using event delegation
    document.getElementById('purchaseRows').addEventListener('click', function(e) {
        if (e.target.classList.contains('remove-row')) {
            const tr = e.target.closest('tr');
            if (tr) {
                const idx = parseInt(tr.getAttribute('data-row-index'));
                if (idx === lastSelectedRowIndex) {
                    lastSelectedRowIndex = null;
                    lastSelectedProductId = null;
                    const container = document.getElementById('batchInfo');
                    container.style.display = 'none';
                    container.innerHTML = '';
                }
                tr.remove();
            }
        }
    });
});
</script>
</body>
</html>