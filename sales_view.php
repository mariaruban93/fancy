<?php
require_once 'includes/header.php';
checkRole(['admin', 'manager', 'cashier']);

$saleId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($saleId <= 0) { echo "<p>Invalid sale ID.</p>"; exit; }

/* ---------- small helpers (robust + safe) ---------- */
function table_exists(PDO $pdo, string $table): bool {
  try {
    $st = $pdo->prepare("SHOW TABLES LIKE ?");
    $st->execute([$table]);
    return (bool)$st->fetchColumn();
  } catch (Throwable $e) { return false; }
}
function column_exists(PDO $pdo, string $table, string $col): bool {
  try {
    $st = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $st->execute([$col]);
    return (bool)$st->fetch(PDO::FETCH_ASSOC);
  } catch (Throwable $e) { return false; }
}
function moneyf($n){ return number_format((float)$n, 2); }

/* ---------- fetch sale header ---------- */
$stmt = $pdo->prepare("
    SELECT s.*,
           b.name      AS branch_name,
           u.username  AS sold_by_name,
           c.name      AS customer_name
    FROM sales s
    JOIN branches b ON s.branch_id = b.id
    JOIN users u    ON s.sold_by   = u.id
    LEFT JOIN customers c ON s.customer_id = c.id
    WHERE s.id = ?
");
$stmt->execute([$saleId]);
$sale = $stmt->fetch();
if (!$sale) { echo "<p>Sale not found.</p>"; exit; }

/* permissions */
if ($user['role_name'] === 'cashier' && (int)$sale['sold_by'] !== (int)$user['id']) {
  echo "<p>You do not have permission to view this sale.</p>"; exit;
}
if ($user['role_name'] === 'manager' && (int)$sale['branch_id'] !== (int)$user['branch_id']) {
  echo "<p>You do not have permission to view this sale.</p>"; exit;
}

/* ---------- sale items ---------- */
$stmtItems = $pdo->prepare("
    SELECT si.quantity, si.selling_price, si.discount_type, si.discount_value, si.tax_rate,
           p.name AS product_name,
           sb.batch_no
    FROM sale_items si
    JOIN products p          ON si.product_id = p.id
    LEFT JOIN stock_batches sb ON si.batch_id = sb.id
    WHERE si.sale_id = ?
");
$stmtItems->execute([$saleId]);
$items = $stmtItems->fetchAll();

/* ---------- payments ---------- */
$stmtPays = $pdo->prepare("
    SELECT method, amount, cheque_number, bank_name, bank_branch, deposit_date
    FROM sale_payments
    WHERE sale_id = ?
    ORDER BY id
");
$stmtPays->execute([$saleId]);
$payments = $stmtPays->fetchAll();

/* ---------- optional card commission summary (from card_commissions) ---------- */
$cardSummary = ['gross_total'=>0.0,'rate'=>null,'commission_total'=>0.0];
try {
  if (table_exists($pdo,'card_commissions')) {
    $q = $pdo->prepare("
        SELECT COALESCE(SUM(gross_amount),0) AS gross_total,
               MAX(commission_rate)          AS rate,
               COALESCE(SUM(commission_amount),0) AS commission_total
        FROM card_commissions
        WHERE sale_id = ?
    ");
    $q->execute([$saleId]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    if ($row) {
      $cardSummary['gross_total']      = (float)$row['gross_total'];
      $cardSummary['rate']             = is_null($row['rate']) ? null : (float)$row['rate'];
      $cardSummary['commission_total'] = (float)$row['commission_total'];
    }
  }
} catch (Throwable $e) {}

/* ---------- build invoice number ---------- */
if (isset($sale['invoice_no']) && $sale['invoice_no'] !== null) {
  $invoice_number = 'BR' . (int)$sale['branch_id'] . '-' . str_pad((int)$sale['invoice_no'], 4, '0', STR_PAD_LEFT);
} else {
  $invoice_number = 'INV-' . date('Ymd', strtotime($sale['sale_date'])) . '-' . $sale['id'];
}

/* ---------- compute totals ---------- */
$subtotalBeforeDiscount = 0.0;
$lineDiscountTotal      = 0.0;
$taxTotal               = 0.0;
$totalQty               = 0;
$linesCount             = count($items);

foreach ($items as $it) {
  $qty   = (int)$it['quantity'];
  $rate  = (float)$it['selling_price'];
  $line  = $rate * $qty;
  $subtotalBeforeDiscount += $line;
  $totalQty += $qty;

  // discount per unit
  $discUnit = 0.0;
  if (!empty($it['discount_type']) && (float)$it['discount_value'] > 0) {
    if ($it['discount_type'] === 'percentage') {
      $discUnit = $rate * ((float)$it['discount_value'] / 100.0);
    } else {
      $discUnit = (float)$it['discount_value']; // fixed per unit
    }
    if ($discUnit > $rate) $discUnit = $rate;
  }
  $discTot = min($discUnit * $qty, $line);
  $lineDiscountTotal += $discTot;

  $taxRate = (float)$it['tax_rate'];
  if ($taxRate > 0) {
    $taxBase = $line - $discTot;
    $taxTotal += ($taxBase * $taxRate / 100.0);
  }
}

$overallDiscount = isset($sale['overall_discount']) ? (float)$sale['overall_discount'] : 0.0;
if ($overallDiscount < 0) $overallDiscount = 0.0;

$grandTotal = $subtotalBeforeDiscount - $lineDiscountTotal - $overallDiscount + $taxTotal;

$totalPaid = 0.0;
foreach ($payments as $pm) { $totalPaid += (float)$pm['amount']; }
$change    = max($totalPaid - $grandTotal, 0.0);
$dueAmount = max($grandTotal - $totalPaid, 0.0);

/* ---------- payment labels (plus proportional card commission text) ---------- */
$paymentsForView = [];
$cardGross = $cardSummary['gross_total'];
$cardComm  = $cardSummary['commission_total'];
foreach ($payments as $pm) {
  $label = ucfirst(strtolower($pm['method']));
  if (strtolower($pm['method']) === 'cheque') {
    $d = [];
    if (!empty($pm['cheque_number'])) $d[] = 'No: ' . htmlspecialchars($pm['cheque_number']);
    if (!empty($pm['bank_name']))     $d[] = 'Bank: ' . htmlspecialchars($pm['bank_name']);
    if (!empty($pm['bank_branch']))   $d[] = 'Branch: ' . htmlspecialchars($pm['bank_branch']);
    if (!empty($pm['deposit_date']))  $d[] = 'Deposit: ' . htmlspecialchars($pm['deposit_date']);
    if ($d) $label .= ' (' . implode(', ', $d) . ')';
  }
  // append card commission (proportional)
  if (strtolower($pm['method']) === 'card' && $cardGross > 0 && $cardComm > 0) {
    $rowComm = round(((float)$pm['amount'] / $cardGross) * $cardComm, 2);
    $bankNet = round(((float)$pm['amount'] - $rowComm), 2);
    $label  .= ' — Comm: ' . moneyf($rowComm) . ', Bank: ' . moneyf($bankNet);
  }
  $paymentsForView[] = ['label'=>$label, 'amount'=>(float)$pm['amount']];
}
$singleMethodLabel = (count($paymentsForView) === 1) ? $paymentsForView[0]['label'] : '';

/* ---------- try to find a linked return for this sale ---------- */
function find_linked_return(PDO $pdo, int $saleId): ?array {
  // 1) explicit link tables
  $linkTables = [
    ['name'=>'sale_return_links','sale_col'=>'sale_id','ret_col'=>'return_id'],
    ['name'=>'return_merges','sale_col'=>'sale_id','ret_col'=>'return_id'],
    ['name'=>'return_merge','sale_col'=>'sale_id','ret_col'=>'return_id'],
    ['name'=>'return_links','sale_col'=>'sale_id','ret_col'=>'return_id'],
  ];
  foreach ($linkTables as $lt) {
    if (!table_exists($pdo, $lt['name'])) continue;
    try {
      $st = $pdo->prepare("SELECT {$lt['ret_col']} AS rid FROM {$lt['name']} WHERE {$lt['sale_col']} = ? ORDER BY 1 DESC LIMIT 1");
      $st->execute([$saleId]);
      $rid = $st->fetchColumn();
      if ($rid) return ['id'=>(int)$rid, 'base'=>null];
    } catch (Throwable $e) {}
  }

  // 2) returns tables that carry a pointer to the sale (merged_sale_id or sale_id)
  $retTables = ['sales_returns','sale_returns','returns','return_bills','sales_return'];
  foreach ($retTables as $rt) {
    if (!table_exists($pdo, $rt)) continue;

    // prefer merged_sale_id if present
    if (column_exists($pdo, $rt, 'merged_sale_id')) {
      try {
        $st = $pdo->prepare("SELECT id FROM {$rt} WHERE merged_sale_id = ? ORDER BY id DESC LIMIT 1");
        $st->execute([$saleId]);
        $rid = $st->fetchColumn();
        if ($rid) return ['id'=>(int)$rid, 'base'=>$rt];
      } catch (Throwable $e) {}
    }

    // fallback to sale_id if present
    if (column_exists($pdo, $rt, 'sale_id')) {
      try {
        $st = $pdo->prepare("SELECT id FROM {$rt} WHERE sale_id = ? ORDER BY id DESC LIMIT 1");
        $st->execute([$saleId]);
        $rid = $st->fetchColumn();
        if ($rid) return ['id'=>(int)$rid, 'base'=>$rt];
      } catch (Throwable $e) {}
    }
  }
  return null;
}

function get_return_details(PDO $pdo, int $returnId, ?string $baseTable): ?array {
  $out = ['id'=>$returnId, 'date'=>null, 'total'=>0.0, 'items'=>[]];

  // pick a returns table to read header (total/date)
  $candidates = $baseTable ? [$baseTable] : ['sales_returns','sale_returns','returns','return_bills','sales_return'];
  foreach ($candidates as $tbl) {
    if (!table_exists($pdo, $tbl)) continue;

    $totCols = ['grand_total','return_total','total_amount','amount','net_total'];
    $dateCols = ['return_date','date','created_at','created_on'];
    $totCol = null; $dateCol = null;
    foreach ($totCols as $c) if (column_exists($pdo,$tbl,$c)) { $totCol = $c; break; }
    foreach ($dateCols as $c) if (column_exists($pdo,$tbl,$c)) { $dateCol = $c; break; }
    if (!$totCol) continue;

    try {
      $sql = "SELECT {$totCol} AS total" . ($dateCol ? ", {$dateCol} AS dt" : ", NULL AS dt") .
             " FROM {$tbl} WHERE id = ? LIMIT 1";
      $st = $pdo->prepare($sql);
      $st->execute([$returnId]);
      if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $out['total'] = (float)$row['total'];
        $out['date']  = $row['dt'];
        $baseTable = $tbl;
        break;
      }
    } catch (Throwable $e) {}
  }
  if (!$baseTable) return $out; // header not found; return minimal info

  // locate items table
  $itemsCandidates = array_unique(array_merge([
    rtrim($baseTable,'s').'_items',
    $baseTable.'_items',
  ], ['sales_return_items','sale_return_items','return_items','return_bill_items']));

  foreach ($itemsCandidates as $itbl) {
    if (!table_exists($pdo, $itbl)) continue;

    $fkCols = ['return_id','sales_return_id','sale_return_id','return_bill_id'];
    $fk = null; foreach ($fkCols as $c) if (column_exists($pdo,$itbl,$c)) { $fk = $c; break; }
    if (!$fk) continue;

    $qtyCol = column_exists($pdo,$itbl,'qty') ? 'qty' : (column_exists($pdo,$itbl,'quantity') ? 'quantity' : null);
    $rateCol= column_exists($pdo,$itbl,'rate') ? 'rate' : (column_exists($pdo,$itbl,'price') ? 'price' : null);
    $subCol = column_exists($pdo,$itbl,'subtotal') ? 'subtotal' : null;
    $prodCol= column_exists($pdo,$itbl,'product_id') ? 'product_id' : null;
    $batchCol= column_exists($pdo,$itbl,'batch_id') ? 'batch_id' : null;
    if (!$qtyCol || !$rateCol) continue;

    try {
      $sql = "SELECT {$qtyCol} AS qty, {$rateCol} AS rate" .
             ($subCol ? ", {$subCol} AS subtotal" : ", ({$qtyCol} * {$rateCol}) AS subtotal") .
             ($prodCol ? ", {$prodCol} AS product_id" : "") .
             ($batchCol ? ", {$batchCol} AS batch_id" : "") .
             " FROM {$itbl} WHERE {$fk} = ?";
      $st = $pdo->prepare($sql);
      $st->execute([$returnId]);
      $rows = $st->fetchAll(PDO::FETCH_ASSOC);
      if ($rows) {
        $nameStmt  = $prodCol  ? $pdo->prepare("SELECT name FROM products WHERE id=?") : null;
        $batchStmt = $batchCol ? $pdo->prepare("SELECT batch_no FROM stock_batches WHERE id=?") : null;

        foreach ($rows as $r) {
          $name = 'Item';
          if ($prodCol) { $nameStmt->execute([$r['product_id']]); $n = $nameStmt->fetchColumn(); if ($n) $name = $n; }
          $suffix = '';
          if ($batchCol) { $batchStmt->execute([$r['batch_id']]); $b = $batchStmt->fetchColumn(); if ($b) $suffix = ' (Batch: '.$b.')'; }
          $out['items'][] = [
            'name'     => $name.$suffix,
            'qty'      => (int)$r['qty'],
            'rate'     => (float)$r['rate'],
            'subtotal' => (float)$r['subtotal'],
          ];
        }
        if ($out['total'] <= 0) {
          $out['total'] = array_sum(array_column($out['items'],'subtotal'));
        }
        break;
      }
    } catch (Throwable $e) {}
  }

  return $out;
}

$linkedReturn = null;
try {
  $link = find_linked_return($pdo, $saleId);
  if ($link) $linkedReturn = get_return_details($pdo, $link['id'], $link['base']);
} catch (Throwable $e) {
  $linkedReturn = null; // fail-quietly
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Sale Details - POS System</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    .invoice-wrap { max-width: 920px; margin: 0 auto; }
    .table-smaller th, .table-smaller td { padding:.45rem .5rem; }
    @media print { .no-print { display:none !important; } body { background:#fff; } }
  </style>
</head>
<body>
<?php include 'includes/nav.php'; ?>

<div class="container invoice-wrap my-4">
  <div class="d-flex justify-content-between align-items-center mb-3 no-print">
    <h1 class="m-0">Sale Details</h1>
    <a href="sales_list.php" class="btn btn-secondary">Back to Sales List</a>
  </div>

  <div class="card mb-4">
    <div class="card-body">
      <div class="row">
        <div class="col-md-6">
          <h5 class="card-title mb-1">Sale #<?= (int)$sale['id']; ?></h5>
          <div><strong>Invoice No:</strong> <?= htmlspecialchars($invoice_number); ?></div>
          <div><strong>Date:</strong> <?= htmlspecialchars(date('d-M-Y', strtotime($sale['sale_date']))); ?>
              &nbsp; <strong>Time:</strong> <?= htmlspecialchars(date('h:i a', strtotime($sale['sale_date']))); ?></div>
        </div>
        <div class="col-md-6 text-md-end mt-2 mt-md-0">
          <div><strong>Branch:</strong> <?= htmlspecialchars($sale['branch_name']); ?></div>
          <div><strong>Cashier:</strong> <?= htmlspecialchars($sale['sold_by_name']); ?></div>
          <div><strong>Customer:</strong> <?= htmlspecialchars($sale['customer_name'] ?: 'Walk-in Customer'); ?></div>
        </div>
      </div>
    </div>
  </div>

  <h4 class="mb-2">Items</h4>
  <div class="table-responsive">
    <table class="table table-bordered table-smaller">
      <thead class="table-light">
      <tr>
        <th>Description</th>
        <th class="text-center">Qty</th>
        <th class="text-end">Rate</th>
        <th class="text-end">Disc/Unit</th>
        <th class="text-end">Disc Tot</th>
        <th class="text-end">Line Tot</th>
      </tr>
      </thead>
      <tbody>
      <?php foreach ($items as $it):
        $qty   = (int)$it['quantity'];
        $rate  = (float)$it['selling_price'];
        $line  = $rate * $qty;

        $discUnit = 0.0;
        if (!empty($it['discount_type']) && (float)$it['discount_value'] > 0) {
          if ($it['discount_type'] === 'percentage') {
            $discUnit = $rate * ((float)$it['discount_value'] / 100.0);
          } else {
            $discUnit = (float)$it['discount_value'];
          }
          if ($discUnit > $rate) $discUnit = $rate;
        }
        $discTot = min($discUnit * $qty, $line);
        $lineTot = $line - $discTot;

        $name = $it['product_name'] . ($it['batch_no'] ? ' (Batch: ' . $it['batch_no'] . ')' : '');
      ?>
        <tr>
          <td><?= htmlspecialchars($name); ?></td>
          <td class="text-center"><?= $qty; ?></td>
          <td class="text-end"><?= moneyf($rate); ?></td>
          <td class="text-end"><?= moneyf($discUnit); ?></td>
          <td class="text-end"><?= moneyf($discTot); ?></td>
          <td class="text-end"><?= moneyf($lineTot); ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="small text-muted mb-3">
    Lines: <strong><?= (int)$linesCount; ?></strong> &nbsp; | &nbsp; Total Qty: <strong><?= (int)$totalQty; ?></strong>
  </div>

  <div class="card">
    <div class="card-body">
      <div class="row mb-2">
        <div class="col-md-6"><h5 class="m-0">Summary</h5></div>
        <div class="col-md-6 text-md-end">
          <strong>Payment Type:</strong>
          <?= count($paymentsForView) === 1 ? htmlspecialchars($singleMethodLabel) : 'Multiple' ?>
        </div>
      </div>

      <div class="table-responsive">
        <table class="table table-bordered table-smaller m-0">
          <tbody>
            <tr><td class="text-end"><strong>Sub Total:</strong></td>
                <td class="text-end" style="width:180px"><?= moneyf($subtotalBeforeDiscount); ?></td></tr>
            <tr><td class="text-end"><strong>Line Discount:</strong></td>
                <td class="text-end"><?= moneyf($lineDiscountTotal); ?></td></tr>
            <tr><td class="text-end"><strong>Overall Discount:</strong></td>
                <td class="text-end"><?= moneyf($overallDiscount); ?></td></tr>
            <tr><td class="text-end"><strong>Tax:</strong></td>
                <td class="text-end"><?= moneyf($taxTotal); ?></td></tr>
            <tr><td class="text-end"><strong>Grand Total:</strong></td>
                <td class="text-end"><?= moneyf($grandTotal); ?></td></tr>
            <tr><td class="text-end"><strong>Amount Received:</strong></td>
                <td class="text-end"><?= moneyf($totalPaid); ?></td></tr>
            <tr><td class="text-end">
                  <strong><?= ($change > 0 ? 'Change Amount:' : 'Due Amount:'); ?></strong></td>
                <td class="text-end"><?= moneyf($change > 0 ? $change : $dueAmount); ?></td></tr>
          </tbody>
        </table>
      </div>

      <?php if ($cardSummary['gross_total'] > 0 && $cardSummary['commission_total'] > 0): ?>
        <div class="mt-2 small text-muted">
          Card Total: <strong><?= moneyf($cardSummary['gross_total']); ?></strong>
          &nbsp; | &nbsp; Commission<?= is_null($cardSummary['rate']) ? '' : ' ('.moneyf($cardSummary['rate']).'%)' ?>:
          <strong><?= moneyf($cardSummary['commission_total']); ?></strong>
          &nbsp; | &nbsp; Net to Bank:
          <strong><?= moneyf($cardSummary['gross_total'] - $cardSummary['commission_total']); ?></strong>
        </div>
      <?php endif; ?>

      <?php if (count($paymentsForView) > 1): ?>
        <div class="mt-3">
          <h6>Payments</h6>
          <table class="table table-bordered table-smaller">
            <thead class="table-light">
              <tr><th>Payment Type</th><th class="text-end">Amount</th></tr>
            </thead>
            <tbody>
              <?php foreach ($paymentsForView as $pm): ?>
                <tr>
                  <td><?= $pm['label']; ?></td>
                  <td class="text-end"><?= moneyf($pm['amount']); ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>

      <?php if ($linkedReturn): ?>
        <hr class="my-3">
        <div class="d-flex justify-content-between align-items-center">
          <h5 class="m-0">Merged Return</h5>
          <div class="small text-muted">
            Return #<?= (int)$linkedReturn['id']; ?>
            <?php if (!empty($linkedReturn['date'])): ?>
              &nbsp; | &nbsp; Date: <?= htmlspecialchars(date('d-M-Y', strtotime($linkedReturn['date']))); ?>
            <?php endif; ?>
          </div>
        </div>

        <?php if (!empty($linkedReturn['items'])): ?>
          <div class="table-responsive mt-2">
            <table class="table table-bordered table-smaller">
              <thead class="table-light">
                <tr>
                  <th>Item</th>
                  <th class="text-center">Qty</th>
                  <th class="text-end">Rate</th>
                  <th class="text-end">Subtotal</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($linkedReturn['items'] as $ri): ?>
                  <tr>
                    <td><?= htmlspecialchars($ri['name']); ?></td>
                    <td class="text-center"><?= (int)$ri['qty']; ?></td>
                    <td class="text-end"><?= moneyf($ri['rate']); ?></td>
                    <td class="text-end"><?= moneyf($ri['subtotal']); ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
              <tfoot>
                <tr>
                  <td colspan="3" class="text-end"><strong>Return Total:</strong></td>
                  <td class="text-end"><strong><?= moneyf($linkedReturn['total']); ?></strong></td>
                </tr>
              </tfoot>
            </table>
          </div>
        <?php else: ?>
          <div class="mt-2">Return Total: <strong><?= moneyf($linkedReturn['total']); ?></strong></div>
        <?php endif; ?>

        <?php
          $mergeBalance = $grandTotal - (float)$linkedReturn['total'];
          $mergeText = $mergeBalance >= 0 ? 'Balance to Collect' : 'Refund to Customer';
        ?>
        <div class="mt-2">
          <span class="fw-bold"><?= $mergeText; ?>:</span>
          <span><?= moneyf(abs($mergeBalance)); ?></span>
        </div>
      <?php endif; ?>

      <div class="mt-3">
        <p class="mb-1"><strong>Notes:</strong> Thank you for your business!</p>
        <p class="text-muted small mb-0"><strong>Invoice T&amp;C:</strong> Goods once sold will not be taken back.</p>
      </div>

      <div class="d-flex justify-content-between align-items-center mt-3 no-print">
        <a href="print_invoice.php?id=<?= (int)$sale['id']; ?>" target="_blank" class="btn btn-primary">Thermal Format</a>
        <div>
          <button class="btn btn-secondary me-2" onclick="window.print();">Print</button>
          <a href="pos.php" class="btn btn-link">Close Back to POS</a>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
