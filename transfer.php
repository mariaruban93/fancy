<?php
require_once 'includes/header.php';

// -----------------------------------------------------------------------------
// Multi-item transfer support
// -----------------------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Reset cart on user request
if (isset($_POST['reset_cart'])) {
    $_SESSION['transfer_cart'] = [];
    unset($_SESSION['transfer_from_branch']);
    unset($_SESSION['transfer_to_branch']);
}

// Ensure cart exists
if (!isset($_SESSION['transfer_cart'])) {
    $_SESSION['transfer_cart'] = [];
}

// Only admin or manager can access this page
checkRole(['admin', 'manager']);

$message = '';

// Determine current cart context: if a cart exists, populate from and to branch
$cart_from_branch = $_SESSION['transfer_from_branch'] ?? null;
$cart_to_branch   = $_SESSION['transfer_to_branch'] ?? null;

// Fetch branches
$allBranches = $pdo->query("SELECT id, name FROM branches ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Build a lookup of branch IDs to names for easier display later
$branchNames = [];
foreach ($allBranches as $b) {
    $branchNames[(int)$b['id']] = $b['name'];
}

// Determine from_branch for manager
if (($user['role_name'] ?? '') === 'manager') {
    $from_branch_id_default = (int)$user['branch_id'];
}

// Determine selected_from_branch: priority is POST data, then session cart, then default for managers
$selected_from_branch = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selected_from_branch = isset($_POST['from_branch_id']) && $_POST['from_branch_id'] !== ''
        ? (int)$_POST['from_branch_id']
        : null;
}
if ($selected_from_branch === null && $cart_from_branch) {
    $selected_from_branch = (int)$cart_from_branch;
}
if ($selected_from_branch === null && isset($from_branch_id_default)) {
    $selected_from_branch = (int)$from_branch_id_default;
}

