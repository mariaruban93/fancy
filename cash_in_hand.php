<?php
/**
 * cash_in_hand.php — Daily Drawer model with Bank/Wallet selection (PHP 7.3 safe)
 * - Uses cash_drawer_daily for one-row-per-day, per-branch cash.
 * - Auto-creates today's row with opening = yesterday's actual closing (or fallback).
 * - Recalculates aggregates from transactions.
 * - End-of-day: journal transfer (cash -> bank/wallet) + optional closing & rollover.
 * - NEW: Destination Account (bank name / wallet) selector with validation.
 */

require_once 'includes/header.php';
if (function_exists('checkRole')) { checkRole(['admin','manager']); }

/* -------------------------------------------------------
   Basic role/branch inputs
------------------------------------------------------- */
$range     = isset($_GET['range']) ? $_GET['range'] : 'today';
$start_in  = isset($_GET['start_date']) ? $_GET['start_date'] : null;
$end_in    = isset($_GET['end_date']) ? $_GET['end_date'] : null;

$is_admin = (isset($user['role_name']) && $user['role_name'] === 'admin');
$user_branch_id = (int)($user['branch_id'] ?? 0);
$incoming_branch_id = isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : 0;
$branch_id = $is_admin ? $incoming_branch_id : $user_branch_id;

$today = date('Y-m-d');

/* -------------------------------------------------------
   Utility: daterange
------------------------------------------------------- */
function daterange_for($range) {
  $today = date('Y-m-d');
  if ($range === 'today') return [$today, $today];
  if ($range === 'yesterday') { $y=date('Y-m-d', strtotime($today.' -1 day')); return [$y,$y]; }
  if ($range === 'week') {
    $start = date('Y-m-d', strtotime('monday this week'));
    $end   = date('Y-m-d', strtotime($start.' +6 days'));
    return [$start,$end];
  }
  if ($range === 'month') {
    $start = date('Y-m-01');
    $end   = date('Y-m-t');
    return [$start,$end];
  }
  return null;
}

if ($pair = daterange_for($range)) { $start_date=$pair[0]; $end_date=$pair[1]; }
else {
  $start_date = $start_in ?: $today;
  $end_date   = $end_in   ?: $today;
}

/* -------------------------------------------------------
   Ensure base tables
------------------------------------------------------- */
function ensureJournal(PDO $pdo) {
  $pdo->prepare("CREATE TABLE IF NOT EXISTS journal_entries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    branch_id INT NOT NULL,
    entry_date DATE NOT NULL,
    account_type ENUM('stock','cash','supplier','customer','other','bank','wallet') NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    description VARCHAR(255) DEFAULT NULL,
    created_by INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
  ) ENGINE=InnoDB")->execute();
}

function ensureDailyDrawer(PDO $pdo) {
  $pdo->exec("CREATE TABLE IF NOT EXISTS cash_drawer_daily (
    id INT AUTO_INCREMENT PRIMARY KEY,
    branch_id INT NOT NULL,
    business_date DATE NOT NULL,
    opening_balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total_cash_sales DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total_cash_returns DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    cash_from_credit_customers DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total_cash_expenses DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    supplier_payment_cash DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    cargo_payment_cash DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    other_cash_out DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    calculated_closing_balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    actual_closing_balance DECIMAL(12,2) DEFAULT NULL,
    created_by INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL,
    UNIQUE KEY uq_cash_drawer_daily (branch_id, business_date)
  ) ENGINE=InnoDB");
}

ensureJournal($pdo);
ensureDailyDrawer($pdo);

/* -------------------------------------------------------
   Helpers for columns (compat)
------------------------------------------------------- */
function column_exists(PDO $pdo, $table, $column) {
  try {
    $stmt = $pdo->query("SHOW COLUMNS FROM `$table` LIKE ".$pdo->quote($column));
    return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
  } catch (Throwable $e) { return false; }
}

