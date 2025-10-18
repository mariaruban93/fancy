<?php
/**
 * barcode_print.php
 *
 * Allows users (admin/manager/inventory officer) to search for products,
 * select a specific batch and quantity, and generate a simple barcode label
 * preview for printing.  The barcode is represented as the numeric barcode
 * value; for actual barcode graphics a library would be required.
 */

require_once 'includes/header.php';

// Roles allowed to access barcode printing (inventory officers included)
checkRole(['admin','manager','inventory_officer']);

// Helper to fetch a single product
function getProduct($pdo, $id) {
    $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// Helper to fetch batches for a product (with positive quantity)
function getBatches($pdo, $product_id, $branch_id = null) {
    $sql = "SELECT sb.*, b.name as branch_name FROM stock_batches sb JOIN branches b ON sb.branch_id = b.id WHERE sb.product_id = ? AND sb.quantity > 0";
    $params = [$product_id];
    // If non-admin, filter by user branch
    global $user;
    if ($user['role_name'] !== 'admin') {
        $sql .= " AND sb.branch_id = ?";
        $params[] = $user['branch_id'];
    } elseif ($branch_id) {
        $sql .= " AND sb.branch_id = ?";
        $params[] = $branch_id;
    }
    $sql .= " ORDER BY sb.batch_no";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

// Determine action state
$search_term  = trim($_GET['search'] ?? '');
$product_id   = isset($_GET['product_id']) && ctype_digit($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
// When generating labels the POST body has generate_labels flag
$is_generate  = isset($_POST['generate_labels']);

// Fetch selected product if product_id provided
$selected_product = null;
if ($product_id) {
    $selected_product = getProduct($pdo, $product_id);
}

// Fetch batches if product selected and not generating labels yet
$batches = [];
if ($selected_product && !$is_generate) {
    $batches = getBatches($pdo, $product_id);
}

// Handle label generation
$labels = [];
$selected_batch = null;
$label_count = 0;
if ($is_generate && $product_id) {
    $batch_id = isset($_POST['batch_id']) ? (int)$_POST['batch_id'] : 0;
    $count    = isset($_POST['count']) ? (int)$_POST['count'] : 0;
    if ($count > 0 && $batch_id) {
        // Fetch product and batch again
        $selected_product = getProduct($pdo, $product_id);
        // Get selected batch details for price and branch; ensure belongs to user branch if not admin
        $batch_sql = "SELECT sb.*, b.name AS branch_name FROM stock_batches sb JOIN branches b ON sb.branch_id = b.id WHERE sb.id = ? AND sb.product_id = ?";
        $batch_params = [$batch_id, $product_id];
        // Enforce branch restrictions for non-admin
        if ($user['role_name'] !== 'admin') {
            $batch_sql .= " AND sb.branch_id = ?";
            $batch_params[] = $user['branch_id'];
        }
        $st = $pdo->prepare($batch_sql);
        $st->execute($batch_params);
        $selected_batch = $st->fetch(PDO::FETCH_ASSOC);
        if ($selected_product && $selected_batch) {
            // Build an array of labels equal to count
            for ($i = 0; $i < $count; $i++) {
                $labels[] = [
                    'name'    => $selected_product['name'],
                    'barcode' => $selected_product['barcode'],
                    'price'   => $selected_batch['selling_price'] ?? $selected_product['selling_price'],
                    'batch_no'=> $selected_batch['batch_no'],
                    'expiry'  => $selected_batch['expiry_date']
                ];
            }
            $label_count = $count;
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Barcode Printing - POS System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .label-preview { border: 1px dashed #ccc; padding: 8px; margin: 4px; width: 48%; display: inline-block; vertical-align: top; font-size: 12px; }
        .label-preview strong { display:block; font-size: 14px; margin-bottom: 2px; }
        @media print {
            body { margin: 0; }
            .no-print { display: none; }
            .label-preview { page-break-inside: avoid; }
        }
    </style>
</head>
<body>
<?php include 'includes/nav.php'; ?>
<div class="container my-4">
    <h3 class="mb-3">Print Barcode Labels</h3>

    <?php if (!$product_id): ?>
        <!-- Live search input -->
        <div class="mb-3">
            <label class="form-label">Search Products</label>
            <input type="text" id="productSearch" class="form-control" placeholder="Start typing name or barcode...">
            <div id="searchResults" class="list-group position-absolute" style="z-index:1000; max-height:200px; overflow-y:auto; width:50%;"></div>
        </div>
    <?php elseif ($product_id && !$is_generate): ?>
        <!-- Step 2: Select batch and quantity -->
        <div class="mb-3">
            <h5>Selected Product: <?php echo htmlspecialchars($selected_product['name']); ?></h5>
            <p>Barcode: <?php echo htmlspecialchars($selected_product['barcode']); ?></p>
        </div>
        <?php if ($batches): ?>
            <form method="post">
                <input type="hidden" name="product_id" value="<?php echo $product_id; ?>">
                <div class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label">Batch</label>
                        <select name="batch_id" class="form-select" required>
                            <?php foreach ($batches as $batch): ?>
                                <option value="<?php echo $batch['id']; ?>">
                                    <?php echo htmlspecialchars($batch['batch_no'] . ' - ' . $batch['branch_name'] . ' (Qty: ' . $batch['quantity'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Label Count</label>
                        <input type="number" name="count" class="form-control" value="2" min="1" max="100" required>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" name="generate_labels" class="btn btn-primary">Generate Labels</button>
                    </div>
                </div>
            </form>
        <?php else: ?>
            <p class="text-warning">No available batches for this product.</p>
        <?php endif; ?>
    <?php elseif ($is_generate && $selected_product && $selected_batch): ?>
        <!-- Step 3: Show label preview -->
        <div class="mb-3 no-print">
            <a href="barcode_print.php" class="btn btn-secondary">New Search</a>
            <button onclick="window.print();" class="btn btn-success">Print</button>
        </div>
        <div class="d-flex flex-wrap">
            <?php foreach ($labels as $idx => $lb): ?>
                <div class="label-preview">
                    <strong><?php echo htmlspecialchars($lb['name']); ?></strong>
                    <svg id="barcode<?php echo $idx; ?>" data-code="<?php echo htmlspecialchars($lb['barcode']); ?>"></svg>
                    <span>Price: Rs. <?php echo number_format((float)$lb['price'], 2); ?></span><br>
                    <span>Batch: <?php echo htmlspecialchars($lb['batch_no']); ?></span><br>
                    <?php if ($lb['expiry']): ?>
                    <span>Expiry: <?php echo htmlspecialchars($lb['expiry']); ?></span><br>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<!-- jQuery for live product search -->
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<!-- JsBarcode library for generating barcodes -->
<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>
<script>
// Initialize barcodes after DOM loaded when preview page is shown
document.addEventListener('DOMContentLoaded', function() {
    var svgs = document.querySelectorAll('svg[id^="barcode"]');
    svgs.forEach(function(svg) {
        var code = svg.getAttribute('data-code');
        if (code) {
            try {
                JsBarcode(svg, code, {format: "CODE128", width: 1.5, height: 40, displayValue: false});
            } catch (e) {
                // ignore errors
            }
        }
    });
});

// Live search for products
$(document).ready(function() {
    var $results = $('#searchResults');
    $('#productSearch').on('keyup', function() {
        var query = $(this).val().trim();
        if (query.length < 1) {
            $results.hide().empty();
            return;
        }
        $.getJSON('ajax_search_products.php', { q: query }, function(data) {
            var html = '';
            if (data && data.length) {
                $.each(data, function(i, item) {
                    html += '<a href="barcode_print.php?product_id=' + item.id + '" class="list-group-item list-group-item-action">' + item.name + ' (' + item.barcode + ')</a>';
                });
            } else {
                html = '<div class="list-group-item disabled">No products found</div>';
            }
            $results.html(html).show();
        });
    });
    // Hide results when clicking outside
    $(document).on('click', function(e) {
        if (!$(e.target).closest('#productSearch').length) {
            $results.hide();
        }
    });
});
</script>
</body>
</html>