// Query stock batches for selected branch
$batchList = [];
if ($selected_from_branch) {
    $batchStmt = $pdo->prepare("
        SELECT sb.*, p.name AS product_name
        FROM stock_batches sb
        JOIN products p ON sb.product_id = p.id
        WHERE sb.branch_id = ? AND sb.quantity > 0
        ORDER BY p.name, sb.batch_no
    ");
    $batchStmt->execute([$selected_from_branch]);
    $batchList = $batchStmt->fetchAll(PDO::FETCH_ASSOC);
}

// Build list of available products (with stock) for the selected branch to support live search
$availableProducts = [];
if ($selected_from_branch) {
    $stmt = $pdo->prepare(
        "SELECT p.id, p.name, p.barcode
         FROM products p
         JOIN stock_batches sb ON sb.product_id = p.id
         WHERE sb.branch_id = ? AND sb.quantity > 0
         GROUP BY p.id, p.name, p.barcode
         ORDER BY p.name ASC"
    );
    $stmt->execute([$selected_from_branch]);
    $availableProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Handle item addition to transfer cart
if (isset($_POST['add_item'])) {
    $from_branch_id = (int)($_POST['from_branch_id'] ?? 0);
    $to_branch_id   = (int)($_POST['to_branch_id'] ?? 0);
    $batch_id       = (int)($_POST['batch_id'] ?? 0);
    $quantity       = (int)($_POST['quantity'] ?? 0);

    // Validate input
    if (!$from_branch_id || !$to_branch_id || !$batch_id || $quantity <= 0) {
        $message = 'Please fill all fields';
    } else {
        // Ensure cart is either empty or matches the chosen branches
        if (!empty($_SESSION['transfer_cart'])) {
            if ((int)$_SESSION['transfer_from_branch'] !== $from_branch_id
                || (int)$_SESSION['transfer_to_branch'] !== $to_branch_id) {
                $message = 'Cannot mix different source/destination branches in one transfer. Please reset cart first.';
            }
        }
        if (!$message) {
            // Fetch batch details for stock and product information
            $stmt = $pdo->prepare("
                SELECT sb.*, p.name AS product_name
                FROM stock_batches sb
                JOIN products p ON p.id = sb.product_id
                WHERE sb.id = ? AND sb.branch_id = ?
            ");
            $stmt->execute([$batch_id, $from_branch_id]);
            $batch = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$batch || (int)$batch['quantity'] < $quantity) {
                $message = 'Invalid batch or insufficient quantity';
            } else {
                // Set cart branch context if not already set
                if (empty($_SESSION['transfer_cart'])) {
                    $_SESSION['transfer_from_branch'] = $from_branch_id;
                    $_SESSION['transfer_to_branch']   = $to_branch_id;
                }

                // Add item to cart
                $_SESSION['transfer_cart'][] = [
                    'batch_id'      => (int)$batch_id,
                    'product_id'    => (int)$batch['product_id'],
                    'product_name'  => (string)$batch['product_name'],
                    'batch_no'      => (string)$batch['batch_no'],
                    'quantity'      => (int)$quantity,
                    'cost_price'    => (float)$batch['cost_price'],
                    'selling_price' => (float)$batch['selling_price'],
                    'expiry_date'   => $batch['expiry_date'], // may be null
                ];
                $message = 'Item added to transfer cart.';
            }
        }
    }

    // Refresh batch list after add
    $selected_from_branch = $from_branch_id;
    if ($selected_from_branch) {
        $batchStmt = $pdo->prepare("
            SELECT sb.*, p.name AS product_name
            FROM stock_batches sb
            JOIN products p ON sb.product_id = p.id
            WHERE sb.branch_id = ? AND sb.quantity > 0
            ORDER BY p.name, sb.batch_no
        ");
        $batchStmt->execute([$selected_from_branch]);
        $batchList = $batchStmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Handle removal of an item from the cart
if (isset($_POST['remove_index'])) {
    $idx = (int)$_POST['remove_index'];
    if (isset($_SESSION['transfer_cart'][$idx])) {
        array_splice($_SESSION['transfer_cart'], $idx, 1);
        $message = 'Item removed.';
        // If cart becomes empty, unset branch context
        if (empty($_SESSION['transfer_cart'])) {
            unset($_SESSION['transfer_from_branch'], $_SESSION['transfer_to_branch']);
        }
    }
}

// Handle finalisation of transfer cart
if (isset($_POST['finalize_transfer'])) {
    if (empty($_SESSION['transfer_cart'])) {
        $message = 'No items in transfer cart';
    } else {
        $from_branch_id = (int)($_SESSION['transfer_from_branch'] ?? 0);
        $to_branch_id   = (int)($_SESSION['transfer_to_branch'] ?? 0);

        if (!$from_branch_id || !$to_branch_id) {
            $message = 'Invalid branch selection';
        } else {
            $pdo->beginTransaction();
            try {
                // Insert master transfer record
                $stmt = $pdo->prepare("
                    INSERT INTO stock_transfers (from_branch_id, to_branch_id, transferred_by, transferred_at)
                    VALUES (?, ?, ?, NOW())
                ");
                $stmt->execute([$from_branch_id, $to_branch_id, $user['id']]);
                $transfer_id = (int)$pdo->lastInsertId();

                // Process each cart item
                foreach ($_SESSION['transfer_cart'] as $item) {
                    $batch_id = (int)$item['batch_id'];
                    $qty      = (int)$item['quantity'];

                    // Re-fetch and lock source batch
                    $stmt = $pdo->prepare("SELECT * FROM stock_batches WHERE id = ? AND branch_id = ? FOR UPDATE");
                    $stmt->execute([$batch_id, $from_branch_id]);
                    $batch = $stmt->fetch(PDO::FETCH_ASSOC);

                    if (!$batch || (int)$batch['quantity'] < $qty) {
                        throw new Exception('Insufficient stock for batch ' . $batch_id);
                    }

                    // Insert transfer item row
                    $stmt = $pdo->prepare("INSERT INTO stock_transfer_items (transfer_id, batch_id, quantity) VALUES (?, ?, ?)");
                    $stmt->execute([$transfer_id, $batch_id, $qty]);

                    // Deduct from source batch
                    $stmt = $pdo->prepare("UPDATE stock_batches SET quantity = quantity - ? WHERE id = ?");
                    $stmt->execute([$qty, $batch_id]);

                    // --------- MERGE RULE AT DESTINATION ----------
                    // Destination MUST match all: product_id, batch_no, cost_price, selling_price, expiry_date
                    // Use NULL-safe equality for expiry_date
                    $stmt = $pdo->prepare("
                        SELECT *
                        FROM stock_batches
                        WHERE branch_id = ?
                          AND product_id = ?
                          AND batch_no   = ?
                          AND cost_price = ?
                          AND selling_price = ?
                          AND (expiry_date <=> ?)
                        LIMIT 1
                    ");
                    $stmt->execute([
                        $to_branch_id,
                        (int)$batch['product_id'],
                        (string)$batch['batch_no'],
                        (float)$batch['cost_price'],
                        (float)$batch['selling_price'],
                        $batch['expiry_date'] // may be NULL
                    ]);
                    $dest = $stmt->fetch(PDO::FETCH_ASSOC);

                    if ($dest) {
                        // Merge into existing destination batch
                        $stmt2 = $pdo->prepare("UPDATE stock_batches SET quantity = quantity + ? WHERE id = ?");
                        $stmt2->execute([$qty, (int)$dest['id']]);
                    } else {
                        // Create a NEW batch at destination with same attributes
                        $stmt2 = $pdo->prepare("
                            INSERT INTO stock_batches
                                (product_id, branch_id, batch_no, quantity, cost_price, selling_price, expiry_date, created_at)
                            VALUES (?,?,?,?,?,?,?, NOW())
                        ");
                        $stmt2->execute([
                            (int)$batch['product_id'],
                            $to_branch_id,
                            (string)$batch['batch_no'],
                            $qty,
                            (float)$batch['cost_price'],
                            (float)$batch['selling_price'],
                            $batch['expiry_date']
                        ]);
                    }
                }

                // Commit transaction
                $pdo->commit();
                $message = 'Stock transferred successfully!';

                // Store count before clearing for logging
                $transferredCount = count($_SESSION['transfer_cart']);

                // Clear cart + context
                $_SESSION['transfer_cart'] = [];
                unset($_SESSION['transfer_from_branch'], $_SESSION['transfer_to_branch']);

                // Log activity summarising transfer (best-effort)
                try {
                    $desc = 'Transferred ' . $transferredCount . ' batches from branch ' . $from_branch_id . ' to branch ' . $to_branch_id . ' (multi-item transfer)';
                    if (function_exists('logActivity')) {
                        logActivity($pdo, $user['id'], 'Transfer', $desc, $from_branch_id);
                    }
                } catch (Throwable $logErr) {
                    // ignore logging errors
                }
            } catch (Exception $e) {
                $pdo->rollBack();
                $message = 'Error during transfer: ' . $e->getMessage();
            }
        }
    }

    // Refresh batch list after finalisation
    $selected_from_branch = $from_branch_id ?? $selected_from_branch;
    if ($selected_from_branch) {
        $batchStmt = $pdo->prepare("
            SELECT sb.*, p.name AS product_name
            FROM stock_batches sb
            JOIN products p ON sb.product_id = p.id
            WHERE sb.branch_id = ? AND sb.quantity > 0
            ORDER BY p.name, sb.batch_no
        ");
        $batchStmt->execute([$selected_from_branch]);
        $batchList = $batchStmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transfer Stock - POS System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
    /* Styles for the live product search */
    .search-container { position: relative; }
    .search-results {
        position: absolute;
        top: 100%;
        left: 0;
        right: 0;
        background: #fff;
        border: 1px solid #ddd;
        border-top: none;
        border-radius: 0 0 5px 5px;
        max-height: 300px;
        overflow-y: auto;
        z-index: 1000;
        box-shadow: 0 4px 10px rgba(0,0,0,.1);
        display: none;
    }
    .search-item {
        padding: 10px 15px;
        border-bottom: 1px solid #eee;
        cursor: pointer;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .search-item:hover { background: #f5f5f5; }
    </style>
</head>
<body>
<?php include 'includes/nav.php'; ?>
<div class="container">
    <h1 class="mb-4">Transfer Stock</h1>
    <?php if ($message): ?>
        <div class="alert alert-info"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>
    <div class="card mb-4">
        <div class="card-header">Transfer Form</div>
        <div class="card-body">
            <form method="post">
                <!-- Row for branch selection and product search -->
                <div class="row g-3">
                    <!-- From Branch -->
                    <?php if (($user['role_name'] ?? '') === 'admin'): ?>
                        <div class="col-md-3">
                            <label class="form-label">From Branch</label>
                            <?php if (!empty($_SESSION['transfer_cart'])): ?>
                                <?php $fbid = (int)$_SESSION['transfer_from_branch']; ?>
                                <input type="hidden" name="from_branch_id" value="<?php echo $fbid; ?>">
                                <input type="text" class="form-control" value="<?php echo htmlspecialchars($branchNames[$fbid] ?? ('Branch #' . $fbid)); ?>" disabled>
                            <?php else: ?>
                                <select name="from_branch_id" class="form-select" onchange="this.form.submit()" required>
                                    <option value="">Select</option>
                                    <?php foreach ($allBranches as $br): ?>
                                        <option value="<?php echo (int)$br['id']; ?>" <?php echo ($selected_from_branch == (int)$br['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($br['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <input type="hidden" name="from_branch_id" value="<?php echo (int)$user['branch_id']; ?>">
                        <div class="col-md-3">
                            <label class="form-label">From Branch</label>
                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($user['branch_name'] ?? 'My Branch'); ?>" disabled>
                        </div>
                    <?php endif; ?>

                    <!-- Product search -->
                    <div class="col-md-4 search-container">
                        <label class="form-label">Search Product</label>
                        <input type="text" id="productSearch" class="form-control" placeholder="Search by name, ID, or barcode">
                        <div id="searchResults" class="search-results"></div>
                        <input type="hidden" name="product_id" id="product_id">
                    </div>

                    <!-- To Branch -->
                    <div class="col-md-3">
                        <label class="form-label">To Branch</label>
                        <?php if (!empty($_SESSION['transfer_cart'])): ?>
                            <?php $tbid = (int)$_SESSION['transfer_to_branch']; ?>
                            <input type="hidden" name="to_branch_id" value="<?php echo $tbid; ?>">
                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($branchNames[$tbid] ?? ('Branch #' . $tbid)); ?>" disabled>
                        <?php else: ?>
                            <select name="to_branch_id" class="form-select" required>
                                <option value="">Select</option>
                                <?php foreach ($allBranches as $br): ?>
                                    <?php if ($selected_from_branch && (int)$br['id'] === (int)$selected_from_branch) continue; ?>
                                    <option value="<?php echo (int)$br['id']; ?>"><?php echo htmlspecialchars($br['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Second row: batch and quantity -->
                <div class="row g-3 mt-3">
                    <div class="col-md-4">
                        <label class="form-label">Select Batch</label>
                        <select name="batch_id" id="batchSelect" class="form-select">
                            <option value="">Select</option>
                            <?php foreach ($batchList as $batch): ?>
                                <option value="<?php echo (int)$batch['id']; ?>">
                                    <?php
                                      $tag = $batch['product_name'].' - '.$batch['batch_no'].' (Qty: '.$batch['quantity'].')';
                                      echo htmlspecialchars($tag);
                                    ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Quantity</label>
                        <input type="number" name="quantity" class="form-control" min="1">
                    </div>
                </div>

                <!-- Buttons row -->
                <div class="mt-3">
                    <button type="submit" name="add_item" class="btn btn-primary">Add Item</button>
                    <?php if (!empty($_SESSION['transfer_cart'])): ?>
                        <button type="submit" name="finalize_transfer" class="btn btn-success ms-2">Finalize Transfer</button>
                        <button type="submit" name="reset_cart" class="btn btn-danger ms-2" onclick="return confirm('Clear transfer cart?');">Reset</button>
                    <?php endif; ?>
                </div>
            </form>

            <!-- Cart summary table -->
            <?php if (!empty($_SESSION['transfer_cart'])): ?>
                <div class="mt-4">
                    <h5>Items in Transfer</h5>
                    <div class="table-responsive">
                        <table class="table table-bordered table-sm">
                            <thead class="table-light">
                                <tr>
                                    <th>#</th>
                                    <th>Product</th>
                                    <th>Batch No</th>
                                    <th>Qty</th>
                                    <th>Cost Price</th>
                                    <th>Total Cost</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $idx = 0; foreach ($_SESSION['transfer_cart'] as $item): ?>
                                    <tr>
                                        <td><?php echo ++$idx; ?></td>
                                        <td><?php echo htmlspecialchars($item['product_name']); ?></td>
                                        <td><?php echo htmlspecialchars($item['batch_no']); ?></td>
                                        <td><?php echo (int)$item['quantity']; ?></td>
                                        <td><?php echo number_format((float)$item['cost_price'], 2); ?></td>
                                        <td><?php echo number_format(((int)$item['quantity'] * (float)$item['cost_price']), 2); ?></td>
                                        <td>
                                            <form method="post" onsubmit="return confirm('Remove this item?');">
                                                <input type="hidden" name="remove_index" value="<?php echo ($idx - 1); ?>">
                                                <button type="submit" class="btn btn-sm btn-danger">Remove</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

<script>
// Pass the list of products with available stock to the front end for live search
const transferProducts = <?php echo json_encode($availableProducts, JSON_UNESCAPED_UNICODE); ?>;

document.addEventListener('DOMContentLoaded', () => {
    const searchInput  = document.getElementById('productSearch');
    const resultsDiv   = document.getElementById('searchResults');
    const hiddenId     = document.getElementById('product_id');
    const batchSelect  = document.getElementById('batchSelect');
    const fromBranchEl = document.querySelector('select[name="from_branch_id"]') || document.querySelector('input[name="from_branch_id"]');

    function hideResults() { resultsDiv.style.display = 'none'; }

    // Live search
    searchInput.addEventListener('input', function () {
        const query = this.value.trim().toLowerCase();
        if (query.length < 1) {
            hideResults();
            hiddenId.value = '';
            return;
        }
        // Filter by name, id, or barcode (removed non-existent "code" field)
        const matches = transferProducts.filter(p => {
            const nameMatch = (p.name || '').toLowerCase().includes(query);
            const idMatch   = String(p.id).includes(query);
            const barMatch  = (p.barcode || '').toLowerCase().includes(query);
            return nameMatch || idMatch || barMatch;
        });

        if (!matches.length) {
            resultsDiv.innerHTML = '<div class="search-item">No products found</div>';
            resultsDiv.style.display = 'block';
            return;
        }
        resultsDiv.innerHTML = matches.map(p => {
            return `<div class="search-item" data-product-id="${p.id}">
                        <div><strong>${p.name}</strong>
                          <small class="text-muted">(ID: ${p.id})</small><br>
                          <small class="text-muted">Barcode: ${p.barcode || ''}</small>
                        </div>
                    </div>`;
        }).join('');
        resultsDiv.style.display = 'block';

        resultsDiv.querySelectorAll('.search-item').forEach(item => {
            item.addEventListener('click', () => {
                const pid = parseInt(item.getAttribute('data-product-id'));
                const prod = transferProducts.find(x => x.id === pid);
                if (prod) {
                    searchInput.value = prod.name;
                    hiddenId.value = prod.id;
                    hideResults();
                    loadBatches(prod.id);
                }
            });
        });
    });

    // Hide results if clicking outside
    document.addEventListener('click', function (e) {
        if (!e.target.closest('.search-container')) hideResults();
    });

    // Load batches for selected product + branch
    function loadBatches(productId) {
        let branchId = null;
        if (fromBranchEl) branchId = fromBranchEl.value;
        if (!branchId) return;
        fetch(`ajax_get_product_batches.php?product_id=${productId}&branch_id=${branchId}`)
            .then(resp => resp.json())
            .then(data => {
                batchSelect.innerHTML = '<option value="">Select</option>';
                if (data && data.status === 'success' && Array.isArray(data.batches) && data.batches.length) {
                    data.batches.forEach(b => {
                        const text = `${b.product_name ?? ''} - ${b.batch_no || ''} (Qty: ${b.quantity})`;
                        const opt = document.createElement('option');
                        opt.value = b.id;
                        opt.textContent = text;
                        batchSelect.appendChild(opt);
                    });
                }
            })
            .catch(err => console.error('Failed to load batches', err));
    }
});
</script>
