<?php
require_once 'includes/header.php';
checkRole(['admin', 'manager', 'cashier']);

function fetchRow(PDO $pdo, string $sql, array $args) {
    $st = $pdo->prepare($sql);
    $st->execute($args);
    return $st->fetch(PDO::FETCH_ASSOC);
}
function fmt2($n) { return number_format((float)$n, 2); }

/* ------------------------- inputs ------------------------- */
$saleId    = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$returnId  = isset($_GET['return_id']) ? (int)$_GET['return_id'] : 0;

if ($saleId <= 0) { echo "<p>Invalid sale ID.</p>"; exit; }

/* If return_id not provided, try to discover it via settled_with_sale_id */
if ($returnId <= 0) {
    try {
        $ret = fetchRow(
            $pdo,
            "SELECT id FROM sale_returns WHERE settled_with_sale_id = ? LIMIT 1",
            [$saleId]
        );
        if ($ret && !empty($ret['id'])) $returnId = (int)$ret['id'];
    } catch (Throwable $e) {
        // fallback for older schemas (optional)
        try {
            $ret = fetchRow(
                $pdo,
                "SELECT id FROM sale_returns WHERE new_sale_id = ? OR merged_sale_id = ? OR linked_sale_id = ? LIMIT 1",
                [$saleId, $saleId, $saleId]
            );
            if ($ret && !empty($ret['id'])) $returnId = (int)$ret['id'];
        } catch (Throwable $e2) { /* ignore */ }
    }
}

/* -------------------- load base sale ---------------------- */
$baseSale = fetchRow(
    $pdo,
    "SELECT s.*, 
            b.name AS branch_name, b.address AS branch_address,
            u.username AS cashier_name,
            c.name AS customer_name,
            w.name AS worker_name
     FROM sales s
     JOIN branches b   ON s.branch_id = b.id
     JOIN users u      ON s.sold_by   = u.id
     LEFT JOIN customers c ON c.id = s.customer_id
     LEFT JOIN workers   w ON w.id = s.worker_number
     WHERE s.id = ?",
    [$saleId]
);

if (!$baseSale) { echo "<p>Sale not found.</p>"; exit; }

/* ----------------------- permission ----------------------- */
$allow = ($user['role_name'] === 'admin')
      || ($user['role_name'] === 'manager' && (int)$baseSale['branch_id'] === (int)$user['branch_id'])
      || ($user['role_name'] === 'cashier'  && (int)$baseSale['sold_by']   === (int)$user['id']);
if (!$allow) { echo "<p>You do not have permission to view this invoice.</p>"; exit; }

/* Effective sale: for merge prints, it's just $saleId (the newly created sale with items) */
$effectiveSaleId = $saleId;
$headerSale = $baseSale;
$mergeHint = ($returnId > 0) ? " (Merged with Return #{$returnId})" : "";

/* -------------------- items & payments -------------------- */
$itemsStmt = $pdo->prepare(
    "SELECT si.product_id, si.quantity, si.selling_price, 
            si.discount_type, si.discount_value, si.tax_rate,
            p.name AS product_name
       FROM sale_items si 
       JOIN products p ON si.product_id = p.id
      WHERE si.sale_id = ?"
);
$itemsStmt->execute([$effectiveSaleId]);
$items = $itemsStmt->fetchAll();

$payStmt = $pdo->prepare(
    "SELECT method, amount, cheque_number, bank_name, bank_branch, deposit_date
       FROM sale_payments
      WHERE sale_id = ?"
);
$payStmt->execute([$effectiveSaleId]);
$payments = $payStmt->fetchAll();

/* ------------------------- totals ------------------------- */
$subTotal = 0; $discountAmount = 0; $taxAmount = 0; $totalQty = 0;

foreach ($items as $it) {
    $qty  = (int)$it['quantity'];
    $rate = (float)$it['selling_price'];
    $line = $rate * $qty;
    $totalQty += $qty;

    $disc = 0.0;
    if (!empty($it['discount_type']) && (float)$it['discount_value'] > 0) {
        if ($it['discount_type'] === 'percentage') {
            $disc = $line * ((float)$it['discount_value'] / 100.0);
        } else {
            $disc = (float)$it['discount_value'] * $qty;
            if ($disc > $line) $disc = $line;
        }
    }
    $subTotal       += $line;
    $discountAmount += $disc;
    $taxAmount      += ($line - $disc) * ((float)$it['tax_rate'] / 100.0);
}

