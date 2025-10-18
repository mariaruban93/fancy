<?php
/**
 * purchase_return.php
 *
 * Provides a user interface to process purchase returns.  Users can
 * search for a purchase, select items and quantities to return, and
 * the system will update inventory and record the return.  Return
 * totals reduce accounts payable and will be reflected in the financial
 * statement.
 */

require_once 'includes/header.php';

// Restrict to admin, manager or inventory officer
checkRole(['admin','manager','inventory_officer']);

// Step 1: Search purchases
$purchase_id = isset($_GET['purchase_id']) && ctype_digit($_GET['purchase_id']) ? (int)$_GET['purchase_id'] : 0;
$is_admin    = ($user['role_name'] === 'admin');
$branch_id   = $is_admin ? 0 : (int)$user['branch_id'];

// Messages
$message = '';

// Handle return submission
if (isset($_POST['process_return']) && $purchase_id) {
    $remarks = trim($_POST['remarks'] ?? '');
    $quantities = $_POST['qty'] ?? [];
    // Filter quantities: keep only numeric > 0
    $returnItems = [];
    foreach ($quantities as $itemId => $qty) {
        $qty = (int)$qty;
        if ($qty > 0) {
            $returnItems[(int)$itemId] = $qty;
        }
    }
    if (!$returnItems) {
        $message = '<div class="alert alert-danger">No return quantities entered.</div>';
    } else {
        // Fetch purchase and its items to validate quantities
        $stmt = $pdo->prepare("SELECT p.*, s.name AS supplier_name FROM purchases p JOIN suppliers s ON p.supplier_id = s.id WHERE p.id = ?" . ($is_admin || !$branch_id ? '' : ' AND p.branch_id = ?'));
        $params = [$purchase_id];
        if (!$is_admin && $branch_id) { $params[] = $branch_id; }
        $stmt->execute($params);
        $purchase = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$purchase) {
            $message = '<div class="alert alert-danger">Invalid purchase selected.</div>';
        } else {
            // Get purchase items with corresponding stock batch based on product, batch_no and branch
            $sqlItems = "SELECT pi.id, pi.product_id, sb.id AS batch_id, pi.quantity, pi.cost_price, sb.quantity AS batch_qty
                         FROM purchase_items pi
                         JOIN purchases p ON pi.purchase_id = p.id
                         JOIN stock_batches sb ON sb.product_id = pi.product_id AND sb.batch_no = pi.batch_no AND sb.branch_id = p.branch_id
                         WHERE pi.purchase_id = ?";
            $stItems = $pdo->prepare($sqlItems);
            $stItems->execute([$purchase_id]);
            $items = $stItems->fetchAll(PDO::FETCH_ASSOC);
            // Build map for validation
            $itemMap = [];
            foreach ($items as $it) {
                $itemMap[$it['id']] = $it;
            }
            $totalRefund = 0.0;
            $invalid = false;
            foreach ($returnItems as $itemId => $qty) {
                if (!isset($itemMap[$itemId])) { $invalid = true; break; }
                $orig = $itemMap[$itemId];
                if ($qty > $orig['quantity'] || $qty > $orig['batch_qty']) {
                    $invalid = true; break;
                }
                $totalRefund += $qty * $orig['cost_price'];
            }
            if ($invalid) {
                $message = '<div class="alert alert-danger">Invalid return quantities.</div>';
            } else {
                // Process return: insert record and update batches
                $pdo->beginTransaction();
                try {
                    // Insert into purchase_returns
                    $insRet = $pdo->prepare("INSERT INTO purchase_returns (purchase_id, branch_id, return_date, processed_by, total_refund, remarks) VALUES (?,?,?,?,?,?)");
                    $insRet->execute([
                        $purchase_id,
                        $purchase['branch_id'],
                        date('Y-m-d H:i:s'),
                        $user['id'],
                        $totalRefund,
                        $remarks
                    ]);
                    $returnId = (int)$pdo->lastInsertId();
                    // Insert each item and adjust stock_batches
                    $insItem = $pdo->prepare("INSERT INTO purchase_return_items (purchase_return_id, product_id, batch_id, quantity, unit_price, line_total) VALUES (?,?,?,?,?,?)");
                    $updBatch = $pdo->prepare("UPDATE stock_batches SET quantity = quantity - ? WHERE id = ?");
                    foreach ($returnItems as $itemId => $qty) {
                        $orig = $itemMap[$itemId];
                        $lineTotal = $qty * $orig['cost_price'];
                        $insItem->execute([$returnId, $orig['product_id'], $orig['batch_id'], $qty, $orig['cost_price'], $lineTotal]);
                        $updBatch->execute([$qty, $orig['batch_id']]);
                    }
                    // Log activity
                    $log = $pdo->prepare("INSERT INTO activity_logs (user_id, action, description, branch_id) VALUES (?,?,?,?)");
                    $desc = 'Processed purchase return for purchase #' . $purchase_id . ' amount ' . number_format($totalRefund,2);
                    $log->execute([$user['id'], 'Purchase Return', $desc, $purchase['branch_id']]);
                    $pdo->commit();
                    $message = '<div class="alert alert-success">Purchase return processed successfully.</div>';
                    // Clear selected purchase
                    $purchase_id = 0;
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    $message = '<div class="alert alert-danger">Failed to process return: ' . htmlspecialchars($e->getMessage()) . '</div>';
                }
            }
        }
    }
}

