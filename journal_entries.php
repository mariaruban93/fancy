<?php
/**
 * journal_entries.php
 *
 * This page allows authorized users (admin and manager) to record opening journal entries
 * for various accounts such as stock, cash, suppliers, customers, etc.  Entries are
 * stored in the journal_entries table and can be filtered by branch and date.
 */

require_once 'includes/header.php';

// Only admin and manager can access this page
checkRole(['admin', 'manager']);

// Ensure journal_entries table exists (idempotent)
$pdo->prepare("CREATE TABLE IF NOT EXISTS journal_entries (
  id INT AUTO_INCREMENT PRIMARY KEY,
  branch_id INT NOT NULL,
  entry_date DATE NOT NULL,
  account_type ENUM('stock','cash','supplier','customer','other','bank','wallet') NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  description VARCHAR(255) DEFAULT NULL,
  created_by INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci")->execute();

// Determine branch context for manager; admin can filter via GET
$is_admin       = ($user['role_name'] === 'admin');
$filter_branch_id = 0;
if ($is_admin) {
    if (isset($_GET['branch_id']) && ctype_digit($_GET['branch_id'])) {
        $filter_branch_id = (int)$_GET['branch_id'];
    }
} else {
    $filter_branch_id = (int)($user['branch_id'] ?? 0);
}

// Handle new journal entry submission
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['entry_date'])) {
    $entry_date   = $_POST['entry_date'];
    $account_type = $_POST['account_type'] ?? '';
    $amount       = (float)($_POST['amount'] ?? 0);
    $desc         = trim($_POST['description'] ?? '');
    // Branch selection: admin chooses; manager uses own branch
    $post_branch_id = $is_admin ? (int)($_POST['branch_id'] ?? 0) : (int)($user['branch_id'] ?? 0);
    // Validate input
    if (!$post_branch_id) {
        $message = 'Please select a valid branch.';
    } elseif (!$entry_date || !$account_type || !in_array($account_type, ['stock','cash','supplier','customer','other'], true)) {
        $message = 'Please fill all fields correctly.';
    } elseif ($amount <= 0) {
        $message = 'Amount must be greater than zero.';
    } else {
        // When recording an opening cash balance, do **not** insert into journal_entries.
        // In earlier versions we inserted the cash opening as a journal entry and
        // simultaneously updated the cash_openings table.  This resulted in the
        // opening cash being counted twice in Cash In Hand and financial reports
        // (once as an opening and once as a journal entry).  To prevent this
        // double entry, we now treat account_type "cash" entries as pure
        // opening balances: they are stored only in the cash_openings table.
        if ($account_type === 'cash') {
            try {
                // Ensure cash_openings table exists (similar to cash_in_hand.php)
                $pdo->prepare("CREATE TABLE IF NOT EXISTS cash_openings (
                  id INT AUTO_INCREMENT PRIMARY KEY,
                  branch_id INT NOT NULL,
                  opening_date DATE NOT NULL,
                  opening_amount DECIMAL(12,2) NOT NULL,
                  created_by INT NOT NULL,
                  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  UNIQUE KEY uniq_opening (branch_id, opening_date)
                ) ENGINE=InnoDB")->execute();
                // Upsert the opening balance for the given date and branch.  We do
                // not insert a matching row into journal_entries because
                // cash_openings already captures the opening cash amount.
                $stmtOpen = $pdo->prepare("INSERT INTO cash_openings (branch_id, opening_date, opening_amount, created_by)
                    VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE opening_amount = VALUES(opening_amount)");
                $stmtOpen->execute([$post_branch_id, $entry_date, $amount, $user['id']]);
                $message = 'Opening cash balance saved successfully!';
            } catch (Throwable $e) {
                $message = 'Error saving opening cash balance: ' . $e->getMessage();
            }
        } else {
            // Insert journal entry for non-cash accounts (stock, supplier, customer, other)
            try {
                $stmt = $pdo->prepare("INSERT INTO journal_entries (branch_id, entry_date, account_type, amount, description, created_by) VALUES (?,?,?,?,?,?)");
                $stmt->execute([$post_branch_id, $entry_date, $account_type, $amount, $desc, $user['id']]);
                $message = 'Journal entry saved successfully!';
            } catch (Throwable $e) {
                $message = 'Error saving journal entry: ' . $e->getMessage();
            }
        }
    }
}

// Fetch branches for dropdown
$branches = $pdo->query("SELECT id, name FROM branches ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
// Map branch names
$branch_map = [];
foreach ($branches as $b) { $branch_map[(int)$b['id']] = $b['name']; }

// Fetch existing journal entries for display (filtered by branch if selected)
$entries = [];
try {
    $sql = "SELECT je.*, b.name AS branch_name, u.username FROM journal_entries je JOIN branches b ON je.branch_id = b.id JOIN users u ON je.created_by = u.id";
    $params = [];
    if ($filter_branch_id) {
        $sql .= " WHERE je.branch_id = ?";
        $params[] = $filter_branch_id;
    }
    $sql .= " ORDER BY je.entry_date DESC, je.id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $entries = [];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Journal Entries - POS System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<?php include 'includes/nav.php'; ?>
<div class="container">
    <h1 class="mb-4">Journal Entries</h1>
    <?php if ($message): ?>
        <div class="alert alert-info"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <!-- Filter form for admin -->
    <?php if ($is_admin): ?>
    <form method="get" class="row g-3 mb-4 align-items-end">
        <div class="col-md-4">
            <label class="form-label">Filter by Branch</label>
            <select name="branch_id" class="form-select" onchange="this.form.submit()">
                <option value="">All Branches</option>
                <?php foreach ($branches as $br): ?>
                    <option value="<?php echo (int)$br['id']; ?>" <?php echo ($filter_branch_id && $filter_branch_id == $br['id'] ? 'selected' : ''); ?>><?php echo htmlspecialchars($br['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>
    <?php endif; ?>

    <!-- Journal entry form -->
    <div class="card mb-4">
        <div class="card-header">Add Journal Entry</div>
        <div class="card-body">
            <form method="post">
                <div class="row g-3">
                    <?php if ($is_admin): ?>
                    <div class="col-md-3">
                        <label class="form-label">Branch</label>
                        <select name="branch_id" class="form-select" required>
                            <option value="">Select</option>
                            <?php foreach ($branches as $br): ?>
                                <option value="<?php echo (int)$br['id']; ?>"><?php echo htmlspecialchars($br['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    <div class="col-md-3">
                        <label class="form-label">Date</label>
                        <input type="date" name="entry_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Account Type</label>
                        <select name="account_type" class="form-select" required>
                            <option value="">Select</option>
                            <!-- <option value="stock">Stock</option> -->
                            <option value="cash">Cash</option>
                            <!-- <option value="supplier">Supplier</option>
                            <option value="customer">Customer</option>
                            <option value="other">Other</option> -->
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Amount</label>
                        <input type="number" name="amount" class="form-control" step="0.01" min="0.01" required>
                    </div>
                    <div class="col-md-12">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="2" placeholder="Optional"></textarea>
                    </div>
                </div>
                <div class="mt-3">
                    <button type="submit" class="btn btn-primary">Save Entry</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Entries table -->
    <div class="card">
        <div class="card-header">Journal Entries List<?php if ($filter_branch_id) { echo ' - ' . htmlspecialchars($branch_map[$filter_branch_id] ?? '#'.$filter_branch_id); } ?></div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered table-striped">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Date</th>
                            <th>Branch</th>
                            <th>Account</th>
                            <th>Amount</th>
                            <th>Description</th>
                            <th>Created By</th>
                            <th>Created At</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($entries as $e): ?>
                        <tr>
                            <td><?php echo (int)$e['id']; ?></td>
                            <td><?php echo htmlspecialchars($e['entry_date']); ?></td>
                            <td><?php echo htmlspecialchars($e['branch_name']); ?></td>
                            <td><?php echo htmlspecialchars(ucfirst($e['account_type'])); ?></td>
                            <td><?php echo number_format($e['amount'], 2); ?></td>
                            <td><?php echo htmlspecialchars($e['description']); ?></td>
                            <td><?php echo htmlspecialchars($e['username']); ?></td>
                            <td><?php echo htmlspecialchars($e['created_at']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$entries): ?>
                        <tr><td colspan="8" class="text-center">No entries found.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>