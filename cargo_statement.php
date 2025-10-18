<?php
// cargo_statement.php — Per-cargo-provider ledger: lists cargo services (amounts owed) and payments made
require_once 'includes/header.php';
checkRole(['admin','manager']);

/* ---------- helpers ---------- */
function column_exists(PDO $pdo, string $table, string $col): bool {
  try {
    $st = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $st->execute([$col]);
    return (bool)$st->fetch(PDO::FETCH_ASSOC);
  } catch (Throwable $e) {
    return false;
  }
}
function table_exists(PDO $pdo, string $table): bool {
  try { $pdo->query("SELECT 1 FROM `$table` LIMIT 1"); return true; }
  catch(Throwable $e) { return false; }
}
function fmt($n) { return 'Rs. '.number_format((float)$n,2); }

/* ---------- branch resolve ---------- */
$branch_id = 0;
if (($user['role_name'] ?? '') === 'admin') {
  if (isset($_GET['branch_id']) && ctype_digit($_GET['branch_id'])) {
    $branch_id = (int)$_GET['branch_id'];
    $_SESSION['sale_branch_id'] = $branch_id;
  } elseif (isset($_SESSION['sale_branch_id'])) {
    $branch_id = (int)$_SESSION['sale_branch_id'];
  } elseif (!empty($user['branch_id'])) {
    $branch_id = (int)$user['branch_id'];
  }
} else {
  $branch_id = (int)($user['branch_id'] ?? 0);
}
if ($branch_id <= 0) {
  include 'includes/nav.php';
  echo "<div class='container mt-4'><div class='alert alert-warning'>No branch selected.</div></div>";
  exit;
}

