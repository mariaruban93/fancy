<?php
/**
 * admin_tools.php
 * Admin-only maintenance tools:
 *  - Delete customers / suppliers / users (safe checks)
 *  - Reset database (keeps users & branches; optional keep product masters)
 *  - Create/Sync missing tables (idempotent CREATE TABLE IF NOT EXISTS)
 *
 * Assumes:
 *  - includes/header.php sets $pdo and $user and has checkRole(), csrf_check(), $_SESSION['csrf']
 *  - Bootstrap 5 present
 */

require_once 'includes/header.php';
if (function_exists('checkRole')) { checkRole(['admin']); }

// ------------------------------------------------------------------
// Flash helper
// ------------------------------------------------------------------
$flash = function($msg, $type='success'){
  if (!isset($_SESSION['flash'])) $_SESSION['flash'] = [];
  $_SESSION['flash'][] = ['t'=>$type,'m'=>$msg];
};
if (!isset($_SESSION['flash'])) $_SESSION['flash'] = [];

// ------------------------------------------------------------------
// Utility: ensure table exists with given SQL (idempotent)
// ------------------------------------------------------------------
function ensureTable(PDO $pdo, string $name, string $createSql): array {
  try {
    // Does it already exist?
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $st->execute([$name]);
    $exists = (int)$st->fetchColumn() > 0;
    if ($exists) return ['name'=>$name,'created'=>false,'message'=>'exists'];

    $pdo->exec($createSql);
    return ['name'=>$name,'created'=>true,'message'=>'created'];
  } catch (Throwable $e) {
    return ['name'=>$name,'created'=>false,'message'=>'error: '.$e->getMessage()];
  }
}