/* -------------------------------------------------------
   Daily Drawer helpers
------------------------------------------------------- */
function ensure_today_drawer(PDO $pdo, $branch_id, $user_id) {
  if ($branch_id <= 0) return;
  $today = date('Y-m-d');

  $st = $pdo->prepare("SELECT id FROM cash_drawer_daily WHERE branch_id=? AND business_date=?");
  $st->execute([$branch_id, $today]);
  if ($st->fetch(PDO::FETCH_ASSOC)) return;

  // Yesterday closing (actual preferred)
  $yest = date('Y-m-d', strtotime($today.' -1 day'));
  $st = $pdo->prepare("SELECT COALESCE(actual_closing_balance, calculated_closing_balance)
                       FROM cash_drawer_daily
                       WHERE branch_id=? AND business_date=?");
  $st->execute([$branch_id, $yest]);
  $carry = $st->fetchColumn();

  if ($carry === false) {
    // fallback to most recent cash_openings <= today
    $st = $pdo->prepare("SELECT opening_amount
                         FROM cash_openings
                         WHERE branch_id=? AND opening_date<=?
                         ORDER BY opening_date DESC LIMIT 1");
    $st->execute([$branch_id, $today]);
    $carry = $st->fetchColumn();
  }
  if ($carry === false || $carry === null) $carry = 0.00;

  $ins = $pdo->prepare("INSERT INTO cash_drawer_daily
    (branch_id, business_date, opening_balance, created_by)
    VALUES (?,?,?,?)");
  $ins->execute([$branch_id, $today, (float)$carry, $user_id]);
}

function recalc_drawer_for(PDO $pdo, $branch_id, $the_date) {
  if ($branch_id <= 0) return;
  $from_dt = $the_date.' 00:00:00';
  $to_dt   = $the_date.' 23:59:59';

  // Opening
  $st = $pdo->prepare("SELECT opening_balance FROM cash_drawer_daily WHERE branch_id=? AND business_date=?");
  $st->execute([$branch_id, $the_date]);
  $opening = (float)($st->fetchColumn() ?: 0.0);

  // Cash sales
  $st = $pdo->prepare("SELECT COALESCE(SUM(sp.amount),0)
                       FROM sale_payments sp
                       JOIN sales s ON sp.sale_id=s.id
                       WHERE sp.method='cash' AND s.branch_id=? AND s.sale_date BETWEEN ? AND ?");
  $st->execute([$branch_id, $from_dt, $to_dt]);
  $total_cash_sales = (float)$st->fetchColumn();

  // Cash returns (require sr.return_type='cash' if exists)
  $has_return_type = column_exists($pdo, 'sale_returns', 'return_type');
  $sql_ret = "SELECT COALESCE(SUM(sr.total_refund),0)
              FROM sale_returns sr
              JOIN sales s ON sr.sale_id=s.id
              WHERE s.branch_id=? AND sr.return_date BETWEEN ? AND ?";
  if ($has_return_type) $sql_ret .= " AND sr.return_type='cash'";
  $st = $pdo->prepare($sql_ret);
  $st->execute([$branch_id, $from_dt, $to_dt]);
  $total_cash_returns = (float)$st->fetchColumn();

  // Journal + (cash received, e.g., from credit customers)
  $st = $pdo->prepare("SELECT COALESCE(SUM(amount),0)
                       FROM journal_entries
                       WHERE branch_id=? AND account_type='cash' AND entry_date=? AND amount>0");
  $st->execute([$branch_id, $the_date]);
  $cash_from_credit = (float)$st->fetchColumn();

  // Journal - (other cash out, including end-of-day bank/wallet transfers)
  $st = $pdo->prepare("SELECT COALESCE(SUM(-amount),0)
                       FROM journal_entries
                       WHERE branch_id=? AND account_type='cash' AND entry_date=? AND amount<0");
  $st->execute([$branch_id, $the_date]);
  $other_cash_out = (float)$st->fetchColumn();

  // Expenses paid in cash (plus worker cash)
  $expenses_has_method = column_exists($pdo, 'expenses', 'payment_method');
  $sql_exp = "SELECT COALESCE(SUM(e.amount),0) FROM expenses e WHERE e.branch_id=? AND e.expense_date=?";
  if ($expenses_has_method) $sql_exp .= " AND e.payment_method='cash'";
  $st = $pdo->prepare($sql_exp); $st->execute([$branch_id, $the_date]);
  $cash_exp = (float)$st->fetchColumn();

  $st = $pdo->prepare("SELECT COALESCE(SUM(wp.amount),0)
                       FROM worker_payments wp
                       JOIN workers w ON wp.worker_id=w.id
                       WHERE w.branch_id=? AND wp.payment_date=? AND wp.method='cash'");
  $st->execute([$branch_id, $the_date]);
  $worker_cash = (float)$st->fetchColumn();

  $total_cash_expenses = $cash_exp + $worker_cash;

  // Supplier cash
  $pp_has_method = column_exists($pdo, 'purchase_payments', 'method');
  if ($pp_has_method) {
    $sql_pp = "SELECT COALESCE(SUM(pp.amount),0)
               FROM purchase_payments pp
               JOIN purchases p ON pp.purchase_id=p.id
               WHERE p.branch_id=? AND DATE(pp.paid_at)=? AND pp.method='cash'";
  } else {
    $sql_pp = "SELECT COALESCE(SUM(pp.amount),0)
               FROM purchase_payments pp
               JOIN purchases p ON pp.purchase_id=p.id
               WHERE p.branch_id=? AND DATE(pp.paid_at)=?";
  }
  $st = $pdo->prepare($sql_pp); $st->execute([$branch_id, $the_date]);
  $supplier_cash = (float)$st->fetchColumn();

  // Cargo cash
  $cargo_has_method = column_exists($pdo, 'cargo_payments', 'method');
  if ($cargo_has_method) {
    $sql_cg = "SELECT COALESCE(SUM(cp.amount),0)
               FROM cargo_payments cp
               WHERE cp.branch_id=? AND DATE(cp.paid_at)=? AND cp.method='cash'";
  } else {
    $sql_cg = "SELECT COALESCE(SUM(cp.amount),0)
               FROM cargo_payments cp
               WHERE cp.branch_id=? AND DATE(cp.paid_at)=?";
  }
  $st = $pdo->prepare($sql_cg); $st->execute([$branch_id, $the_date]);
  $cargo_cash = (float)$st->fetchColumn();

  $net_cash_sales = $total_cash_sales - $total_cash_returns;
  $calc_close = $opening + $net_cash_sales + $cash_from_credit
              - $total_cash_expenses - $supplier_cash - $cargo_cash - $other_cash_out;

  $up = $pdo->prepare("UPDATE cash_drawer_daily
                       SET total_cash_sales=?,
                           total_cash_returns=?,
                           cash_from_credit_customers=?,
                           total_cash_expenses=?,
                           supplier_payment_cash=?,
                           cargo_payment_cash=?,
                           other_cash_out=?,
                           calculated_closing_balance=?,
                           updated_at=NOW()
                       WHERE branch_id=? AND business_date=?");
  $up->execute([
    $total_cash_sales,
    $total_cash_returns,
    $cash_from_credit,
    $total_cash_expenses,
    $supplier_cash,
    $cargo_cash,
    $other_cash_out,
    max(0,$calc_close),
    $branch_id,
    $the_date
  ]);
}

function close_day_and_rollover(PDO $pdo, $branch_id, $user_id, $the_date, $actual_close) {
  if ($branch_id <= 0) return;
  $actual = (float)$actual_close;
  $tomorrow = date('Y-m-d', strtotime($the_date.' +1 day'));

  $pdo->beginTransaction();
  try {
    $st = $pdo->prepare("UPDATE cash_drawer_daily
                         SET actual_closing_balance=?, updated_at=NOW()
                         WHERE branch_id=? AND business_date=?");
    $st->execute([$actual, $branch_id, $the_date]);

    $st = $pdo->prepare("INSERT INTO cash_drawer_daily
        (branch_id, business_date, opening_balance, created_by)
        VALUES (?,?,?,?)
        ON DUPLICATE KEY UPDATE opening_balance=VALUES(opening_balance)");
    $st->execute([$branch_id, $tomorrow, $actual, $user_id]);

    $pdo->commit();
  } catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
  }
}

/* -------------------------------------------------------
   Branch list
------------------------------------------------------- */
$branches = $pdo->query("SELECT id,name FROM branches ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$branch_map = [];
foreach ($branches as $b) $branch_map[(int)$b['id']] = $b['name'];
$branch_label = $is_admin
  ? ($branch_id ? ($branch_map[$branch_id] ?? "Branch #$branch_id") : 'All Branches')
  : ($branch_map[$branch_id] ?? 'My Branch');

/* -------------------------------------------------------
   FETCH BANKS & WALLETS (for destination account selection)
------------------------------------------------------- */
$banks = $wallets = [];
try {
  if ($is_admin) {
    $banks   = $pdo->query("SELECT id, name, account_no, branch_id FROM bank_accounts ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    $wallets = $pdo->query("SELECT id, name, branch_id FROM wallets ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
  } else {
    $stmtB = $pdo->prepare("SELECT id, name, account_no, branch_id FROM bank_accounts WHERE branch_id=? ORDER BY name");
    $stmtB->execute([$branch_id]);
    $banks = $stmtB->fetchAll(PDO::FETCH_ASSOC);

    $stmtW = $pdo->prepare("SELECT id, name, branch_id FROM wallets WHERE branch_id=? ORDER BY name");
    $stmtW->execute([$branch_id]);
    $wallets = $stmtW->fetchAll(PDO::FETCH_ASSOC);
  }
} catch (Throwable $e) {
  $banks = $wallets = [];
}

/* -------------------------------------------------------
   POST actions: transfer / close day
------------------------------------------------------- */
$message = '';

if ($_SERVER['REQUEST_METHOD']==='POST') {
  $action = isset($_POST['action']) ? $_POST['action'] : '';
  $biz_date = isset($_POST['business_date']) ? $_POST['business_date'] : $today;
  if (!$biz_date) $biz_date = $today;

  try {
    if ($action === 'transfer') {
      $amount = (float)($_POST['transfer_amount'] ?? 0);
      $dest_type = isset($_POST['destination_type']) ? $_POST['destination_type'] : '';
      $dest_id = (int)($_POST['destination_id'] ?? 0);
      $remarks = trim(isset($_POST['transfer_remarks']) ? $_POST['transfer_remarks'] : '');

      if ($branch_id <= 0) throw new Exception("Select a branch to transfer.");
      if ($amount <= 0) throw new Exception("Enter a valid transfer amount.");
      if ($dest_type!=='bank' && $dest_type!=='wallet') throw new Exception("Select destination (bank/wallet).");

      // Validate selected account & build label
      $dest_label = '';
      if ($dest_type === 'bank') {
        $match = null;
        foreach ($banks as $b) {
          if ((int)$b['id'] === $dest_id) { $match = $b; break; }
        }
        if (!$match) throw new Exception("Select a valid Bank account.");
        $dest_label = $match['name'] . (isset($match['account_no']) && $match['account_no']!=='' ? ' ('.$match['account_no'].')' : '');
      } else { // wallet
        $match = null;
        foreach ($wallets as $w) {
          if ((int)$w['id'] === $dest_id) { $match = $w; break; }
        }
        if (!$match) throw new Exception("Select a valid Wallet.");
        $dest_label = $match['name'];
      }

      // Journal entries
      ensureJournal($pdo);
      $pdo->beginTransaction();
      $desc = $remarks ?: 'End of day transfer to '.strtoupper($dest_type).': '.$dest_label;
      $j = $pdo->prepare("INSERT INTO journal_entries (branch_id, entry_date, account_type, amount, description, created_by)
                          VALUES (?,?,?,?,?,?)");
      // cash out
      $j->execute([$branch_id, $biz_date, 'cash', -$amount, $desc, $user['id']]);
      // destination in
      $j->execute([$branch_id, $biz_date, $dest_type,  $amount, $desc, $user['id']]);
      $pdo->commit();

      // refresh aggregates for that day
      recalc_drawer_for($pdo, $branch_id, $biz_date);
      $message = "<div class='alert alert-success'>Transfer recorded (Rs. ".number_format($amount,2).") to ".htmlspecialchars($dest_label).".</div>";
    }
    elseif ($action === 'close_day') {
      $actual = (float)($_POST['actual_closing_balance'] ?? 0);
      if ($branch_id <= 0) throw new Exception("Select a branch to close.");
      close_day_and_rollover($pdo, $branch_id, $user['id'], $biz_date, $actual);
      $message = "<div class='alert alert-success'>Day closed. Tomorrow opening set to Rs. ".number_format($actual,2).".</div>";
    }
  } catch (Throwable $e) {
    $message = "<div class='alert alert-danger'>".$e->getMessage()."</div>";
  }
}

/* -------------------------------------------------------
   Ensure & recalc today
------------------------------------------------------- */
if ($branch_id > 0) {
  ensure_today_drawer($pdo, $branch_id, $user['id']);
  recalc_drawer_for($pdo, $branch_id, $today);
}

/* -------------------------------------------------------
   Fetch today's row & a short history
------------------------------------------------------- */
function get_drawer(PDO $pdo, $branch_id, $the_date) {
  $st = $pdo->prepare("SELECT * FROM cash_drawer_daily WHERE branch_id=? AND business_date=?");
  $st->execute([$branch_id, $the_date]);
  return $st->fetch(PDO::FETCH_ASSOC);
}
$today_row = ($branch_id>0) ? get_drawer($pdo, $branch_id, $today) : null;

$hist = [];
if ($branch_id>0) {
  $st = $pdo->prepare("SELECT * FROM cash_drawer_daily
                       WHERE branch_id=? AND business_date BETWEEN ? AND ?
                       ORDER BY business_date DESC");
  $st->execute([$branch_id, $start_date, $end_date]);
  $hist = $st->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Cash In Hand (Daily Drawer)</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body{background:#f6f7fb;font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,'Helvetica Neue',Arial;}
    .badge-ghost{background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;padding:6px 10px;color:#374151}
    .grid{display:grid;gap:12px;grid-template-columns:repeat(auto-fit,minmax(220px,1fr))}
    .card-stat{border:1px solid #e5e7eb;border-radius:12px;background:#fff;box-shadow:0 4px 12px rgba(0,0,0,.05)}
    .card-stat .card-body{padding:16px}
    .k{color:#6b7280;font-size:.9rem}
    .v{font-weight:700;font-size:1.2rem}
    .table tfoot th{background:#f9fafb}
  </style>
</head>
<body>
<?php if (file_exists('includes/nav.php')) include 'includes/nav.php'; ?>

<div class="container my-4">
  <div class="d-flex align-items-center gap-2 mb-2">
    <h3 class="mb-0">Cash In Hand</h3>
    <span class="badge-ghost"><?php echo htmlspecialchars($branch_label); ?></span>
  </div>

  <?php if($message) echo $message; ?>

  <!-- Filter -->
  <form class="row g-2 align-items-end mb-3" method="get">
    <div class="col-md-2">
      <label class="form-label">Range</label>
      <select class="form-select" name="range" id="rangeSelect">
        <option value="today"     <?php echo $range==='today'?'selected':''; ?>>Today</option>
        <option value="yesterday" <?php echo $range==='yesterday'?'selected':''; ?>>Yesterday</option>
        <option value="week"      <?php echo $range==='week'?'selected':''; ?>>This Week</option>
        <option value="month"     <?php echo $range==='month'?'selected':''; ?>>This Month</option>
        <option value="custom"    <?php echo ($range!=='today'&&$range!=='yesterday'&&$range!=='week'&&$range!=='month')?'selected':''; ?>>Custom</option>
      </select>
    </div>
    <div class="col-md-3" id="startDateDiv" style="display:none;">
      <label class="form-label">Start Date</label>
      <input type="date" class="form-control" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>">
    </div>
    <div class="col-md-3" id="endDateDiv" style="display:none;">
      <label class="form-label">End Date</label>
      <input type="date" class="form-control" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>">
    </div>
    <?php if ($is_admin): ?>
      <div class="col-md-3">
        <label class="form-label">Branch</label>
        <select name="branch_id" class="form-select">
          <option value="0"<?php echo ($branch_id===0?' selected':''); ?>>All (admin)</option>
          <?php foreach($branches as $b): ?>
            <option value="<?php echo (int)$b['id']; ?>" <?php echo ($branch_id===(int)$b['id'])?'selected':''; ?>>
              <?php echo htmlspecialchars($b['name']); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    <?php else: ?>
      <div class="col-md-3">
        <label class="form-label">Branch</label>
        <input type="text" class="form-control" value="<?php echo htmlspecialchars($branch_label); ?>" disabled>
        <input type="hidden" name="branch_id" value="<?php echo (int)$branch_id; ?>">
      </div>
    <?php endif; ?>
    <div class="col-md-2 d-grid">
      <button class="btn btn-primary w-100">Apply</button>
    </div>
  </form>

  <!-- Today cards -->
  <?php if ($branch_id>0 && $today_row): ?>
  <div class="grid mb-3">
    <div class="card card-stat"><div class="card-body">
      <div class="k">Opening (Today)</div>
      <div class="v">Rs. <?php echo number_format($today_row['opening_balance'],2); ?></div>
    </div></div>
    <div class="card card-stat"><div class="card-body">
      <div class="k">Net Cash Sales (Cash - Returns)</div>
      <div class="v">Rs. <?php echo number_format($today_row['total_cash_sales'] - $today_row['total_cash_returns'],2); ?></div>
    </div></div>
    <div class="card card-stat"><div class="card-body">
      <div class="k">Cash Received (Journal +)</div>
      <div class="v">Rs. <?php echo number_format($today_row['cash_from_credit_customers'],2); ?></div>
    </div></div>
    <div class="card card-stat"><div class="card-body">
      <div class="k">Cash Out (Exp + Supp + Cargo + Journal -)</div>
      <div class="v">Rs. <?php
        $out = $today_row['total_cash_expenses'] + $today_row['supplier_payment_cash']
             + $today_row['cargo_payment_cash'] + $today_row['other_cash_out'];
        echo number_format($out,2);
      ?></div>
    </div></div>
    <div class="card card-stat"><div class="card-body">
      <div class="k">Closing (Calculated)</div>
      <div class="v">Rs. <?php echo number_format($today_row['calculated_closing_balance'],2); ?></div>
    </div></div>
    <div class="card card-stat"><div class="card-body">
      <div class="k">Closing (Actual)</div>
      <div class="v">Rs. <?php echo number_format((float)($today_row['actual_closing_balance'] ?? 0),2); ?></div>
    </div></div>
  </div>
  <?php endif; ?>

  <?php if ($branch_id>0): ?>
  <!-- End of day transfer -->
  <div class="card mb-3">
    <div class="card-header">End-of-Day Transfer (Cash → Bank/Wallet)</div>
    <div class="card-body">
      <form method="post" class="row g-2">
        <input type="hidden" name="action" value="transfer">
        <div class="col-md-3">
          <label class="form-label">Business Date</label>
          <input type="date" class="form-control" name="business_date" value="<?php echo htmlspecialchars($today); ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">Amount</label>
          <input type="number" step="0.01" min="0" class="form-control" name="transfer_amount" placeholder="0.00">
        </div>
        <div class="col-md-3">
          <label class="form-label">Destination Type</label>
          <select class="form-select" name="destination_type" id="destType">
            <option value="">Select</option>
            <?php if(!empty($banks)): ?><option value="bank">Bank</option><?php endif; ?>
            <?php if(!empty($wallets)): ?><option value="wallet">Wallet</option><?php endif; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Destination Account</label>
          <select class="form-select" name="destination_id" id="destId">
            <option value="">Select</option>
            <?php foreach($banks as $bk): ?>
              <option value="<?php echo (int)$bk['id']; ?>" data-type="bank">
                <?php echo htmlspecialchars($bk['name']); ?><?php echo ($bk['account_no'] ? ' ('.htmlspecialchars($bk['account_no']).')' : ''); ?>
              </option>
            <?php endforeach; ?>
            <?php foreach($wallets as $wl): ?>
              <option value="<?php echo (int)$wl['id']; ?>" data-type="wallet">
                <?php echo htmlspecialchars($wl['name']); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-12">
          <label class="form-label">Remarks</label>
          <input type="text" class="form-control" name="transfer_remarks" placeholder="Optional (e.g., cash deposit slip no.)">
        </div>
        <div class="col-md-12 d-grid mt-2">
          <button class="btn btn-secondary">Record Transfer</button>
        </div>
      </form>
      <div class="text-muted small mt-2">
        This writes two journal entries: <strong>cash −amount</strong> and <strong>bank/wallet +amount</strong>.
        Balance Sheet picks these up automatically. The selected bank/wallet name is included in the description.
      </div>
    </div>
  </div>

  <!-- Close day -->
  <div class="card mb-4">
    <div class="card-header">Close Day & Roll to Tomorrow</div>
    <div class="card-body">
      <form method="post" class="row g-2">
        <input type="hidden" name="action" value="close_day">
        <div class="col-md-3">
          <label class="form-label">Business Date</label>
          <input type="date" class="form-control" name="business_date" value="<?php echo htmlspecialchars($today); ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">Actual Closing (counted)</label>
          <input type="number" step="0.01" min="0" class="form-control" name="actual_closing_balance"
                 value="<?php echo $today_row ? number_format((float)$today_row['calculated_closing_balance'],2,'.','') : '0.00'; ?>">
        </div>
        <div class="col-md-6 d-grid align-items-end">
          <button class="btn btn-success">Close Day & Set Tomorrow Opening</button>
        </div>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <!-- History table -->
  <?php if ($branch_id>0): ?>
  <div class="card">
    <div class="card-header">Daily History (<?php echo htmlspecialchars($start_date); ?> → <?php echo htmlspecialchars($end_date); ?>)</div>
    <div class="card-body table-responsive">
      <table class="table table-bordered table-striped">
        <thead class="table-light">
          <tr>
            <th>Date</th>
            <th class="text-end">Opening</th>
            <th class="text-end">Net Cash Sales</th>
            <th class="text-end">Journal +</th>
            <th class="text-end">Expenses</th>
            <th class="text-end">Supplier Cash</th>
            <th class="text-end">Cargo Cash</th>
            <th class="text-end">Journal −</th>
            <th class="text-end">Closing (Calc)</th>
            <th class="text-end">Closing (Actual)</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($hist as $r): ?>
            <tr>
              <td><?php echo htmlspecialchars($r['business_date']); ?></td>
              <td class="text-end"><?php echo number_format($r['opening_balance'],2); ?></td>
              <td class="text-end"><?php echo number_format($r['total_cash_sales']-$r['total_cash_returns'],2); ?></td>
              <td class="text-end"><?php echo number_format($r['cash_from_credit_customers'],2); ?></td>
              <td class="text-end"><?php echo number_format($r['total_cash_expenses'],2); ?></td>
              <td class="text-end"><?php echo number_format($r['supplier_payment_cash'],2); ?></td>
              <td class="text-end"><?php echo number_format($r['cargo_payment_cash'],2); ?></td>
              <td class="text-end"><?php echo number_format($r['other_cash_out'],2); ?></td>
              <td class="text-end"><?php echo number_format($r['calculated_closing_balance'],2); ?></td>
              <td class="text-end"><?php echo number_format((float)$r['actual_closing_balance'],2); ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <div class="text-muted small">Tip: If Actual is empty, the system still uses Calculated for carry-forward.</div>
    </div>
  </div>
  <?php else: ?>
    <div class="alert alert-info">Select a specific branch to view and operate the cash drawer.</div>
  <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
  function toggleCustomRange(val){
    const show = (val === 'custom');
    document.getElementById('startDateDiv').style.display = show ? 'block' : 'none';
    document.getElementById('endDateDiv').style.display = show ? 'block' : 'none';
  }
  (function(){
    var sel = document.getElementById('rangeSelect');
    if (sel) toggleCustomRange(sel.value);
    sel && sel.addEventListener('change', function(){ toggleCustomRange(this.value); });

    // Filter "Destination Account" by type
    var typeSel = document.getElementById('destType');
    var acctSel = document.getElementById('destId');
    if (typeSel && acctSel) {
      typeSel.addEventListener('change', function() {
        var t = this.value;
        var opts = acctSel.options;
        acctSel.value = '';
        for (var i=0; i<opts.length; i++) {
          var o = opts[i];
          var dt = o.getAttribute('data-type');
          if (!dt) continue; // skip the placeholder
          o.style.display = t ? (dt === t ? '' : 'none') : '';
        }
      });
    }
  })();
</script>
</body>
</html>