// Fetch list of purchases for search
$purchases = [];
if (!$purchase_id) {
    // Select purchases with computed total amount (sum of quantity * cost_price from purchase_items)
    $sql = "SELECT p.id, s.name AS supplier,
                   COALESCE(SUM(pi.quantity * pi.cost_price), 0) AS total_amount,
                   p.purchase_date, b.name AS branch_name
            FROM purchases p
            JOIN suppliers s ON p.supplier_id = s.id
            JOIN branches b ON p.branch_id = b.id
            LEFT JOIN purchase_items pi ON pi.purchase_id = p.id";
    $params = [];
    if (!$is_admin) {
        $sql .= " WHERE p.branch_id = ?";
        $params[] = $branch_id;
    }
    $sql .= " GROUP BY p.id, s.name, p.purchase_date, b.name ORDER BY p.id DESC LIMIT 100";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $purchases = $st->fetchAll(PDO::FETCH_ASSOC);
}

// If a purchase is selected, fetch its items
$returnPurchase = null;
$purchaseItems  = [];
if ($purchase_id) {
    $st = $pdo->prepare("SELECT p.*, s.name AS supplier_name, b.name AS branch_name FROM purchases p JOIN suppliers s ON p.supplier_id = s.id JOIN branches b ON p.branch_id = b.id WHERE p.id = ?" . ($is_admin || !$branch_id ? '' : ' AND p.branch_id = ?'));
    $params = [$purchase_id];
    if (!$is_admin && $branch_id) { $params[] = $branch_id; }
    $st->execute($params);
    $returnPurchase = $st->fetch(PDO::FETCH_ASSOC);
    if ($returnPurchase) {
        // Fetch purchase items along with corresponding stock batch based on product, batch_no and branch
        $stItems = $pdo->prepare("SELECT pi.id, pi.product_id, sb.id AS batch_id, pi.quantity, pi.cost_price,
                                        pr.name AS product_name, sb.batch_no, sb.quantity AS batch_qty
                                 FROM purchase_items pi
                                 JOIN products pr ON pi.product_id = pr.id
                                 JOIN purchases pu ON pi.purchase_id = pu.id
                                 JOIN stock_batches sb ON sb.product_id = pi.product_id AND sb.batch_no = pi.batch_no AND sb.branch_id = pu.branch_id
                                 WHERE pi.purchase_id = ?");
        $stItems->execute([$purchase_id]);
        $purchaseItems = $stItems->fetchAll(PDO::FETCH_ASSOC);
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Purchase Return - POS System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<?php include 'includes/nav.php'; ?>
<div class="container my-4">
    <h3 class="mb-3">Purchase Return</h3>
    <?php echo $message; ?>
    <?php if (!$purchase_id): ?>
        <p>Select a purchase to process a return:</p>
        <div class="table-responsive">
            <table class="table table-bordered table-striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Supplier</th>
                        <th>Total Amount</th>
                        <th>Date</th>
                        <th>Branch</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($purchases as $pur): ?>
                    <tr>
                        <td><?php echo $pur['id']; ?></td>
                        <td><?php echo htmlspecialchars($pur['supplier']); ?></td>
                        <td>Rs. <?php echo number_format($pur['total_amount'],2); ?></td>
                        <td><?php echo htmlspecialchars($pur['purchase_date']); ?></td>
                        <td><?php echo htmlspecialchars($pur['branch_name']); ?></td>
                        <td><a href="purchase_return.php?purchase_id=<?php echo $pur['id']; ?>" class="btn btn-sm btn-primary">Return</a></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php elseif ($returnPurchase && $purchaseItems): ?>
        <h5>Purchase Details</h5>
        <p><strong>ID:</strong> <?php echo $returnPurchase['id']; ?> | <strong>Supplier:</strong> <?php echo htmlspecialchars($returnPurchase['supplier_name']); ?> | <strong>Branch:</strong> <?php echo htmlspecialchars($returnPurchase['branch_name']); ?> | <strong>Date:</strong> <?php echo htmlspecialchars($returnPurchase['purchase_date']); ?></p>
        <form method="post">
            <table class="table table-bordered">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Batch</th>
                        <th>Purchased Qty</th>
                        <th>Available Qty</th>
                        <th>Unit Price</th>
                        <th>Return Qty</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($purchaseItems as $it): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($it['product_name']); ?></td>
                        <td><?php echo htmlspecialchars($it['batch_no']); ?></td>
                        <td><?php echo $it['quantity']; ?></td>
                        <td><?php echo $it['batch_qty']; ?></td>
                        <td>Rs. <?php echo number_format($it['cost_price'],2); ?></td>
                        <td>
                            <input type="number" name="qty[<?php echo $it['id']; ?>]" class="form-control" min="0" max="<?php echo $it['quantity']; ?>" value="0">
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div class="mb-3">
                <label class="form-label">Remarks (optional)</label>
                <textarea name="remarks" class="form-control" rows="2"></textarea>
            </div>
            <button type="submit" name="process_return" class="btn btn-success">Process Return</button>
            <a href="purchase_return.php" class="btn btn-secondary">Cancel</a>
        </form>
    <?php else: ?>
        <div class="alert alert-danger">Invalid purchase selected or no items found.</div>
        <a href="purchase_return.php" class="btn btn-secondary">Back</a>
    <?php endif; ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>