/* ---------- date defaults ---------- */
$today = new DateTime();
$from = (isset($_GET['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'])) ? $_GET['from'] : $today->format('Y-m-01');
$to   = (isset($_GET['to'])   && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to']))   ? $_GET['to']   : $today->format('Y-m-t');
$fromDT = $from.' 00:00:00';
$toDT   = $to.' 23:59:59';

/* ---------- branch name ---------- */
$branches = [];
$branch_name = '';
try {
  if (($user['role_name'] ?? '') === 'admin') {
    $branches = $pdo->query("SELECT id, name FROM branches WHERE is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
  }
  $st = $pdo->prepare("SELECT name FROM branches WHERE id=?");
  $st->execute([$branch_id]);
  $branch_name = (string)($st->fetchColumn() ?: '');
} catch (Throwable $e) {}
if (!$branch_name && !empty($user['branch_name'])) $branch_name = $user['branch_name'];

/* ---------- providers dropdown ---------- */
$providers = [];
try {
  $providers = $pdo->query("SELECT id, name FROM cargo_providers WHERE is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  $providers = [];
}
if (empty($providers)) {
  include 'includes/nav.php';
  echo "<div class='container mt-4'><div class='alert alert-danger'>No cargo providers found.</div></div>";
  exit;
}
$provider_id = (isset($_GET['provider_id']) && ctype_digit($_GET['provider_id'])) ? (int)$_GET['provider_id'] : (int)$providers[0]['id'];
$providerMap = [];
foreach ($providers as $p) $providerMap[(int)$p['id']] = $p;
if (!isset($providerMap[$provider_id])) $provider_id = (int)$providers[0]['id'];

/* ---------- feature flags ---------- */
$hasServices = table_exists($pdo, 'cargo_services');
$hasPayments = table_exists($pdo, 'cargo_payments');

$hasServProv = $hasServices && column_exists($pdo, 'cargo_services', 'cargo_provider_id');
$hasServBr   = $hasServices && column_exists($pdo, 'cargo_services', 'branch_id');
$hasServDate = $hasServices && column_exists($pdo, 'cargo_services', 'transfer_date');
$hasServAmt  = $hasServices && column_exists($pdo, 'cargo_services', 'amount');

$hasPayProv  = $hasPayments && column_exists($pdo, 'cargo_payments', 'cargo_provider_id');
$hasPayBr    = $hasPayments && column_exists($pdo, 'cargo_payments', 'branch_id');
$hasPayAmt   = $hasPayments && column_exists($pdo, 'cargo_payments', 'amount');
$hasPayDate  = $hasPayments && column_exists($pdo, 'cargo_payments', 'paid_at');
$hasPayMeth  = $hasPayments && column_exists($pdo, 'cargo_payments', 'method');

/* ---------- compute opening balance (before selected range) ---------- */
$opening = 0.0;
// Services increase payable (credit)
if ($hasServProv && $hasServAmt && $hasServDate) {
  try {
    $sql = "SELECT COALESCE(SUM(amount),0) FROM cargo_services WHERE cargo_provider_id=:pid AND transfer_date < :f";
    $params = [':pid' => $provider_id, ':f' => $fromDT];
    if ($hasServBr) { $sql .= " AND branch_id=:b"; $params[':b'] = $branch_id; }
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $opening += (float)$st->fetchColumn();
  } catch (Throwable $e) {}
}
// Payments reduce payable (debit)
if ($hasPayProv && $hasPayAmt) {
  try {
    $datecol = $hasPayDate ? 'paid_at' : 'created_at';
    $sql = "SELECT COALESCE(SUM(amount),0) FROM cargo_payments WHERE cargo_provider_id=:pid AND $datecol < :f";
    $params = [':pid' => $provider_id, ':f' => $fromDT];
    if ($hasPayBr) { $sql .= " AND branch_id=:b"; $params[':b'] = $branch_id; }
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $opening -= (float)$st->fetchColumn();
  } catch (Throwable $e) {}
}

/* ---------- rows ---------- */
$rows = [];
// Services
if ($hasServProv && $hasServAmt && $hasServDate) {
  try {
    $sql = "SELECT id, transfer_date AS ts, amount FROM cargo_services WHERE cargo_provider_id=:pid AND transfer_date BETWEEN :f AND :t";
    $params = [':pid' => $provider_id, ':f' => $fromDT, ':t' => $toDT];
    if ($hasServBr) { $sql .= " AND branch_id=:b"; $params[':b'] = $branch_id; }
    $sql .= " ORDER BY ts, id";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
      $rows[] = [
        'id'     => 'CS#' . (int)$r['id'],
        'ts'     => $r['ts'],
        'desc'   => 'Cargo Service',
        'credit' => (float)$r['amount'],
        'debit'  => 0.0,
      ];
    }
  } catch (Throwable $e) {}
}
// Payments
if ($hasPayProv && $hasPayAmt) {
  try {
    $datecol = $hasPayDate ? 'paid_at' : 'created_at';
    $sql = "SELECT id, $datecol AS ts, amount, method FROM cargo_payments WHERE cargo_provider_id=:pid AND $datecol BETWEEN :f AND :t";
    $params = [':pid' => $provider_id, ':f' => $fromDT, ':t' => $toDT];
    if ($hasPayBr) { $sql .= " AND branch_id=:b"; $params[':b'] = $branch_id; }
    $sql .= " ORDER BY ts, id";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
      $rows[] = [
        'id'     => 'CP#' . (int)$r['id'],
        'ts'     => $r['ts'],
        'desc'   => 'Payment' . ($hasPayMeth ? ' (' . strtoupper((string)$r['method']) . ')' : ''),
        'credit' => 0.0,
        'debit'  => (float)$r['amount'],
      ];
    }
  } catch (Throwable $e) {}
}

// Sort all rows by timestamp then id (in case both lists added unsorted)
usort($rows, function ($a, $b) {
  $t1 = strtotime($a['ts']); $t2 = strtotime($b['ts']);
  if ($t1 === $t2) return strcmp($a['id'], $b['id']);
  return ($t1 < $t2) ? -1 : 1;
});

/* ---------- output ---------- */
include 'includes/nav.php';
?>
<div class="container mt-4">
  <h3>Cargo Provider Statement</h3>
  <p>Branch: <strong><?= htmlspecialchars($branch_name) ?></strong></p>
  <form class="row g-3 mb-3" method="get">
    <?php if (($user['role_name'] ?? '') === 'admin'): ?>
      <div class="col-md-3">
        <label class="form-label">Branch</label>
        <select name="branch_id" class="form-select" onchange="this.form.submit()">
          <?php foreach ($branches as $b): ?>
            <option value="<?= (int)$b['id'] ?>" <?= ((int)$b['id'] === $branch_id ? 'selected' : '') ?>><?= htmlspecialchars($b['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    <?php endif; ?>
    <div class="col-md-3">
      <label class="form-label">Cargo Provider</label>
      <select name="provider_id" class="form-select" onchange="this.form.submit()">
        <?php foreach ($providers as $p): ?>
          <option value="<?= (int)$p['id'] ?>" <?= ((int)$p['id'] === $provider_id ? 'selected' : '') ?>><?= htmlspecialchars($p['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3">
      <label class="form-label">From</label>
      <input type="date" name="from" value="<?= htmlspecialchars($from) ?>" class="form-control">
    </div>
    <div class="col-md-3">
      <label class="form-label">To</label>
      <input type="date" name="to" value="<?= htmlspecialchars($to) ?>" class="form-control">
    </div>
  </form>

  <?php
  // Opening balance display
  $running = $opening;
  ?>
  <div class="table-responsive">
    <table class="table table-bordered">
      <thead class="table-light">
        <tr>
          <th>ID</th>
          <th>Date</th>
          <th>Description</th>
          <th class="text-end">Credit</th>
          <th class="text-end">Debit</th>
          <th class="text-end">Balance</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td colspan="5" class="text-end"><strong>Opening Balance</strong></td>
          <td class="text-end"><strong><?= fmt($running) ?></strong></td>
        </tr>
        <?php foreach ($rows as $r):
          $running += $r['credit'];
          $running -= $r['debit'];
        ?>
          <tr>
            <td><?= htmlspecialchars($r['id']) ?></td>
            <td><?= htmlspecialchars(date('Y-m-d', strtotime($r['ts']))) ?></td>
            <td><?= htmlspecialchars($r['desc']) ?></td>
            <td class="text-end"><?= $r['credit'] ? fmt($r['credit']) : '' ?></td>
            <td class="text-end"><?= $r['debit']  ? fmt($r['debit'])  : '' ?></td>
            <td class="text-end"><?= fmt($running) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>