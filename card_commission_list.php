<?php
require_once 'includes/header.php';
checkRole(['admin','manager']);

$branch_id = (int)($user['branch_id'] ?? 0);
if ($user['role_name'] === 'admin' && isset($_GET['branch_id'])) {
  $branch_id = (int)$_GET['branch_id'];
}

$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to']   ?? date('Y-m-d');

$sql = "
  SELECT
    cc.id,
    cc.sale_id,
    s.invoice_no,
    cc.gross_amount,
    cc.commission_rate,
    cc.commission_amount,
    cc.created_at
  FROM card_commissions cc
  JOIN sales s ON s.id = cc.sale_id
  WHERE cc.branch_id = :branch
    AND DATE(cc.created_at) BETWEEN :from AND :to
  ORDER BY cc.created_at DESC, cc.id DESC
";
$stmt = $pdo->prepare($sql);
$stmt->execute(['branch'=>$branch_id, 'from'=>$from, 'to'=>$to]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html><html><head>
<meta charset="utf-8"><title>Card Commissions</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
</head><body class="p-3">
<div class="container-fluid">
  <h4>Card Commissions</h4>
  <form class="row g-2 mb-3">
    <?php if ($user['role_name']==='admin'): ?>
    <div class="col-auto">
      <select name="branch_id" class="form-select" onchange="this.form.submit()">
        <?php
          $bs = $pdo->query("SELECT id,name FROM branches ORDER BY name")->fetchAll();
          foreach ($bs as $b) {
            $sel = ((int)$b['id']===$branch_id ? 'selected' : '');
            echo "<option value=\"{$b['id']}\" $sel>".htmlspecialchars($b['name'])."</option>";
          }
        ?>
      </select>
    </div>
    <?php endif; ?>
    <div class="col-auto"><input type="date" name="from" value="<?=htmlspecialchars($from)?>" class="form-control"></div>
    <div class="col-auto"><input type="date" name="to"   value="<?=htmlspecialchars($to)?>"   class="form-control"></div>
    <div class="col-auto"><button class="btn btn-primary">Filter</button></div>
  </form>

  <div class="table-responsive">
    <table class="table table-striped table-bordered align-middle">
      <thead class="table-light">
        <tr>
          <th>#</th>
          <th>Bill No</th>
          <th class="text-end">Total Amount</th>
          <th class="text-end">Commission %</th>
          <th class="text-end">Commission Amount</th>
          <th>Date</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="6" class="text-center text-muted">No commissions</td></tr>
        <?php else: foreach ($rows as $r): ?>
          <tr>
            <td><?= (int)$r['id'] ?></td>
            <td><a href="print_invoice.php?id=<?= (int)$r['sale_id'] ?>" target="_blank">
              <?= htmlspecialchars($r['invoice_no'] ?? ('Sale #'.$r['sale_id'])) ?></a></td>
            <td class="text-end"><?= number_format((float)$r['gross_amount'], 2) ?></td>
            <td class="text-end"><?= number_format((float)$r['commission_rate'], 2) ?></td>
            <td class="text-end"><?= number_format((float)$r['commission_amount'], 2) ?></td>
            <td><?= htmlspecialchars($r['created_at']) ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
      <?php
        $totGross = array_sum(array_column($rows,'gross_amount'));
        $totComm  = array_sum(array_column($rows,'commission_amount'));
      ?>
      <tfoot>
        <tr class="fw-bold">
          <td colspan="2" class="text-end">Totals</td>
          <td class="text-end"><?= number_format($totGross, 2) ?></td>
          <td></td>
          <td class="text-end"><?= number_format($totComm, 2) ?></td>
          <td></td>
        </tr>
      </tfoot>
    </table>
  </div>
</div>
</body></html>
