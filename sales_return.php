<?php
/**
 * sales_return.php
 *
 * This page facilitates returning items from a completed sale.  A cashier,
 * manager or admin can look up a sale by its ID, review the items sold
 * and specify how many of each item are being returned.  Upon submission
 * the system adjusts the sale totals, restores inventory and records a
 * return transaction in the new `sale_returns` and `sale_return_items`
 * tables.  Returns may be processed as cash refunds or goods exchanges.
 */
require_once 'includes/header.php';

// Only allow staff who can perform sales to access this page
checkRole(['admin','manager','cashier']);

$message = '';
$error = '';
$sale = null;
$saleItems = [];
// Support lookup by sale ID or branch‑prefixed invoice number (e.g. BR1‑0009).
$sale_id = 0;
$rawId = isset($_GET['id']) ? trim($_GET['id']) : '';
if ($rawId !== '') {
    // If purely numeric, treat as sale ID
    if (ctype_digit($rawId)) {
        $sale_id = (int)$rawId;
    } else {
        // Parse invoice code of format BR{branch_id}-{invoice_no}
        if (preg_match('/^BR(\d+)-(\d+)$/i', $rawId, $m)) {
            $branchFromCode = (int)$m[1];
            $invoiceNo = (int)$m[2];
            try {
                $stmtInv = $pdo->prepare("SELECT id, branch_id FROM sales WHERE branch_id = ? AND invoice_no = ? LIMIT 1");
                $stmtInv->execute([$branchFromCode, $invoiceNo]);
                $sid = $stmtInv->fetch(PDO::FETCH_ASSOC);
                if ($sid) {
                    // Additional branch restriction: non‑admins may only lookup their own branch
                    if ($user['role_name'] === 'admin' || ((int)$sid['branch_id'] === (int)($user['branch_id'] ?? 0))) {
                        $sale_id = (int)$sid['id'];
                    }
                }
            } catch (Throwable $e) {
                // ignore lookup failure
            }
        }
    }
}

// Fetch sale and items if an ID is provided
if ($sale_id > 0) {
    // Retrieve sale record
    $stmt = $pdo->prepare("SELECT * FROM sales WHERE id = ?");
    $stmt->execute([$sale_id]);
    $sale = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($sale) {
        // Permission check: limit returns to the user's own branch (non-admin)
        $allowed = false;
        if ($user['role_name'] === 'admin') {
            // Administrators may process returns for any branch
            $allowed = true;
        } elseif ($user['role_name'] === 'manager' && (int)$sale['branch_id'] === (int)$user['branch_id']) {
            // Managers may process returns for their own branch
            $allowed = true;
        } elseif ($user['role_name'] === 'cashier' && (int)$sale['branch_id'] === (int)$user['branch_id']) {
            // Cashiers are limited to sales belonging to their branch
            $allowed = true;
        }
        if (!$allowed) {
            $error = 'You do not have permission to process a return for this sale.';
            $sale = null;
        } else {
            // Prevent processing a return if one already exists for this sale.  The POS
            // design assumes a sale can only be returned once.  Checking the
            // sale_returns table helps avoid multiple returns which would distort
            // inventory and financials.
            $alreadyReturned = false;
            try {
                $chk = $pdo->prepare("SELECT COUNT(*) FROM sale_returns WHERE sale_id = ?");
                $chk->execute([$sale_id]);
                $alreadyReturned = ((int)$chk->fetchColumn() > 0);
            } catch (Throwable $e) {
                $alreadyReturned = false;
            }
            if ($alreadyReturned) {
                $error = 'A return has already been processed for this invoice. A sale can only be returned once.';
                // Unset sale to prevent further processing or display of items
                $sale = null;
            } else {
                // Load sale items. Include discount_type, discount_value and tax_rate so refund calculations can factor these values.
                $itemStmt = $pdo->prepare(
                    "SELECT si.id AS sale_item_id, si.product_id, si.batch_id, si.quantity, si.selling_price, 
                            si.discount_type, si.discount_value, si.tax_rate, 
                            p.name AS product_name, sb.batch_no
                     FROM sale_items si
                     JOIN products p ON p.id = si.product_id
                     JOIN stock_batches sb ON sb.id = si.batch_id
                     WHERE si.sale_id = ?"
                );
                $itemStmt->execute([$sale_id]);
                $saleItems = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
            }
        }
    } else {
        $error = 'Sale not found.';
    }
}