$overallDiscount = isset($headerSale['overall_discount']) ? max(0, (float)$headerSale['overall_discount']) : 0.0;
$grandTotal      = max(0, $subTotal - $discountAmount + $taxAmount - $overallDiscount);

/* payments applied */
$totalPaidApplied = 0.0;
foreach ($payments as $pm) $totalPaidApplied += (float)$pm['amount'];

/* tendered/change from sales if present; else reconstruct */
$amountReceived = isset($headerSale['amount_received']) ? (float)$headerSale['amount_received'] : 0.0;
$changeGiven    = isset($headerSale['change_given'])    ? (float)$headerSale['change_given']    : 0.0;
if ($amountReceived <= 0) $amountReceived = $totalPaidApplied + $changeGiven;

/* compute due/change for display */
$dueAmount = max(0, $grandTotal - $totalPaidApplied);
$changeDue = ($dueAmount > 0) ? 0.0 : max(0.0, $amountReceived - $grandTotal);

/* --------------- Return & Merge (optional) ---------------- */
$returnTotal = 0.0; $mergeBalance = 0.0; $returnSummaryItems = [];

if ($returnId > 0) {
    $r = fetchRow($pdo, "SELECT id, total_refund FROM sale_returns WHERE id = ?", [$returnId]);
    if ($r) {
        $returnTotal  = (float)$r['total_refund'];
        $mergeBalance = round($grandTotal - $returnTotal, 2);

        $rit = $pdo->prepare(
            "SELECT sri.quantity AS qty, sri.unit_price AS rate, 
                    (sri.quantity*sri.unit_price) AS subtotal, 
                    p.name, sb.batch_no
               FROM sale_return_items sri
               JOIN products p ON p.id = sri.product_id
          LEFT JOIN stock_batches sb ON sb.id = sri.batch_id
              WHERE sri.sale_return_id = ?
           ORDER BY sri.id"
        );
        $rit->execute([$returnId]);
        $returnSummaryItems = $rit->fetchAll(PDO::FETCH_ASSOC);
    }
}

/* ------------------- invoice number ---------------------- */
if (isset($headerSale['invoice_no']) && $headerSale['invoice_no'] !== null) {
    $invoiceNum = 'BR' . (int)$headerSale['branch_id'] . '-' . str_pad((int)$headerSale['invoice_no'], 4, '0', STR_PAD_LEFT);
} else {
    $invoiceNum = 'INV-' . date('Ymd', strtotime($headerSale['sale_date'])) . '-' . str_pad($headerSale['id'], 4, '0', STR_PAD_LEFT);
}