// ------------------------------------------------------------------
// Fetch lists for tables (simple preview)
// ------------------------------------------------------------------
$customers = $pdo->query("SELECT id, name, phone FROM customers ORDER BY id DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
$suppliers = $pdo->query("SELECT id, name, phone FROM suppliers ORDER BY id DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);

$users = $pdo->query("
  SELECT id, username, full_name, role_id, branch_id
  FROM users
  ORDER BY id ASC
")->fetchAll(PDO::FETCH_ASSOC);

$roleMap = [];
foreach ($pdo->query("SELECT id, name FROM roles") as $r) {
  $roleMap[(int)$r['id']] = $r['name'];
}

// ------------------------------------------------------------------
// CREATE/SYNC: schemas derived from current DB
// (Safe to run multiple times; will only create when missing)
// ------------------------------------------------------------------
$createSchemas = [

  // ----- Banking & Cash -----
  'bank_accounts' => <<<SQL
CREATE TABLE IF NOT EXISTS `bank_accounts` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `account_no` VARCHAR(100) NOT NULL,
  `branch_id` INT(11) DEFAULT NULL,
  `opening_balance` DECIMAL(12,2) NOT NULL DEFAULT '0.00',
  `created_by` INT(11) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_bank_branch` (`branch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL,

  'cash_openings' => <<<SQL
CREATE TABLE IF NOT EXISTS `cash_openings` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `branch_id` INT(11) NOT NULL,
  `opening_date` DATE NOT NULL,
  `opening_amount` DECIMAL(12,2) NOT NULL,
  `created_by` INT(11) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cash_open_branch_date` (`branch_id`,`opening_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL,

  // ----- Counters -----
  'branch_counters' => <<<SQL
CREATE TABLE IF NOT EXISTS `branch_counters` (
  `branch_id` INT(11) NOT NULL,
  `next_invoice` INT(11) NOT NULL DEFAULT '1',
  PRIMARY KEY (`branch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL,

  // ----- Sales & Payments -----
  'card_commissions' => <<<SQL
CREATE TABLE IF NOT EXISTS `card_commissions` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `sale_id` INT(11) NOT NULL,
  `branch_id` INT(11) NOT NULL,
  `gross_amount` DECIMAL(12,2) NOT NULL,
  `commission_rate` DECIMAL(5,2) NOT NULL DEFAULT '2.50',
  `commission_amount` DECIMAL(12,2) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cc_sale` (`sale_id`),
  KEY `idx_cc_branch` (`branch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL,

  'sale_payments' => <<<SQL
CREATE TABLE IF NOT EXISTS `sale_payments` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `sale_id` INT(11) NOT NULL,
  `method` VARCHAR(50) NOT NULL,
  `amount` DECIMAL(10,2) NOT NULL,
  `cheque_number` VARCHAR(100) DEFAULT NULL,
  `bank_name` VARCHAR(100) DEFAULT NULL,
  `bank_branch` VARCHAR(100) DEFAULT NULL,
  `deposit_date` DATE DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sp_sale` (`sale_id`),
  KEY `idx_sp_method` (`method`),
  KEY `idx_sp_deposit_date` (`deposit_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL,

  // ----- Purchases & Returns -----
  'purchase_returns' => <<<SQL
CREATE TABLE IF NOT EXISTS `purchase_returns` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `purchase_id` INT(11) NOT NULL,
  `branch_id` INT(11) NOT NULL,
  `return_date` DATETIME NOT NULL,
  `processed_by` INT(11) NOT NULL,
  `total_refund` DECIMAL(12,2) NOT NULL DEFAULT '0.00',
  `remarks` VARCHAR(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_pr_purchase` (`purchase_id`),
  KEY `idx_pr_branch` (`branch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL,

  'purchase_return_items' => <<<SQL
CREATE TABLE IF NOT EXISTS `purchase_return_items` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `purchase_return_id` INT(11) NOT NULL,
  `product_id` INT(11) NOT NULL,
  `batch_id` INT(11) NOT NULL,
  `quantity` INT(11) NOT NULL,
  `unit_price` DECIMAL(10,2) NOT NULL,
  `line_total` DECIMAL(12,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_pri_return` (`purchase_return_id`),
  KEY `idx_pri_product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL,

  // ----- Cargo (providers / services / payments) -----
  'cargo_providers' => <<<SQL
CREATE TABLE IF NOT EXISTS `cargo_providers` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(150) NOT NULL,
  `phone` VARCHAR(50) DEFAULT NULL,
  `address` VARCHAR(255) DEFAULT NULL,
  `is_active` TINYINT(1) DEFAULT '1',
  `created_by` INT(11) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL,

  'cargo_services' => <<<SQL
CREATE TABLE IF NOT EXISTS `cargo_services` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `branch_id` INT(11) NOT NULL,
  `supplier_id` INT(11) NOT NULL,
  `purchase_id` INT(11) DEFAULT NULL,
  `cargo_provider_id` INT(11) NOT NULL,
  `transfer_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `reference_no` VARCHAR(50) DEFAULT NULL,
  `description` VARCHAR(255) DEFAULT NULL,
  `amount` DECIMAL(12,2) NOT NULL,
  `created_by` INT(11) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cs_branch_date` (`branch_id`,`transfer_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL,

  'cargo_payments' => <<<SQL
CREATE TABLE IF NOT EXISTS `cargo_payments` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `cargo_provider_id` INT(11) NOT NULL,
  `branch_id` INT(11) NOT NULL,
  `method` ENUM('cash','bank','card','cheque') NOT NULL,
  `bank_account_id` INT(11) DEFAULT NULL,
  `cheque_no` VARCHAR(100) DEFAULT NULL,
  `amount` DECIMAL(12,2) NOT NULL,
  `paid_by` INT(11) NOT NULL,
  `paid_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `notes` VARCHAR(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_cp_branch_paidat` (`branch_id`,`paid_at`),
  KEY `idx_cp_provider` (`cargo_provider_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL,

  // ----- Journals / Expenses / Logs -----
  'journal_entries' => <<<SQL
CREATE TABLE IF NOT EXISTS `journal_entries` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `branch_id` INT(11) NOT NULL,
  `entry_date` DATE NOT NULL,
  `account_type` ENUM('stock','cash','supplier','customer','other','bank','wallet','cargo') NOT NULL,
  `amount` DECIMAL(12,2) NOT NULL,
  `description` VARCHAR(255) DEFAULT NULL,
  `created_by` INT(11) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_je_branch_date` (`branch_id`,`entry_date`),
  KEY `idx_je_type` (`account_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL,

  'expenses' => <<<SQL
CREATE TABLE IF NOT EXISTS `expenses` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `branch_id` INT(11) NOT NULL,
  `expense_date` DATE NOT NULL,
  `description` VARCHAR(255) NOT NULL,
  `category` VARCHAR(50) NOT NULL,
  `subcategory` VARCHAR(50) NOT NULL,
  `payment_method` VARCHAR(20) NOT NULL,
  `payee_name` VARCHAR(100) NOT NULL,
  `amount` DECIMAL(12,2) NOT NULL,
  `created_by` INT(11) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_exp_branch_date` (`branch_id`,`expense_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL,

  'activity_logs' => <<<SQL
CREATE TABLE IF NOT EXISTS `activity_logs` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NOT NULL,
  `action` VARCHAR(100) NOT NULL,
  `description` TEXT,
  `branch_id` INT(11) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_log_user_time` (`user_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL,

  // ----- Workers -----
  'workers' => <<<SQL
CREATE TABLE IF NOT EXISTS `workers` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(120) NOT NULL,
  `phone` VARCHAR(40) DEFAULT NULL,
  `branch_id` INT(11) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_workers_branch` (`branch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL,

  'worker_payments' => <<<SQL
CREATE TABLE IF NOT EXISTS `worker_payments` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `worker_id` INT(11) NOT NULL,
  `payment_date` DATE NOT NULL,
  `type` ENUM('salary','advance','special') NOT NULL,
  `method` ENUM('cash','bank') NOT NULL DEFAULT 'cash',
  `amount` DECIMAL(12,2) NOT NULL,
  `created_by` INT(11) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_wp_worker_date` (`worker_id`,`payment_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL,

  // ----- Write-offs (stock shrinkage) -----
  'write_offs' => <<<SQL
CREATE TABLE IF NOT EXISTS `write_offs` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `branch_id` INT(11) NOT NULL,
  `product_id` INT(11) NOT NULL,
  `batch_id` INT(11) NOT NULL,
  `quantity` INT(11) NOT NULL,
  `cost_price` DECIMAL(10,2) NOT NULL,
  `reason` VARCHAR(255) DEFAULT NULL,
  `write_off_date` DATETIME NOT NULL,
  `created_by` INT(11) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_wo_branch_date` (`branch_id`,`write_off_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL,

  // ----- Inter-branch transfers -----
  'stock_transfers' => <<<SQL
CREATE TABLE IF NOT EXISTS `stock_transfers` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `from_branch_id` INT(11) NOT NULL,
  `to_branch_id` INT(11) NOT NULL,
  `transferred_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `notes` VARCHAR(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_st_from_to_time` (`from_branch_id`,`to_branch_id`,`transferred_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL,

  'stock_transfer_items' => <<<SQL
CREATE TABLE IF NOT EXISTS `stock_transfer_items` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `transfer_id` INT(11) NOT NULL,
  `batch_id` INT(11) NOT NULL,
  `product_id` INT(11) NOT NULL,
  `quantity` INT(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_sti_transfer` (`transfer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL,

  'transfer_payments' => <<<SQL
CREATE TABLE IF NOT EXISTS `transfer_payments` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `transfer_id` INT(11) NOT NULL,
  `amount` DECIMAL(12,2) NOT NULL,
  `paid_by` INT(11) NOT NULL,
  `paid_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tp_transfer` (`transfer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL,

  // ----- Wallet (optional generic wallet account) -----
  'wallets' => <<<SQL
CREATE TABLE IF NOT EXISTS `wallets` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `branch_id` INT(11) NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `balance` DECIMAL(12,2) NOT NULL DEFAULT '0.00',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_wallet_branch` (`branch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL,
];

// ------------------------------------------------------------------
// Delete / Reset / Create handlers
// ------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $action = $_POST['action'] ?? '';
  csrf_check();

  try {
    if ($action === 'delete_customer') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id<=0) throw new Exception("Invalid customer ID");
      $st = $pdo->prepare("DELETE FROM customers WHERE id=?");
      $st->execute([$id]);
      $flash("Customer #{$id} deleted.");

    } elseif ($action === 'delete_supplier') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id<=0) throw new Exception("Invalid supplier ID");
      $st = $pdo->prepare("DELETE FROM suppliers WHERE id=?");
      $st->execute([$id]);
      $flash("Supplier #{$id} deleted.");

    } elseif ($action === 'delete_user') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id<=0) throw new Exception("Invalid user ID");
      if ($id == (int)$user['id']) throw new Exception("You can't delete yourself.");
      $st = $pdo->prepare("SELECT username FROM users WHERE id=?");
      $st->execute([$id]);
      $uname = $st->fetchColumn();
      if ($uname === false) throw new Exception("User not found.");
      if (strtolower($uname) === 'admin') throw new Exception("You can't delete the primary admin.");

      $refChecks = [
        ['sql'=>"SELECT COUNT(*) FROM purchases WHERE purchased_by=?",      'label'=>'purchases'],
        ['sql'=>"SELECT COUNT(*) FROM sales WHERE sold_by=?",              'label'=>'sales'],
        ['sql'=>"SELECT COUNT(*) FROM expenses WHERE created_by=?",        'label'=>'expenses'],
        ['sql'=>"SELECT COUNT(*) FROM activity_logs WHERE user_id=?",      'label'=>'activity logs'],
        ['sql'=>"SELECT COUNT(*) FROM journal_entries WHERE created_by=?", 'label'=>'journal entries'],
        ['sql'=>"SELECT COUNT(*) FROM write_offs WHERE created_by=?", 'label'=>'write-offs'],
      ];
      $totalRefs = 0; $labels = [];
      foreach ($refChecks as $chk) {
        $s = $pdo->prepare($chk['sql']); $s->execute([$id]);
        $c = (int)$s->fetchColumn();
        if ($c>0) { $totalRefs += $c; $labels[]=$chk['label']; }
      }
      if ($totalRefs>0) {
        throw new Exception("Cannot delete user; referenced in: ".implode(', ', $labels).". Consider creating a new user and disabling this one instead.");
      }
      $st = $pdo->prepare("DELETE FROM users WHERE id=?");
      $st->execute([$id]);
      $flash("User #{$id} deleted.");

    } elseif ($action === 'reset_database') {
      // Double-confirm guard
      $confirm = trim($_POST['confirm_text'] ?? '');
      if ($confirm !== 'RESET') throw new Exception("Type RESET to confirm.");

      $keepProducts = isset($_POST['keep_products']);

      // Order below aims to respect FKs even with FK checks off.
      $pdo->beginTransaction();
      $pdo->exec("SET FOREIGN_KEY_CHECKS=0");

      // Full list (aligned with your DB)
    // Full list (aligned with your DB)
// NOTE: children before parents; FOREIGN_KEY_CHECKS is OFF anyway, but order still helps.
$allTables = [
  // --- Sales & returns ---
  'sale_return_items','sale_returns',
  'sale_items','sale_payments','sales',

  // --- Purchases & returns ---
  'purchase_return_items','purchase_returns',
  'purchase_items','purchase_payments','purchases',

  // --- Stock (batches, adjustments, transfers) ---
  'stock_adjustment_items','stock_adjustments',
  'stock_transfer_items','transfer_payments','stock_transfers',
  'stock_batches',

  // --- Cash drawer / daily cash snapshots ---
  'cash_draws','cash_drawer_daily',
  'cash_openings',

  // --- Banking / card fees / wallet ---
  'card_commissions',
  'wallets',
  'bank_accounts',

  // --- Expenses / journals / write-offs / logs ---
  'expenses','journal_entries','write_offs',
  'activity_logs',

  // --- Workers & payments ---
  'worker_payments','workers',

  // --- Cargo (providers / services / payments) ---
  'cargo_payments','cargo_services','cargo_providers',

  // --- Party masters (will be wiped) ---
  'customers','suppliers',

  // --- Product masters (OPTIONAL keep) ---
  'products','categories','brands','units',

  // --- Other transactional audit ---
  'reversal_audit',
];


      // Always keep users/branches/roles/permissions/role_permissions and counters
      $skip = ['users','branches','roles','permissions','role_permissions','branch_counters'];
      if ($keepProducts) {
        $skip = array_merge($skip, ['products','categories','brands','units']);
      }

      foreach ($allTables as $t) {
        if (in_array($t, $skip, true)) continue;
        try { $pdo->exec("TRUNCATE TABLE `$t`"); } catch (Throwable $e) { /* ignore missing */ }
      }

      if (!$keepProducts) {
        foreach (['products','categories','brands','units'] as $t) {
          try { $pdo->exec("TRUNCATE TABLE `$t`"); } catch (Throwable $e) { /* ignore missing */ }
        }
      }

      $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
      $pdo->commit();

      $flash("Database reset completed. Kept: users & branches".($keepProducts?", and product masters.":"."));

    } elseif ($action === 'create_missing_tables') {
      $results = [];
      foreach ($createSchemas as $t => $sql) {
        $results[] = ensureTable($pdo, $t, $sql);
      }
      // Build message
      $created = array_filter($results, fn($r)=>$r['created']);
      $errors  = array_filter($results, fn($r)=>str_starts_with($r['message'],'error:'));
      if ($created) {
        $flash('Created: '.implode(', ', array_map(fn($r)=>$r['name'],$created)));
      } else {
        $flash('No new tables were created (all present).');
      }
      if ($errors) {
        foreach ($errors as $er) {
          $flash("Create error for {$er['name']}: {$er['message']}", 'danger');
        }
      }

    } else {
      // no-op
    }
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $flash("Error: ".$e->getMessage(), 'danger');
  }

  header("Location: admin_tools.php"); exit;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Admin Tools</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body{background:#f6f7fb}
    .card{border:1px solid #e5e7eb;border-radius:12px;box-shadow:0 4px 12px rgba(0,0,0,.05)}
    .table td,.table th{vertical-align:middle}
    .danger-zone{border-left:4px solid #dc3545}
    .nowrap{white-space:nowrap}
  </style>
</head>
<body>
<?php if (file_exists('includes/nav.php')) include 'includes/nav.php'; ?>

<div class="container my-4">
  <h3 class="mb-3">Admin Tools</h3>

  <?php foreach($_SESSION['flash'] as $f): ?>
    <div class="alert alert-<?= htmlspecialchars($f['t']) ?>"><?= htmlspecialchars($f['m']) ?></div>
  <?php endforeach; $_SESSION['flash']=[]; ?>

  <!-- CREATE / SYNC MISSING TABLES -->
  <div class="card mb-4">
    <div class="card-header bg-light">
      <strong>Create / Sync missing tables</strong>
    </div>
    <div class="card-body">
      <p class="mb-2">
        This checks for commonly used tables (card commissions, cargo, journal, returns, transfers, etc.) and creates any that are missing.
        Safe to run multiple times.
      </p>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
        <input type="hidden" name="action" value="create_missing_tables">
        <button class="btn btn-success">Create / Sync</button>
      </form>
      <small class="text-muted d-block mt-2">Uses <code>CREATE TABLE IF NOT EXISTS</code> with sensible PKs and indexes.</small>
    </div>
  </div>

  <!-- RESET DB -->
  <div class="card mb-4 danger-zone">
    <div class="card-header bg-light">
      <strong>Reset Database (Keep Users &amp; Branches)</strong>
    </div>
    <div class="card-body">
      <p class="mb-2">
        Removes transactions & most masters except <strong>users</strong>, <strong>branches</strong>, <strong>roles</strong>, <strong>permissions</strong>, and <strong>branch_counters</strong>.
        Optionally keep Product masters (Products / Categories / Brands / Units).
      </p>
      <form method="post" onsubmit="return confirm('This is irreversible. Continue?');">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
        <input type="hidden" name="action" value="reset_database">
        <div class="form-check mb-2">
          <input class="form-check-input" type="checkbox" id="keep_products" name="keep_products">
          <label class="form-check-label" for="keep_products">Keep Products, Categories, Brands, Units</label>
        </div>
        <div class="row g-2 align-items-center mb-2">
          <div class="col-auto"><label for="confirm_text" class="col-form-label">Type <code>RESET</code> to confirm:</label></div>
          <div class="col-3"><input type="text" class="form-control" name="confirm_text" id="confirm_text" required></div>
        </div>
        <button class="btn btn-danger">Run Reset</button>
      </form>
    </div>
  </div>

  <div class="row">
    <!-- CUSTOMERS -->
    <div class="col-lg-4">
      <div class="card mb-4">
        <div class="card-header"><strong>Customers</strong></div>
        <div class="card-body">
          <div class="table-responsive" style="max-height:420px">
            <table class="table table-sm table-hover">
              <thead class="table-light">
                <tr><th>ID</th><th>Name</th><th class="nowrap">Phone</th><th></th></tr>
              </thead>
              <tbody>
                <?php if (!$customers): ?>
                  <tr><td colspan="4" class="text-center text-muted py-3">No customers</td></tr>
                <?php else: foreach ($customers as $c): ?>
                  <tr>
                    <td><?= (int)$c['id'] ?></td>
                    <td><?= htmlspecialchars($c['name']) ?></td>
                    <td class="nowrap"><?= htmlspecialchars($c['phone'] ?? '') ?></td>
                    <td class="text-end">
                      <form method="post" onsubmit="return confirm('Delete customer #<?= (int)$c['id'] ?>?');" class="d-inline">
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
                        <input type="hidden" name="action" value="delete_customer">
                        <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                        <button class="btn btn-sm btn-outline-danger">Delete</button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <!-- SUPPLIERS -->
    <div class="col-lg-4">
      <div class="card mb-4">
        <div class="card-header"><strong>Suppliers</strong></div>
        <div class="card-body">
          <div class="table-responsive" style="max-height:420px">
            <table class="table table-sm table-hover">
              <thead class="table-light">
                <tr><th>ID</th><th>Name</th><th class="nowrap">Phone</th><th></th></tr>
              </thead>
              <tbody>
                <?php if (!$suppliers): ?>
                  <tr><td colspan="4" class="text-center text-muted py-3">No suppliers</td></tr>
                <?php else: foreach ($suppliers as $s): ?>
                  <tr>
                    <td><?= (int)$s['id'] ?></td>
                    <td><?= htmlspecialchars($s['name']) ?></td>
                    <td class="nowrap"><?= htmlspecialchars($s['phone'] ?? '') ?></td>
                    <td class="text-end">
                      <form method="post" onsubmit="return confirm('Delete supplier #<?= (int)$s['id'] ?>?');" class="d-inline">
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
                        <input type="hidden" name="action" value="delete_supplier">
                        <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                        <button class="btn btn-sm btn-outline-danger">Delete</button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <!-- USERS -->
    <div class="col-lg-4">
      <div class="card mb-4">
        <div class="card-header"><strong>Users</strong> <small class="text-muted">(cannot delete yourself or primary admin)</small></div>
        <div class="card-body">
          <div class="table-responsive" style="max-height:420px">
            <table class="table table-sm table-hover">
              <thead class="table-light">
                <tr><th>ID</th><th>Username</th><th>Name</th><th>Role</th><th>Branch</th><th></th></tr>
              </thead>
              <tbody>
                <?php if (!$users): ?>
                  <tr><td colspan="6" class="text-center text-muted py-3">No users</td></tr>
                <?php else: foreach ($users as $u):
                  $isMe = ((int)$u['id'] === (int)$user['id']);
                  $isPrimaryAdmin = (strtolower($u['username'])==='admin');
                  $disableDelete = $isMe || $isPrimaryAdmin;
                ?>
                  <tr>
                    <td><?= (int)$u['id'] ?></td>
                    <td><?= htmlspecialchars($u['username']) ?></td>
                    <td><?= htmlspecialchars($u['full_name'] ?? '') ?></td>
                    <td><?= htmlspecialchars($roleMap[(int)$u['role_id']] ?? ('#'.$u['role_id'])) ?></td>
                    <td><?= (int)($u['branch_id'] ?? 0) ?></td>
                    <td class="text-end">
                      <form method="post" class="d-inline" onsubmit="return confirm('Delete user #<?= (int)$u['id'] ?> (<?= htmlspecialchars($u['username']) ?>)?');">
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
                        <input type="hidden" name="action" value="delete_user">
                        <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                        <button class="btn btn-sm btn-outline-danger" <?= $disableDelete?'disabled':''; ?>>Delete</button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

  </div><!-- /row -->

</div><!-- /container -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