// Handle return submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sale_id']) && is_numeric($_POST['sale_id'])) {
    $sale_id = (int)$_POST['sale_id'];
    $return_type = isset($_POST['return_type']) && $_POST['return_type'] === 'exchange' ? 'exchange' : 'cash';
    $remarks = isset($_POST['remarks']) && trim($_POST['remarks']) !== '' ? trim($_POST['remarks']) : null;
    $return_qtys = isset($_POST['return_qty']) && is_array($_POST['return_qty']) ? $_POST['return_qty'] : [];
    // reload sale and items for validation
    $stmt = $pdo->prepare("SELECT * FROM sales WHERE id = ?");
    $stmt->execute([$sale_id]);
    $sale = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$sale) {
        $error = 'Sale not found.';
    } else {
        // Permission check again: limit returns to the user's own branch (non-admin)
        $allowed = false;
        if ($user['role_name'] === 'admin') {
            $allowed = true;
        } elseif ($user['role_name'] === 'manager' && (int)$sale['branch_id'] === (int)$user['branch_id']) {
            $allowed = true;
        } elseif ($user['role_name'] === 'cashier' && (int)$sale['branch_id'] === (int)$user['branch_id']) {
            $allowed = true;
        }
        if (!$allowed) {
            $error = 'You do not have permission to process a return for this sale.';
        } else {
            // Prevent processing multiple returns for the same sale.  If a return record
            // already exists for this sale_id, do not allow another.  This ensures
            // inventory adjustments and financial records remain consistent.
            try {
                $chk = $pdo->prepare("SELECT COUNT(*) FROM sale_returns WHERE sale_id = ?");
                $chk->execute([$sale_id]);
                if ((int)$chk->fetchColumn() > 0) {
                    $error = 'A return has already been processed for this invoice. A sale can only be returned once.';
                }
            } catch (Throwable $e) {
                // If check fails, allow processing but ideally we should not reach here
            }
        }
        // If an error was set due to permission or duplicate returns, skip processing
        if ($error !== '') {
            // Nothing else to do
        } else {
            // Load items again including discount and tax fields for return calculation
            $itemStmt = $pdo->prepare(
                "SELECT si.id AS sale_item_id, si.product_id, si.batch_id, si.quantity, si.selling_price, 
                        si.discount_type, si.discount_value, si.tax_rate, 
                        sb.quantity AS batch_qty
                 FROM sale_items si 
                 JOIN stock_batches sb ON sb.id = si.batch_id 
                 WHERE si.sale_id = ?"
            );
            $itemStmt->execute([$sale_id]);
            $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
            // Build map of sale_item_id to record
            $itemMap = [];
            foreach ($items as $it) {
                $itemMap[$it['sale_item_id']] = $it;
            }
            $total_refund = 0.0;
            $returnLines = [];
            // Validate return quantities
            foreach ($return_qtys as $sale_item_id => $qty) {
                $qty = (int)$qty;
                if ($qty <= 0) {
                    continue;
                }
                if (!isset($itemMap[$sale_item_id])) {
                    continue;
                }
                $record = $itemMap[$sale_item_id];
                if ($qty > (int)$record['quantity']) {
                    $qty = (int)$record['quantity'];
                }
                /*
                 * Compute refund based on the actual amount paid by the customer for each unit,
                 * taking into account per‑unit discounts (percentage or fixed) and tax rate.
                 * We treat discount_value as per‑unit for fixed discounts. Percentage discounts
                 * apply to the selling price. Tax is applied on the discounted unit price.
                 */
                $sellingPrice = (float)$record['selling_price'];
                $discType     = isset($record['discount_type']) ? $record['discount_type'] : null;
                $discValue    = isset($record['discount_value']) ? (float)$record['discount_value'] : 0.0;
                $taxRate      = isset($record['tax_rate']) ? (float)$record['tax_rate'] : 0.0;

                // Calculate per unit discount
                $discUnit = 0.0;
                if ($discType && $discValue > 0) {
                    if ($discType === 'percentage') {
                        $discUnit = $sellingPrice * ($discValue / 100.0);
                    } elseif ($discType === 'fixed') {
                        // Fixed discount is already per unit
                        $discUnit = $discValue;
                    }
                }
                // Net price per unit after discount; ensure non‑negative
                $netUnit = $sellingPrice - $discUnit;
                if ($netUnit < 0) {
                    $netUnit = 0.0;
                }
                // Tax per unit on discounted price
                $taxUnit = $netUnit * ($taxRate / 100.0);
                // Refund per unit = net price + tax
                $unitRefund = $netUnit + $taxUnit;
                // Line total = refund per unit * quantity being returned
                $lineTotal = $unitRefund * $qty;
                // Sum total refund for this return
                $total_refund += $lineTotal;
                // Build return line with unit refund stored in price field (unit_price in DB)
                $returnLines[] = [
                    'sale_item_id' => $sale_item_id,
                    'product_id'   => (int)$record['product_id'],
                    'batch_id'     => (int)$record['batch_id'],
                    'qty'          => $qty,
                    'price'        => $unitRefund,
                    'line_total'   => $lineTotal
                ];
            }
            if (empty($returnLines)) {
                $error = 'Please specify at least one item to return.';
            } else {
                // Process return transaction
                try {
                    $pdo->beginTransaction();
                    // Determine if sale_returns table has amount tracking columns (amount_received/change_given)
                    $hasRetAmtCols = false;
                    try {
                        $colChk = $pdo->query("SHOW COLUMNS FROM sale_returns LIKE 'amount_received'")->fetch();
                        if ($colChk) {
                            $hasRetAmtCols = true;
                        }
                    } catch (Throwable $colEx) {
                        $hasRetAmtCols = false;
                    }
                    // Calculate amounts for cash returns: customer receives the refund, so we record
                    // change_given equal to the refund and amount_received as zero.  For exchange
                    // returns there is no cash transaction at this stage.
                    $retReceived = 0.0;
                    $retChange = 0.0;
                    if ($return_type === 'cash') {
                        $retReceived = 0.0;
                        $retChange = $total_refund;
                    }
                    // Insert sale_return record with or without amount columns
                    if ($hasRetAmtCols) {
                        $stmt = $pdo->prepare("INSERT INTO sale_returns (sale_id, branch_id, return_date, processed_by, return_type, total_refund, amount_received, change_given, remarks) VALUES (?,?,?,?,?,?,?,?,?)");
                        $stmt->execute([
                            $sale_id,
                            (int)$sale['branch_id'],
                            date('Y-m-d H:i:s'),
                            (int)$user['id'],
                            $return_type,
                            $total_refund,
                            $retReceived,
                            $retChange,
                            $remarks
                        ]);
                    } else {
                        $stmt = $pdo->prepare("INSERT INTO sale_returns (sale_id, branch_id, return_date, processed_by, return_type, total_refund, remarks) VALUES (?,?,?,?,?,?,?)");
                        $stmt->execute([
                            $sale_id,
                            (int)$sale['branch_id'],
                            date('Y-m-d H:i:s'),
                            (int)$user['id'],
                            $return_type,
                            $total_refund,
                            $remarks
                        ]);
                    }
                    $return_id = $pdo->lastInsertId();
                    // Insert return items and update stock and sale_items
                    foreach ($returnLines as $line) {
                        // Insert into sale_return_items
                        $stmt = $pdo->prepare("INSERT INTO sale_return_items (sale_return_id, product_id, batch_id, quantity, unit_price, line_total) VALUES (?,?,?,?,?,?)");
                        $stmt->execute([
                            $return_id,
                            $line['product_id'],
                            $line['batch_id'],
                            $line['qty'],
                            $line['price'],
                            $line['line_total']
                        ]);
                        // We no longer reduce sale_items quantity here. The original sale remains intact.
                        // Update stock_batches: add quantity back
                        $stmt = $pdo->prepare("UPDATE stock_batches SET quantity = quantity + ? WHERE id = ?");
                        $stmt->execute([$line['qty'], $line['batch_id']]);
                    }
                    // Note: We do not adjust sale totals; refund is recorded separately in sale_returns.
                    // Log activity
                    try {
                        logActivity($pdo, $user['id'], 'Sale Return', 'Processed return for sale #' . $sale_id . ' amount ' . number_format($total_refund,2), $sale['branch_id']);
                    } catch (Throwable $logErr) {
                        // Ignore logging errors
                    }
                    $pdo->commit();
                    $message = 'Return processed successfully. Refund/adjustment amount: Rs. ' . number_format($total_refund,2);
                    // Reload sale and items for display after return
                    $stmt = $pdo->prepare("SELECT * FROM sales WHERE id = ?");
                    $stmt->execute([$sale_id]);
                    $sale = $stmt->fetch(PDO::FETCH_ASSOC);
                    $itemStmt = $pdo->prepare(
                        "SELECT si.id AS sale_item_id, si.product_id, si.batch_id, si.quantity, si.selling_price,
                                si.discount_type, si.discount_value, si.tax_rate,
                                p.name AS product_name, sb.batch_no
                         FROM sale_items si
                         JOIN products p ON p.id = si.product_id
                         JOIN stock_batches sb ON sb.id = si.batch_id
                         WHERE si.sale_id = ?"
                    );
                    $itemStmt->execute([$sale_id]);
                    $saleItems = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
                } catch (Throwable $ex) {
                    $pdo->rollBack();
                    $error = 'Error processing return: ' . $ex->getMessage();
                }
            }
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales Return - POS System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<?php include 'includes/nav.php'; ?>
<div class="container my-4">
    <h3 class="mb-3">Process Sale Return</h3>
    <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    <?php if ($message): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>
    <?php if (!$sale_id): ?>
        <!-- Lookup form if no sale ID provided.  Accept numeric sale IDs as well as
             branch‑prefixed invoice codes (e.g. BR1‑0009).  Managers and cashiers
             are restricted to their own branch. -->
        <form method="get" class="row g-3 mb-3">
            <div class="col-auto">
                <label for="id" class="form-label">Sale/Invoice</label>
            </div>
            <div class="col-auto">
                <input type="text" name="id" id="id" class="form-control" required placeholder="Enter sale ID or invoice (e.g. BR1-0009)">
            </div>
            <div class="col-auto">
                <button class="btn btn-primary">Lookup</button>
            </div>
        </form>
    <?php elseif ($sale): ?>
        <!-- Display sale details and return form -->
        <div class="card mb-3">
            <div class="card-body">
                <h5 class="card-title">Sale #<?php echo $sale['id']; ?></h5>
                <p class="card-text">
                    <strong>Date:</strong> <?php echo htmlspecialchars($sale['sale_date']); ?>
                    <br><strong>Branch:</strong> <?php
                        $bname = $pdo->prepare("SELECT name FROM branches WHERE id = ?");
                        $bname->execute([(int)$sale['branch_id']]);
                        echo htmlspecialchars($bname->fetchColumn());
                    ?>
                    <br><strong>Total Amount:</strong> Rs. <?php echo number_format($sale['total_amount'], 2); ?>
                </p>
            </div>
        </div>
        <form method="post">
            <input type="hidden" name="sale_id" value="<?php echo $sale['id']; ?>">
            <table class="table table-bordered align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Product</th>
                        <th>Batch</th>
                        <th class="text-end">Sold Qty</th>
                        <th class="text-end">Price</th>
                        <th class="text-end">Return Qty</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($saleItems as $it): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($it['product_name']); ?></td>
                            <td><?php echo htmlspecialchars($it['batch_no']); ?></td>
                            <td class="text-end"><?php echo number_format((float)$it['quantity']); ?></td>
                            <td class="text-end"><?php echo number_format((float)$it['selling_price'], 2); ?></td>
                            <td class="text-end" style="width:120px;">
                                <input type="number" name="return_qty[<?php echo $it['sale_item_id']; ?>]" min="0" max="<?php echo (int)$it['quantity']; ?>" class="form-control form-control-sm" value="0">
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div class="mb-3">
                <label class="form-label">Return Type</label>
                <select name="return_type" class="form-select">
                    <option value="cash">Cash Refund</option>
                    <option value="exchange">Goods Exchange</option>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label">Remarks (optional)</label>
                <input type="text" name="remarks" class="form-control" placeholder="Reason for return">
            </div>
            <button class="btn btn-success">Process Return</button>
        </form>
    <?php else: ?>
        <!-- Sale not found or permission denied -->
        <p>No sale found or accessible.</p>
    <?php endif; ?>

    <?php
    // After the return processing form or lookup form, show a list of previous returns for admin and manager roles
    if (in_array($user['role_name'], ['admin','manager'])) {
        // Build branch filter for managers: admins see all returns
        $isAdmin = ($user['role_name'] === 'admin');
        $branchCondition = '';
        $paramsList = [];
        if (!$isAdmin) {
            $branchCondition = 'WHERE sr.branch_id = ?';
            $paramsList[] = $user['branch_id'];
        }
        // Determine if the settled_with_sale_id column exists.  If not, select NULL instead.
        $hasSettledCol = false;
        try {
            $pdo->query("SELECT settled_with_sale_id FROM sale_returns LIMIT 1");
            $hasSettledCol = true;
        } catch (Throwable $e) {
            $hasSettledCol = false;
        }
        if ($hasSettledCol) {
            $sqlList = "SELECT sr.id, sr.sale_id, sr.branch_id, b.name AS branch_name, sr.return_date, sr.return_type, sr.total_refund, sr.settled_with_sale_id, u.username AS processed_by\n                FROM sale_returns sr\n                JOIN branches b ON sr.branch_id = b.id\n                JOIN users u ON sr.processed_by = u.id\n                $branchCondition\n                ORDER BY sr.return_date DESC";
        } else {
            $sqlList = "SELECT sr.id, sr.sale_id, sr.branch_id, b.name AS branch_name, sr.return_date, sr.return_type, sr.total_refund, NULL AS settled_with_sale_id, u.username AS processed_by\n                FROM sale_returns sr\n                JOIN branches b ON sr.branch_id = b.id\n                JOIN users u ON sr.processed_by = u.id\n                $branchCondition\n                ORDER BY sr.return_date DESC";
        }
        $stmtList = $pdo->prepare($sqlList);
        $stmtList->execute($paramsList);
        $returnRows = $stmtList->fetchAll(PDO::FETCH_ASSOC);
    ?>
    <hr class="my-4">
    <h4 class="mb-3">Sale Returns List</h4>
    <table id="returnListTable" class="table table-bordered table-striped">
        <thead class="table-light">
        <tr>
            <th>ID</th>
            <th>Sale ID</th>
            <th>Branch</th>
            <th>Return Date</th>
            <th>Processed By</th>
            <th>Type</th>
            <th>Total Refund</th>
            <th>Status</th>
            <th>Actions</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($returnRows as $r): ?>
            <tr>
                <td><?= htmlspecialchars($r['id']) ?></td>
                <td><?= htmlspecialchars($r['sale_id']) ?></td>
                <td><?= htmlspecialchars($r['branch_name']) ?></td>
                <td><?= htmlspecialchars($r['return_date']) ?></td>
                <td><?= htmlspecialchars($r['processed_by']) ?></td>
                <td><?= htmlspecialchars($r['return_type']) ?></td>
                <td><?= number_format($r['total_refund'], 2) ?></td>
                <td>
                    <?php
                    // Mark returns that have been merged into a new sale (settled_with_sale_id not null)
                    if (!empty($r['settled_with_sale_id'])) {
                        echo '<span class="badge bg-success">New billed</span>';
                    } else {
                        echo '<span class="badge bg-secondary">Pending</span>';
                    }
                    ?>
                </td>
                <td>
                    <a href="sales_return_view.php?id=<?= $r['id'] ?>" class="btn btn-sm btn-info">View</a>
                    <?php if ($isAdmin || ($user['role_name'] === 'manager' && (int)$user['branch_id'] === (int)$r['branch_id'])): ?>
                        <button class="btn btn-sm btn-danger" onclick="deleteReturn(<?= $r['id'] ?>)">Delete</button>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php } ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<?php if (in_array($user['role_name'], ['admin','manager'])): ?>
<!-- Include jQuery and DataTables only when return list is shown -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
<script>
$(document).ready(function() {
    // Initialize DataTable for return list if present
    var tbl = document.getElementById('returnListTable');
    if (tbl) {
        $('#returnListTable').DataTable();
    }
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
<?php endif; ?>
</body>
</html>