/* ------------------------- view -------------------------- */
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Invoice #<?php echo htmlspecialchars($invoiceNum); ?></title>
<style>
body{font-family:Arial,sans-serif;margin:0;padding:0}
.invoice-container{width:80mm;margin:0 auto;padding:5mm;border:1px solid #ddd}
.header{text-align:center}
.header img{max-width:50mm;height:auto}
.header h5{margin:5px 0;font-size:13pt}
.header small{font-size:8pt;color:#666}
.section{margin-top:8px;font-size:8pt}
.section h4{margin:0;font-size:9pt;border-bottom:1px dashed #000;padding-bottom:4px}
.details-table,.summary-table,.payments-table{width:100%;border-collapse:collapse;margin-top:5px;font-size:8pt}
.details-table th,.details-table td{border-bottom:1px dashed #ccc;padding:4px}
.summary-table td{padding:3px}
.payments-table th,.payments-table td{border:1px solid #ddd;padding:3px}
.footer{text-align:center;margin-top:10px;font-size:8pt}
@media print{body,html{width:80mm}.invoice-container{border:none;padding:0}}
</style>
</head>
<body>
<div class="invoice-container">
    <div class="header">
        <?php if (file_exists('./logo.png')): ?><img src="./logo.png" width="60" alt="Logo"><?php endif; ?>
        <h5>Jeyaluksan Fancy ( <?php echo htmlspecialchars($headerSale['branch_name']); ?> )</h5>
        <small><?php echo htmlspecialchars($headerSale['branch_address']); ?></small>
    </div>

    <div class="section">
        <strong>Invoice No:</strong> <?php echo htmlspecialchars($invoiceNum); ?><br>
        <strong>Date:</strong> <?php echo date('d-M-Y', strtotime($headerSale['sale_date'])); ?>
        &nbsp;&nbsp;<strong>Time:</strong> <?php echo date('h:i a', strtotime($headerSale['sale_date'])); ?><br>
        <strong>Cashier:</strong> <?php echo htmlspecialchars($headerSale['cashier_name']); ?><br>
       <?php if (!empty($headerSale['worker_number'])): ?>
    <strong>Worker ID:</strong> #<?php echo htmlspecialchars($headerSale['worker_number']); ?><br>
<?php endif; ?>

        <strong>Customer:</strong> <?php echo htmlspecialchars($headerSale['customer_name'] ?: 'Walk-in Customer'); ?>
        <?php if ($returnId > 0): ?>
            <br><small><em>Return Merge Mode<?php echo htmlspecialchars($mergeHint); ?></em></small>
        <?php endif; ?>
    </div>

    <div class="section">
        <h4>Items</h4>
        <table class="details-table">
            <thead>
            <tr>
                <th style="width:36%;text-align:left;">Description</th>
                <th style="width:12%;text-align:center;">Qty</th>
                <th style="width:14%;text-align:right;">Rate</th>
                <th style="width:14%;text-align:right;">Disc/Unit</th>
                <th style="width:12%;text-align:right;">Disc Tot</th>
                <th style="width:12%;text-align:right;">Line Tot</th>
            </tr>
            </thead>
            <tbody>
            <?php if (empty($items)): ?>
                <tr><td colspan="6" style="text-align:center;" class="text-muted">No items recorded for Sale #<?php echo (int)$effectiveSaleId; ?></td></tr>
            <?php else: foreach ($items as $it):
                $qty=(int)$it['quantity']; $rate=(float)$it['selling_price']; $line=$rate*$qty;
                $discPerUnit=0.0; $discLine=0.0;
                if (!empty($it['discount_type']) && (float)$it['discount_value']>0){
                    if ($it['discount_type']==='percentage'){ $discPerUnit=$rate*((float)$it['discount_value']/100.0); $discLine=$discPerUnit*$qty; }
                    else { $discPerUnit=(float)$it['discount_value']; $discLine=$discPerUnit*$qty; }
                    if ($discLine>$line){ $discLine=$line; $discPerUnit=$qty>0?($discLine/$qty):$discPerUnit; }
                }
                $netLine=$line-$discLine; ?>
                <tr>
                    <td><?php echo htmlspecialchars($it['product_name']); ?></td>
                    <td style="text-align:center;"><?php echo $qty; ?></td>
                    <td style="text-align:right;"><?php echo fmt2($rate); ?></td>
                    <td style="text-align:right;"><?php echo $discPerUnit>0?fmt2($discPerUnit):'-'; ?></td>
                    <td style="text-align:right;"><?php echo $discLine>0?fmt2($discLine):'-'; ?></td>
                    <td style="text-align:right;"><?php echo fmt2($netLine); ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
        <div style="margin-top:4px;">
            <small><strong>Lines:</strong> <?php echo count($items); ?> &nbsp; | &nbsp;
            <strong>Total Qty:</strong> <?php echo (int)$totalQty; ?></small>
        </div>
    </div>

    <div class="section">
        <h4>Summary</h4>
        <table class="summary-table">
            <tr><td style="width:70%;">Sub Total:</td><td style="width:30%;text-align:right;"><?php echo fmt2($subTotal); ?></td></tr>
            <tr><td>Line Discount:</td><td style="text-align:right;"><?php echo fmt2($discountAmount); ?></td></tr>
            <tr><td>Overall Discount:</td><td style="text-align:right;"><?php echo fmt2($overallDiscount); ?></td></tr>
            <tr><td>Tax:</td><td style="text-align:right;"><?php echo fmt2($taxAmount); ?></td></tr>
            <tr><td><strong>Grand Total:</strong></td><td style="text-align:right;"><strong><?php echo fmt2($grandTotal); ?></strong></td></tr>
            <tr><td>Amount Received:</td><td style="text-align:right;"><?php echo fmt2($amountReceived); ?></td></tr>
            <?php if ($dueAmount > 0): ?>
                <tr><td>Due Amount:</td><td style="text-align:right;"><?php echo fmt2($dueAmount); ?></td></tr>
            <?php else: ?>
                <tr><td>Change:</td><td style="text-align:right;"><?php echo fmt2($changeDue ?: $changeGiven); ?></td></tr>
            <?php endif; ?>
        </table>
    </div>

    <div class="section">
        <h4>Payments</h4>
        <table class="payments-table">
            <thead><tr><th>Payment Type</th><th>Amount</th></tr></thead>
            <tbody>
            <?php if (empty($payments)): ?>
                <tr><td colspan="2" style="text-align:center;">Unpaid!</td></tr>
            <?php else: foreach ($payments as $pm): ?>
                <tr>
                    <td>
                        <?php echo htmlspecialchars(ucfirst($pm['method'])); ?>
                        <?php if ($pm['method'] === 'cheque'):
                            $details=[];
                            if (!empty($pm['cheque_number'])) $details[]='No: '.htmlspecialchars($pm['cheque_number']);
                            if (!empty($pm['bank_name']))    $details[]='Bank: '.htmlspecialchars($pm['bank_name']);
                            if (!empty($pm['bank_branch']))  $details[]='Branch: '.htmlspecialchars($pm['bank_branch']);
                            if (!empty($pm['deposit_date'])) $details[]='Date: '.htmlspecialchars($pm['deposit_date']);
                            if ($details) echo '<br><small>('.implode('; ',$details).')</small>';
                        endif; ?>
                    </td>
                    <td style="text-align:right;"><?php echo fmt2((float)$pm['amount']); ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($returnId > 0 && $returnTotal > 0): ?>
    <div class="section">
        <h4>Return &amp; Merge Summary</h4>
        <table class="summary-table">
            <tr><td style="width:70%;">Return ID:</td><td style="width:30%;text-align:right;">#<?php echo htmlspecialchars($returnId); ?></td></tr>
            <tr><td>Return Total:</td><td style="text-align:right;"><?php echo fmt2($returnTotal); ?></td></tr>
            <tr><td>New Bill Total (Sale #<?php echo (int)$effectiveSaleId; ?>):</td><td style="text-align:right;"><?php echo fmt2($grandTotal); ?></td></tr>
            <tr><td><strong>Balance</strong> <?php echo ($mergeBalance>=0)?'to Collect':'to Refund'; ?>:</td><td style="text-align:right;"><strong><?php echo fmt2(abs($mergeBalance)); ?></strong></td></tr>
        </table>

        <?php if (!empty($returnSummaryItems)): ?>
        <div style="margin-top:6px;">
            <strong>Returned Items:</strong>
            <table class="details-table">
                <thead><tr><th style="width:40%;text-align:left;">Item</th><th style="width:20%;text-align:center;">Qty</th><th style="width:20%;text-align:right;">Rate</th><th style="width:20%;text-align:right;">Subtotal</th></tr></thead>
                <tbody>
                <?php foreach ($returnSummaryItems as $ri): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($ri['name']); ?><?php if (!empty($ri['batch_no'])): ?> <span class="text-muted">[<?php echo htmlspecialchars($ri['batch_no']); ?>]</span><?php endif; ?></td>
                        <td style="text-align:center;"><?php echo (float)$ri['qty']; ?></td>
                        <td style="text-align:right;"><?php echo fmt2((float)$ri['rate']); ?></td>
                        <td style="text-align:right;"><?php echo fmt2((float)$ri['subtotal']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="section">
        <p><strong>Notes:</strong> Thank you for your business!</p>
        <p><strong>Invoice T&amp;C:</strong> Goods once sold will not be taken back.</p>
    </div>
    <div class="footer">---- Thank You. Visit Again! ----</div>
</div>

<div style="text-align:center; margin:15px;">
    <a href="javascript:window.close();" class="btn btn-secondary me-2">Close</a>
    <a href="pos.php" class="btn btn-primary">Back to POS</a>
</div>
</body>
</html>
