<?php
require_once 'includes/header.php';
checkRole(['admin','manager']);

$is_admin = ($user['role_name']==='admin');
$branch_id = $is_admin
  ? (isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : 0)
  : (int)($user['branch_id'] ?? 0);

$start = $_GET['start'] ?? date('Y-m-01');
$end   = $_GET['end']   ?? date('Y-m-d');

$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 25;
$offset = ($page-1)*$limit;

$where = "cc.created_at BETWEEN :s AND :e";
$params = [':s' => "$start 00:00:00", ':e' => "$end 23:59:59"];
if (!$is_admin || $branch_id) {
    $where .= " AND cc.branch_id = :b";
    $params[':b'] = $branch_id;
}

$branches = [];
if ($is_admin) {
  try {
    $branches = $pdo->query("SELECT id,name FROM branches WHERE is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
  } catch (Throwable $e) {}
}

$sqlCount = "SELECT COUNT(*) FROM card_commissions cc WHERE $where";
$st = $pdo->prepare($sqlCount); $st->execute($params);
$total_rows = (int)$st->fetchColumn();
$total_pages = max(1, (int)ceil($total_rows / $limit));

$sql = "SELECT cc.*, s.id AS sale_no, b.name AS branch_name
        FROM card_commissions cc
        LEFT JOIN sales s ON s.id = cc.sale_id
        LEFT JOIN branches b ON b.id = cc.branch_id
        WHERE $where
        ORDER BY cc.created_at DESC
        LIMIT $limit OFFSET $offset";
$st = $pdo->prepare($sql); $st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$sqlSum = "SELECT COALESCE(SUM(cc.gross_amount),0) gross_sum,
                  COALESCE(SUM(cc.commission_amount),0) fee_sum
           FROM card_commissions cc
           WHERE $where";
$st = $pdo->prepare($sqlSum); $st->execute($params);
$sums = $st->fetch(PDO::FETCH_ASSOC);
$gross_sum = (float)$sums['gross_sum'];
$fee_sum   = (float)$sums['fee_sum'];

function nf($n){ return 'Rs. ' . number_format((float)$n,2); }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Card Commission Ledger</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<?php include 'includes/nav.php'; ?>
<div class="container my-4">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h4 class="mb-0">Card Commission Ledger</h4>
  </div>

  <form method="get" class="row g-2 align-items-end mb-3">
    <div class="col-md-3">
      <label class="form-label">From</label>
      <input type="date" name="start" class="form-control" value="<?= htmlspecialchars($start) ?>">
    </div>
    <div class="col-md-3">
      <label class="form-label">To</label>
      <input type="date" name="end" class="form-control" value="<?= htmlspecialchars($end) ?>">
    </div>
    <?php if ($is_admin): ?>
      <div class="col-md-3">
        <label class="form-label">Branch</label>
        <select class="form-select" name="branch_id">
          <option value="0" <?= $branch_id===0?'selected':''; ?>>All Branches</option>
          <?php foreach ($branches as $b): ?>
            <option value="<?= (int)$b['id'] ?>" <?= $branch_id===(int)$b['id']?'selected':''; ?>>
              <?= htmlspecialchars($b['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    <?php endif; ?>
    <div class="col-md-2">
      <button class="btn btn-primary w-100">Apply</button>
    </div>
  </form>

  <div class="card">
    <div class="card-header">
      <div class="d-flex justify-content-between">
        <span>Results</span>
        <span class="small">Gross: <strong><?= nf($gross_sum) ?></strong> &nbsp; | &nbsp; Commission: <strong class="text-danger">-<?= nf($fee_sum) ?></strong></span>
      </div>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-striped table-hover mb-0">
          <thead class="table-light">
            <tr>
              <th>Date</th>
              <th>Bill No</th>
              <th>Branch</th>
              <th class="text-end">Card Total</th>
              <th class="text-end">Rate</th>
              <th class="text-end">Commission</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$rows): ?>
              <tr><td colspan="6" class="text-center py-4 text-muted">No records</td></tr>
            <?php else: foreach ($rows as $r): ?>
              <tr>
                <td><?= htmlspecialchars(date('Y-m-d H:i', strtotime($r['created_at']))) ?></td>
                <td>
                  <?php if ($r['sale_no']): ?>
                    <a href="print_invoice.php?id=<?= (int)$r['sale_no'] ?>" target="_blank">#<?= (int)$r['sale_no'] ?></a>
                  <?php else: ?>
                    -
                  <?php endif; ?>
                </td>
                <td><?= htmlspecialchars($r['branch_name'] ?? '—') ?></td>
                <td class="text-end"><?= nf($r['gross_amount']) ?></td>
                <td class="text-end"><?= number_format((float)$r['commission_rate'],2) ?>%</td>
                <td class="text-end text-danger">-<?= nf($r['commission_amount']) ?></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
    <div class="card-footer d-flex justify-content-between align-items-center">
      <div class="small">Page <?= $page ?> / <?= $total_pages ?> (<?= $total_rows ?> rows)</div>
      <nav>
        <ul class="pagination m-0">
          <?php
          $q = $_GET; unset($q['page']);
          $base = htmlspecialchars('?' . http_build_query($q));
          ?>
          <li class="page-item <?= $page<=1?'disabled':'' ?>"><a class="page-link" href="<?= $base . '&page=' . max(1,$page-1) ?>">Prev</a></li>
          <li class="page-item <?= $page>=$total_pages?'disabled':'' ?>"><a class="page-link" href="<?= $base . '&page=' . min($total_pages,$page+1) ?>">Next</a></li>
        </ul>
      </nav>
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
