<?php
/**
 * bank_accounts.php
 * Simple management page for bank accounts.
 * Allows admins/managers to create new bank accounts and view existing ones.
 */
require_once 'includes/header.php';
// Only admin and manager can access
checkRole(['admin','manager']);

// Create table if not exists
$pdo->exec("CREATE TABLE IF NOT EXISTS bank_accounts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  account_no VARCHAR(100) NOT NULL,
  branch_id INT DEFAULT NULL,
  opening_balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  created_by INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB");

// Handle form submission to add new account
$message = '';
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['name'])) {
    $name = trim($_POST['name']);
    $accNo = trim($_POST['account_no']);
    $branchId = isset($_POST['branch_id']) ? (int)$_POST['branch_id'] : null;
    $opening = (float)($_POST['opening_balance'] ?? 0);
    if ($name && $accNo) {
        $stmt = $pdo->prepare("INSERT INTO bank_accounts (name, account_no, branch_id, opening_balance, created_by) VALUES (?,?,?,?,?)");
        $stmt->execute([$name, $accNo, $branchId ?: null, $opening, $user['id']]);
        $message = "Bank account added successfully.";
    } else {
        $message = "Please provide required fields.";
    }
}

// Fetch existing accounts
$accounts = $pdo->query("SELECT ba.*, b.name AS branch_name FROM bank_accounts ba LEFT JOIN branches b ON ba.branch_id = b.id ORDER BY ba.id DESC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch branches for dropdown
$branches = $pdo->query("SELECT id,name FROM branches ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Bank Accounts</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<?php include 'includes/nav.php'; ?>
<div class="container my-4">
  <h3>Bank Accounts</h3>
  <?php if ($message): ?>
    <div class="alert alert-info"><?php echo htmlspecialchars($message); ?></div>
  <?php endif; ?>
  <div class="card mb-3">
    <div class="card-header">Add New Account</div>
    <div class="card-body">
      <form method="post" class="row g-3">
        <div class="col-md-4">
          <label class="form-label">Account Name</label>
          <input type="text" name="name" class="form-control" required>
        </div>
        <div class="col-md-4">
          <label class="form-label">Account Number</label>
          <input type="text" name="account_no" class="form-control" required>
        </div>
        <div class="col-md-4">
          <label class="form-label">Branch (optional)</label>
          <select name="branch_id" class="form-select">
            <option value="">-- All --</option>
            <?php foreach ($branches as $br): ?>
              <option value="<?php echo (int)$br['id']; ?>"><?php echo htmlspecialchars($br['name']); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label">Opening Balance</label>
          <input type="number" step="0.01" name="opening_balance" class="form-control" value="0">
        </div>
        <div class="col-12">
          <button type="submit" class="btn btn-primary">Create Account</button>
        </div>
      </form>
    </div>
  </div>
  <div class="card">
    <div class="card-header">Existing Accounts</div>
    <div class="card-body">
      <table class="table table-striped">
        <thead>
          <tr>
            <th>ID</th><th>Name</th><th>Account No</th><th>Branch</th><th>Opening Balance</th><th>Created At</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($accounts as $acc): ?>
            <tr>
              <td><?php echo (int)$acc['id']; ?></td>
              <td><?php echo htmlspecialchars($acc['name']); ?></td>
              <td><?php echo htmlspecialchars($acc['account_no']); ?></td>
              <td><?php echo htmlspecialchars($acc['branch_name'] ?? 'All'); ?></td>
              <td><?php echo number_format((float)$acc['opening_balance'],2); ?></td>
              <td><?php echo htmlspecialchars($acc['created_at']); ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>