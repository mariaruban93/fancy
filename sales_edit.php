<?php
require_once 'includes/header.php';
// Edit an existing sale. Only admin, manager, or cashier (who made the sale) may edit.
checkRole(['admin', 'manager', 'cashier']);

// Get sale ID
$saleId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($saleId <= 0) {
    echo "<p>Invalid sale ID.</p>";
    exit;
}

// Fetch sale details
$stmtSale = $pdo->prepare("SELECT * FROM sales WHERE id = ?");
$stmtSale->execute([$saleId]);
$sale = $stmtSale->fetch();
if (!$sale) {
    echo "<p>Sale not found.</p>";
    exit;
}

// Permission check: admin can edit all; manager can edit sales in their branch; cashier can edit their own sales
$allow = false;
if ($user['role_name'] === 'admin') {
    $allow = true;
} elseif ($user['role_name'] === 'manager' && $sale['branch_id'] == $user['branch_id']) {
    $allow = true;
} elseif ($user['role_name'] === 'cashier' && $sale['sold_by'] == $user['id']) {
    $allow = true;
}
if (!$allow) {
    echo "<p>You do not have permission to edit this sale.</p>";
    exit;
}

// Fetch sale items for prefill
$stmtItems = $pdo->prepare("SELECT si.product_id, p.name AS product_name, si.quantity, si.selling_price
    FROM sale_items si
    JOIN products p ON si.product_id = p.id
    WHERE si.sale_id = ?");
$stmtItems->execute([$saleId]);
$saleItems = $stmtItems->fetchAll();

// Fetch sale payments
$stmtPays = $pdo->prepare("SELECT method, amount FROM sale_payments WHERE sale_id = ?");
$stmtPays->execute([$saleId]);
$salePayments = $stmtPays->fetchAll();

// Fetch product list for live search
$productsList = $pdo->query("SELECT id, name, COALESCE(barcode, '') AS barcode FROM products ORDER BY name ASC")->fetchAll();

// Payment methods list (predefined)
$availableMethods = [
    ['value' => 'cash', 'label' => 'Cash'],
    ['value' => 'card', 'label' => 'Card'],
    ['value' => 'upi', 'label' => 'UPI'],
    ['value' => 'bank_transfer', 'label' => 'Bank Transfer'],
    ['value' => 'cheque', 'label' => 'Cheque'],
];

$message = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update'])) {
    // Branch is fixed; we cannot change branch
    $branch_id = (int)$sale['branch_id'];
    // Retrieve arrays for items
    $product_ids = $_POST['product_id'] ?? [];
    $quantities  = $_POST['quantity'] ?? [];
    $prices      = $_POST['price'] ?? [];
    // Retrieve payments arrays
    $pay_methods = $_POST['pay_method'] ?? [];
    $pay_amounts = $_POST['pay_amount'] ?? [];

    // Validate at least one item
    $hasValid = false;
    foreach ($product_ids as $idx => $pid) {
        $pid = (int)$pid;
        $qty = isset($quantities[$idx]) ? (int)$quantities[$idx] : 0;
        $price = isset($prices[$idx]) ? (float)$prices[$idx] : 0;
        if ($pid > 0 && $qty > 0 && $price >= 0) {
            $hasValid = true;
            break;
        }
    }
    if ($hasValid) {
        // Prepare payments array
        $newPayments = [];
        $totalPaymentAmount = 0;
        foreach ($pay_methods as $idx => $method) {
            $m = trim($method);
            $amt = isset($pay_amounts[$idx]) ? (float)$pay_amounts[$idx] : 0;
            if ($m !== '' && $amt >= 0) {
                $newPayments[] = ['method' => $m, 'amount' => $amt];
                $totalPaymentAmount += $amt;
            }
        }
        try {
            $pdo->beginTransaction();
            // Revert old sale items (add quantity back to stock)
            $oldItemsStmt = $pdo->prepare("SELECT batch_id, quantity FROM sale_items WHERE sale_id = ?");
            $oldItemsStmt->execute([$saleId]);
            $oldItems = $oldItemsStmt->fetchAll();
            foreach ($oldItems as $it) {
                $batchId = (int)$it['batch_id'];
                $qty     = (int)$it['quantity'];
                $updStmt = $pdo->prepare("UPDATE stock_batches SET quantity = quantity + ? WHERE id = ?");
                $updStmt->execute([$qty, $batchId]);
            }
            // Delete old sale items and payments
            $pdo->prepare("DELETE FROM sale_items WHERE sale_id = ?")->execute([$saleId]);
            $pdo->prepare("DELETE FROM sale_payments WHERE sale_id = ?")->execute([$saleId]);
            // Update sale record with new sold_by and date
            $pdo->prepare("UPDATE sales SET sale_date = NOW(), sold_by = ?, total_amount = 0 WHERE id = ?")->execute([$user['id'], $saleId]);
            // Insert new items
            $totalAmount = 0;
            foreach ($product_ids as $idx => $pid) {
                $pid = (int)$pid;
                $qty = isset($quantities[$idx]) ? (int)$quantities[$idx] : 0;
                $price = isset($prices[$idx]) ? (float)$prices[$idx] : 0;
                if ($pid <= 0 || $qty <= 0 || $price < 0) continue;
                $remaining = $qty;
                // Fetch available batches for this product in the branch
                $batchStmt = $pdo->prepare(
                    "SELECT id, quantity FROM stock_batches WHERE product_id = ? AND branch_id = ? AND quantity > 0 ORDER BY expiry_date IS NULL, expiry_date ASC, id ASC"
                );
                $batchStmt->execute([$pid, $branch_id]);
                $batches = $batchStmt->fetchAll();
                $allocated = 0;
                foreach ($batches as $b) {
                    if ($remaining <= 0) break;
                    $ded = min($remaining, (int)$b['quantity']);
                    // Insert sale item with this batch
                    $pdo->prepare("INSERT INTO sale_items (sale_id, product_id, batch_id, quantity, selling_price) VALUES (?, ?, ?, ?, ?)")
                        ->execute([$saleId, $pid, $b['id'], $ded, $price]);
                    // Deduct from batch
                    $pdo->prepare("UPDATE stock_batches SET quantity = quantity - ? WHERE id = ?")
                        ->execute([$ded, $b['id']]);
                    $remaining -= $ded;
                    $allocated += $ded;
                }
                // If we couldn't allocate full quantity, rollback with error
                if ($remaining > 0) {
                    throw new Exception('Insufficient stock for product ID ' . $pid);
                }
                // Accumulate total amount using full quantity times price
                $totalAmount += $price * $qty;
            }
            // Insert payment records
            if (!empty($newPayments)) {
                $payStmt = $pdo->prepare("INSERT INTO sale_payments (sale_id, method, amount) VALUES (?, ?, ?)");
                foreach ($newPayments as $pm) {
                    $payStmt->execute([$saleId, $pm['method'], $pm['amount']]);
                }
            }
            // Update sales total amount
            $pdo->prepare("UPDATE sales SET total_amount = ? WHERE id = ?")->execute([$totalAmount, $saleId]);
            $pdo->commit();
            $message = 'Sale updated successfully.';
            // Log sale update activity
            try {
                logActivity($pdo, $user['id'], 'Sale', 'Updated sale #' . $saleId, $branch_id);
            } catch (Throwable $logErr) {
                // Ignore logging errors
            }
            header('Location: sales_view.php?id=' . $saleId);
            exit;
        } catch (Throwable $e) {
            $pdo->rollBack();
            $message = 'Error updating sale: ' . $e->getMessage();
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
    <title>Edit Sale - POS System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
    /* Style for live search results */
    .search-item { cursor: pointer; background-color: #fff; }
    .search-item:hover { background-color: #f5f5f5; }
    </style>
</head>
<body>
<?php include 'includes/nav.php'; ?>
<div class="container">
    <h1 class="mb-4">Edit Sale #<?php echo $sale['id']; ?></h1>
    <?php if ($message): ?>
        <div class="alert alert-info"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>
    <div class="card">
        <div class="card-header">Update Sale</div>
        <div class="card-body">
            <form method="post" id="saleForm">
                <input type="hidden" name="update" value="1">
                <!-- Branch (read only) -->
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Branch</label>
                        <?php
                            $branchNameStmt = $pdo->prepare("SELECT name FROM branches WHERE id = ?");
                            $branchNameStmt->execute([$sale['branch_id']]);
                            $branchName = $branchNameStmt->fetchColumn();
                        ?>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($branchName); ?>" readonly>
                        <input type="hidden" name="branch_id" value="<?php echo (int)$sale['branch_id']; ?>">
                    </div>
                </div>
                <!-- Sale items table -->
                <div class="table-responsive mt-4">
                    <table class="table table-bordered align-middle">
                        <thead class="table-light">
                            <tr>
                                <th style="min-width:200px;">Product</th>
                                <th style="width:80px;">Qty</th>
                                <th style="width:120px;">Price</th>
                                <th style="width:80px;">Action</th>
                            </tr>
                        </thead>
                        <tbody id="saleRows">
                            <!-- Pre-populate rows -->
                            <?php $rowIndex = 0; foreach ($saleItems as $it): ?>
                                <tr data-row-index="<?php echo $rowIndex; ?>">
                                    <td>
                                        <div class="search-container position-relative">
                                            <input type="text" class="form-control product-search" id="productSearch_<?php echo $rowIndex; ?>" placeholder="Search product" autocomplete="off" value="<?php echo htmlspecialchars($it['product_name']); ?>">
                                            <div class="search-results" id="searchResults_<?php echo $rowIndex; ?>" style="position:absolute;top:100%;left:0;right:0;background:#fff;border:1px solid #ddd;border-top:none;border-radius:0 0 5px 5px;max-height:250px;overflow-y:auto;z-index:1000;box-shadow:0 4px 10px rgba(0,0,0,.1);display:none"></div>
                                        </div>
                                        <input type="hidden" name="product_id[]" id="productId_<?php echo $rowIndex; ?>" value="<?php echo (int)$it['product_id']; ?>" required>
                                    </td>
                                    <td><input type="number" name="quantity[]" class="form-control" min="1" value="<?php echo (int)$it['quantity']; ?>" required></td>
                                    <td><input type="number" step="0.01" name="price[]" class="form-control" value="<?php echo number_format((float)$it['selling_price'], 2, '.', ''); ?>" required></td>
                                    <td class="text-center"><button type="button" class="btn btn-sm btn-danger remove-row">Remove</button></td>
                                </tr>
                                <?php $rowIndex++; endforeach; ?>
                        </tbody>
                    </table>
                    <button type="button" id="addRow" class="btn btn-secondary btn-sm mt-2">Add Item</button>
                </div>
                <!-- Payment methods -->
                <h4 class="mt-4">Payment Methods</h4>
                <div id="paymentContainer">
                    <?php $pIdx = 0; foreach ($salePayments as $pm): ?>
                    <div class="row g-2 mb-2 payment-row" data-pay-index="<?php echo $pIdx; ?>">
                        <div class="col-5">
                            <select name="pay_method[]" class="form-select">
                                <?php foreach ($availableMethods as $m): ?>
                                    <option value="<?php echo $m['value']; ?>" <?php echo ($pm['method'] == $m['value']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($m['label']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-5"><input type="number" name="pay_amount[]" class="form-control" min="0" step="0.01" value="<?php echo number_format((float)$pm['amount'], 2, '.', ''); ?>"></div>
                        <div class="col-2 text-end"><button type="button" class="btn btn-sm btn-danger remove-pay"><i class="bi bi-trash"></i></button></div>
                    </div>
                    <?php $pIdx++; endforeach; ?>
                </div>
                <button type="button" id="addPayment" class="btn btn-sm btn-outline-primary mb-3"><i class="bi bi-plus"></i> Add Payment Method</button>
                <div class="mt-3">
                    <button type="submit" class="btn btn-primary">Update Sale</button>
                    <a href="sales_view.php?id=<?php echo $saleId; ?>" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// JS for sale edit page
const products = <?php echo json_encode($productsList, JSON_UNESCAPED_UNICODE); ?>;
let rowCounter = <?php echo $rowIndex; ?>;

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
        const matches = products.filter(p => {
            return (p.name && p.name.toLowerCase().includes(query)) || String(p.id).includes(query) || (p.barcode && p.barcode.toLowerCase().includes(query));
        });
        if (matches.length === 0) {
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
                const prod = products.find(x => x.id === pid);
                if (prod) {
                    searchInput.value = prod.name;
                    hiddenId.value = prod.id;
                    resultsDiv.style.display = 'none';
                }
            });
        });
    });
}

// Initialize search events for prepopulated rows
document.addEventListener('DOMContentLoaded', function() {
    for (let i = 0; i < rowCounter; i++) {
        attachSearchEvents(i);
    }
    // Add new row for sale item
    document.getElementById('addRow').addEventListener('click', function() {
        const tbody = document.getElementById('saleRows');
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
            <td><input type="number" step="0.01" name="price[]" class="form-control" required></td>
            <td class="text-center"><button type="button" class="btn btn-sm btn-danger remove-row">Remove</button></td>
        `;
        tbody.appendChild(tr);
        attachSearchEvents(rIdx);
    });
    // Remove item row
    document.getElementById('saleRows').addEventListener('click', function(e) {
        if (e.target.classList.contains('remove-row')) {
            const tr = e.target.closest('tr');
            if (tr) tr.remove();
        }
    });
    // Add payment method row
    document.getElementById('addPayment').addEventListener('click', function() {
        const pc = document.getElementById('paymentContainer');
        const div = document.createElement('div');
        div.className = 'row g-2 mb-2 payment-row';
        div.innerHTML = `
            <div class="col-5">
                <select name="pay_method[]" class="form-select">
                    <?php foreach ($availableMethods as $m): ?>
                        <option value="<?php echo $m['value']; ?>"><?php echo htmlspecialchars($m['label']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-5"><input type="number" name="pay_amount[]" class="form-control" min="0" step="0.01"></div>
            <div class="col-2 text-end"><button type="button" class="btn btn-sm btn-danger remove-pay"><i class="bi bi-trash"></i></button></div>
        `;
        pc.appendChild(div);
    });
    // Remove payment row
    document.getElementById('paymentContainer').addEventListener('click', function(e) {
        if (e.target.closest('.remove-pay')) {
            const row = e.target.closest('.payment-row');
            if (row) row.remove();
        }
    });
});
</script>
</body>
</html>