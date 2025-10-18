<?php
/**
 * worker_view.php
 *
 * Displays detailed information about a single worker.  Allows
 * administrators and managers to record payments to the worker
 * (e.g. salary, advances or special payments) and shows a history
 * of those payments.
 */
require_once 'includes/header.php';

// Only admin or manager can view worker details
checkRole(['admin','manager']);

// Validate worker ID
$worker_id = isset($_GET['id']) && ctype_digit($_GET['id']) ? (int)$_GET['id'] : 0;
if ($worker_id <= 0) {
    echo '<div class="container my-4"><div class="alert alert-danger">Invalid worker ID.</div></div>';
    exit;
}

// Fetch worker information along with branch name
$worker = null;
try {
    $stmt = $pdo->prepare("SELECT w.*, b.name AS branch_name FROM workers w JOIN branches b ON b.id = w.branch_id WHERE w.id = ?");
    $stmt->execute([$worker_id]);
    $worker = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $worker = null;
}
if (!$worker) {
    echo '<div class="container my-4"><div class="alert alert-danger">Worker not found.</div></div>';
    exit;
}

// Handle payment submission
$payment_msg = '';
if (isset($_POST['add_payment'])) {
    $pay_date = trim($_POST['payment_date'] ?? '');
    $amount   = trim($_POST['amount'] ?? '');
    $type     = trim($_POST['type'] ?? '');
    $method   = trim($_POST['method'] ?? '');
    $remarks  = trim($_POST['remarks'] ?? '');

    // Basic validation
    if ($pay_date && $amount !== '' && $type && $method) {
        try {
            $stmt = $pdo->prepare("INSERT INTO worker_payments (worker_id, branch_id, payment_date, amount, type, method, remarks, created_by) VALUES (?,?,?,?,?,?,?,?)");
            $stmt->execute([
                $worker_id,
                $worker['branch_id'],
                $pay_date,
                (float)$amount,
                $type,
                $method,
                $remarks,
                $user['id']
            ]);
            $payment_msg = 'Payment recorded successfully.';
        } catch (Throwable $e) {
            $payment_msg = 'Failed to record payment: ' . $e->getMessage();
        }
    } else {
        $payment_msg = 'Please provide payment date, amount, type and method.';
    }
}

// Fetch payment history for this worker
$payments = [];
try {
    $st = $pdo->prepare("SELECT wp.*, u.username AS created_by_name FROM worker_payments wp LEFT JOIN users u ON wp.created_by = u.id WHERE wp.worker_id = ? ORDER BY wp.payment_date DESC, wp.id DESC");
    $st->execute([$worker_id]);
    $payments = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $payments = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Worker Details - POS System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<?php include 'includes/nav.php'; ?>
<div class="container my-4">
    <h3 class="mb-3">Worker Details</h3>
    <?php if ($payment_msg): ?>
        <div class="alert alert-info"><?php echo htmlspecialchars($payment_msg); ?></div>
    <?php endif; ?>
    <!-- Worker basic information -->
    <div class="card mb-3">
        <div class="card-header">Basic Information</div>
        <div class="card-body">
            <p><strong>Name:</strong> <?php echo htmlspecialchars($worker['name']); ?></p>
            <p><strong>Phone:</strong> <?php echo htmlspecialchars($worker['phone']); ?></p>
            <p><strong>Branch:</strong> <?php echo htmlspecialchars($worker['branch_name']); ?></p>
        </div>
    </div>
    <!-- Payment form -->
    <div class="card mb-3">
        <div class="card-header">Record Payment</div>
        <div class="card-body">
            <form method="post">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Payment Date</label>
                        <input type="date" name="payment_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Amount (Rs)</label>
                        <input type="number" name="amount" class="form-control" step="0.01" min="0" required>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Type</label>
                        <select name="type" class="form-select" required>
                            <option value="">Select</option>
                            <option value="salary">Salary</option>
                            <option value="advance">Advance</option>
                            <option value="special">Special</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Method</label>
                        <select name="method" class="form-select" required>
                            <option value="">Select</option>
                            <option value="cash">Cash</option>
                            <option value="bank">Bank</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Remarks</label>
                        <input type="text" name="remarks" class="form-control" placeholder="Optional">
                    </div>
                </div>
                <div class="mt-3">
                    <button type="submit" name="add_payment" class="btn btn-primary">Add Payment</button>
                </div>
            </form>
        </div>
    </div>
    <!-- Payment history -->
    <div class="card mb-3">
        <div class="card-header">Payment History</div>
        <div class="card-body">
            <?php if ($payments): ?>
                <div class="table-responsive">
                    <table class="table table-bordered table-striped">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Amount (Rs)</th>
                                <th>Type</th>
                                <th>Method</th>
                                <th>Remarks</th>
                                <th>Recorded By</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($payments as $p): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($p['payment_date']); ?></td>
                                <td><?php echo number_format((float)$p['amount'], 2); ?></td>
                                <td><?php echo htmlspecialchars(ucfirst($p['type'])); ?></td>
                                <td><?php echo htmlspecialchars(ucfirst($p['method'])); ?></td>
                                <td><?php echo htmlspecialchars($p['remarks']); ?></td>
                                <td><?php echo htmlspecialchars($p['created_by_name'] ?: ''); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="text-muted mb-0">No payments recorded yet.</p>
            <?php endif; ?>
        </div>
    </div>
    <a href="workers.php" class="btn btn-secondary">Back to Workers</a>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>