<?php
/**
 * wallets.php
 * Simple management page for wallets.  A wallet represents a cash wallet that can be used to fund the cash drawer.
 * Each wallet has an opening balance and an optional bank tariff (for bank charges when funds are moved).
 * When a wallet is created with an opening balance, the opening amount is also stored in cash_openings for the given branch and today's date.
 */
require_once 'includes/header.php';
checkRole(['admin','manager']);

// Ensure wallets table exists
$pdo->exec("CREATE TABLE IF NOT EXISTS wallets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  branch_id INT NOT NULL,
  opening_balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  bank_tariff DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  created_by INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB");

// Ensure cash_openings table exists (for opening balance entries)
$pdo->exec("CREATE TABLE IF NOT EXISTS cash_openings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  branch_id INT NOT NULL,
  opening_date DATE NOT NULL,
  opening_amount DECIMAL(12,2) NOT NULL,
  created_by INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_opening (branch_id, opening_date)
) ENGINE=InnoDB");

$message = '';
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['name'])) {
    $name = trim($_POST['name']);
    $branchId = (int)($_POST['branch_id'] ?? 0);
    $opening = (float)($_POST['opening_balance'] ?? 0);
    $tariff  = (float)($_POST['bank_tariff'] ?? 0);
    if ($name && $branchId) {
        $stmt = $pdo->prepare("INSERT INTO wallets (name, branch_id, opening_balance, bank_tariff, created_by) VALUES (?,?,?,?,?)");
        $stmt->execute([$name, $branchId, $opening, $tariff, $user['id']]);
        // Insert or update cash_openings for today for this branch
        if ($opening > 0) {
            $today = date('Y-m-d');
            $stmt2 = $pdo->prepare("INSERT INTO cash_openings (branch_id, opening_date, opening_amount, created_by) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE opening_amount = opening_amount + VALUES(opening_amount)");
            $stmt2->execute([$branchId, $today, $opening, $user['id']]);
        }
        $message = "Wallet created successfully.";
    } else {
        $message = "Please provide all required fields.";
    }
}

// Fetch wallets
$wallets = $pdo->query("SELECT w.*, b.name AS branch_name FROM wallets w JOIN branches b ON w.branch_id = b.id ORDER BY w.id DESC")->fetchAll(PDO::FETCH_ASSOC);
// Fetch branches
$branches = $pdo->query("SELECT id,name FROM branches ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Wallets</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<?php include 'includes/nav.php'; ?>
<div class="container my-4">
  <h3>Wallets</h3>
  <?php if ($message): ?>
    <div class="alert alert-info"><?php echo htmlspecialchars($message); ?></div>
  <?php endif; ?>
  <div class="card mb-3">
    <div class="card-header">Create New Wallet</div>
    <div class="card-body">
      <form method="post" class="row g-3">
        <div class="col-md-4">
          <label class="form-label">Wallet Name</label>
          <input type="text" name="name" class="form-control" required>
        </div>
        <div class="col-md-4">
          <label class="form-label">Branch</label>
          <select name="branch_id" class="form-select" required>
            <option value="">Select</option>
            <?php foreach ($branches as $br): ?>
              <option value="<?php echo (int)$br['id']; ?>"><?php echo htmlspecialchars($br['name']); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label">Opening Balance</label>
          <input type="number" step="0.01" name="opening_balance" class="form-control" value="0">
        </div>
        <div class="col-md-4">
          <label class="form-label">Bank Tariff (if any)</label>
          <input type="number" step="0.01" name="bank_tariff" class="form-control" value="0">
        </div>
        <div class="col-12">
          <button type="submit" class="btn btn-primary">Create Wallet</button>
        </div>
      </form>
    </div>
  </div>
  <div class="card">
    <div class="card-header">Existing Wallets</div>
    <div class="card-body">
      <table class="table table-striped">
        <thead>
          <tr>
            <th>ID</th><th>Name</th><th>Branch</th><th>Opening Balance</th><th>Bank Tariff</th><th>Created At</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($wallets as $w): ?>
            <tr>
              <td><?php echo (int)$w['id']; ?></td>
              <td><?php echo htmlspecialchars($w['name']); ?></td>
              <td><?php echo htmlspecialchars($w['branch_name']); ?></td>
              <td><?php echo number_format((float)$w['opening_balance'],2); ?></td>
              <td><?php echo number_format((float)$w['bank_tariff'],2); ?></td>
              <td><?php echo htmlspecialchars($w['created_at']); ?></td>
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