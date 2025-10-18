<?php
/**
 * supplier_report.php (FINAL + "All" default)
 *
 * - NEW: Range includes "All" (default) which applies NO date filter.
 * - Other ranges: Today / This Month / This Year / Custom (shows date inputs).
 * - Columns: Supplier | Purchases | Cargo Transfers | Net Purchases | Returns | Payments | Outstanding | Action
 * - Outstanding = MAX( (Purchases − CargoTransfers) − Returns − Payments, 0 )
 * - Branch/date filters applied consistently to ALL subqueries (positional placeholders).
 */

require_once 'includes/header.php';
checkRole(['admin','manager']);

$role    = isset($user['role_name']) ? $user['role_name'] : '';
// In some installations, a manager/cashier may not have a valid branch_id assigned
// and the value defaults to 0. A branch_id of 0 is treated as "no branch" and
// should not match any suppliers. To avoid accidentally showing suppliers from
// other branches when branch_id is missing or zero, we normalise branch_id
// to -1 in such cases. A negative id will never match any real branch.
$userBidRaw = isset($user['branch_id']) ? (int)$user['branch_id'] : 0;
$userBid = ($userBidRaw > 0) ? $userBidRaw : -1;

// ---------------- Range helpers ----------------
function range_dates($range, $start_in = '', $end_in = '') {
  $today = date('Y-m-d');
  switch ($range) {
    case 'all':
      return ['', '']; // no date filter
    case 'today':
      return [$today, $today];
    case 'month':
      return [date('Y-m-01'), $today];
    case 'year':
      return [date('Y-01-01'), $today];
    case 'custom':
    default:
      $s = $start_in !== '' ? $start_in : $today;
      $e = $end_in   !== '' ? $end_in   : $today;
      return [$s, $e];
  }
}

// ---------------- Inputs ----------------
$range     = isset($_GET['range']) ? $_GET['range'] : 'all'; // default "All"
$start_in  = isset($_GET['start_date']) ? trim($_GET['start_date']) : '';
$end_in    = isset($_GET['end_date'])   ? trim($_GET['end_date'])   : '';
[$startDate, $endDate] = range_dates($range, $start_in, $end_in);

$branchId = null;
if ($role === 'manager') {
  $branchId = $userBid;
} elseif ($role === 'admin') {
  if (isset($_GET['branch_id']) && $_GET['branch_id'] !== '' && ctype_digit($_GET['branch_id'])) {
    $branchId = (int)$_GET['branch_id'];
  } else {
    $branchId = null; // All branches
  }
}

// Admin: branches for dropdown
$branches = [];
if ($role === 'admin') {
  $q = $pdo->query("SELECT id, name FROM branches ORDER BY name");
  $branches = $q ? $q->fetchAll(PDO::FETCH_ASSOC) : [];
}

// ------------- WHERE fragments (positional) -------------
$W_PURCH = '';  // purchases (p), purchase_date
$W_PAY   = '';  // purchase_payments (pp) joined to p, filtered by p.purchase_date (kept consistent)
$W_RET   = '';  // purchase_returns (pr) joined to p, filtered by pr.return_date
$W_CARGO = '';  // cargo_services (cs), transfer_date

$binds = [];

// helper adds WHERE/AND
function _wa($cur) { return $cur === '' ? ' WHERE ' : ' AND '; }

// Purchases
if (!is_null($branchId)) { $W_PURCH .= _wa($W_PURCH)."p.branch_id = ?"; $binds[] = $branchId; }
if ($startDate !== '' && $endDate !== '' && $range !== 'all') {
  $W_PURCH .= _wa($W_PURCH)."DATE(p.purchase_date) BETWEEN ? AND ?";
  $binds[] = $startDate; $binds[] = $endDate;
}

// Payments (using p.purchase_date for filter; switch to pp.paid_at if you prefer)
if (!is_null($branchId)) { $W_PAY .= _wa($W_PAY)."p.branch_id = ?"; $binds[] = $branchId; }
if ($startDate !== '' && $endDate !== '' && $range !== 'all') {
  $W_PAY .= _wa($W_PAY)."DATE(p.purchase_date) BETWEEN ? AND ?";
  $binds[] = $startDate; $binds[] = $endDate;
}

// Returns (by pr.return_date)
if (!is_null($branchId)) { $W_RET .= _wa($W_RET)."p.branch_id = ?"; $binds[] = $branchId; }
if ($startDate !== '' && $endDate !== '' && $range !== 'all') {
  $W_RET .= _wa($W_RET)."DATE(pr.return_date) BETWEEN ? AND ?";
  $binds[] = $startDate; $binds[] = $endDate;
}

// Cargo transfers (by cs.transfer_date)
if (!is_null($branchId)) { $W_CARGO .= _wa($W_CARGO)."cs.branch_id = ?"; $binds[] = $branchId; }
if ($startDate !== '' && $endDate !== '' && $range !== 'all') {
  $W_CARGO .= _wa($W_CARGO)."DATE(cs.transfer_date) BETWEEN ? AND ?";
  $binds[] = $startDate; $binds[] = $endDate;
}

// ------------- Main SQL -------------
// Build the main SQL for supplier report.  We select suppliers and their aggregate
// purchase, cargo, return and payment totals.  A branch filter is applied
// directly to the suppliers list so that only suppliers associated with the
// selected branch (or global suppliers with NULL/0 branch_id) are displayed.
$sql = "
SELECT
  s.id,
  s.name,
  COALESCE(p_tot.total_cost, 0)          AS total_cost,
  COALESCE(cargo_tot.cargo_transfers, 0) AS cargo_transfers,
  COALESCE(ret_tot.total_returns, 0)     AS total_returns,
  COALESCE(pay_tot.total_paid, 0)        AS total_paid
FROM suppliers s

LEFT JOIN (
  SELECT p.supplier_id, SUM(pi.quantity * pi.cost_price) AS total_cost
  FROM purchases p
  JOIN purchase_items pi ON pi.purchase_id = p.id
  $W_PURCH
  GROUP BY p.supplier_id
) p_tot ON p_tot.supplier_id = s.id

LEFT JOIN (
  SELECT cs.supplier_id, SUM(cs.amount) AS cargo_transfers
  FROM cargo_services cs
  $W_CARGO
  GROUP BY cs.supplier_id
) cargo_tot ON cargo_tot.supplier_id = s.id

LEFT JOIN (
  SELECT p.supplier_id, SUM(pr.total_refund) AS total_returns
  FROM purchase_returns pr
  JOIN purchases p ON pr.purchase_id = p.id
  $W_RET
  GROUP BY p.supplier_id
) ret_tot ON ret_tot.supplier_id = s.id

LEFT JOIN (
  SELECT p.supplier_id, SUM(pp.amount) AS total_paid
  FROM purchases p
  JOIN purchase_payments pp ON pp.purchase_id = p.id
  $W_PAY
  GROUP BY p.supplier_id
) pay_tot ON pay_tot.supplier_id = s.id
";

// Apply supplier branch filter if a specific branch is selected.  Include
// suppliers where branch_id matches or is NULL/0 (global suppliers).
if (!is_null($branchId)) {
    // Restrict suppliers strictly to those belonging to the selected branch.
    $sql .= " WHERE s.branch_id = ?";
    // Add branch ID parameter to the binds array at the end
    $binds[] = $branchId;
}

// Always order suppliers by name
$sql .= " ORDER BY s.name";

$stmt = $pdo->prepare($sql);
$stmt->execute($binds);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Determine if suppliers table has an opening_balance column.  If present, incorporate it into the report.
$hasSuppOpening = false;
try {
    $colCheck = $pdo->prepare("SHOW COLUMNS FROM suppliers LIKE 'opening_balance'");
    $colCheck->execute();
    $hasSuppOpening = (bool)$colCheck->fetch();
} catch (Throwable $e) {
    $hasSuppOpening = false;
}

// Fetch opening balances keyed by supplier id when available
$supplierOpeningMap = [];
if ($hasSuppOpening) {
    try {
        $stOpen = $pdo->query("SELECT id, opening_balance FROM suppliers");
        while ($rowOB = $stOpen->fetch(PDO::FETCH_ASSOC)) {
            $supplierOpeningMap[(int)$rowOB['id']] = (float)$rowOB['opening_balance'];
        }
    } catch (Throwable $e) {
        $supplierOpeningMap = [];
    }
}

// Compute derived columns and totals
$sum_cost = 0.0; $sum_cargo = 0.0; $sum_net = 0.0; $sum_ret = 0.0; $sum_paid = 0.0; $sum_open = 0.0; $sum_out = 0.0;

for ($i = 0, $n = count($rows); $i < $n; $i++) {
  $cost    = (float)($rows[$i]['total_cost'] ?? 0.0);
  $cargo   = (float)($rows[$i]['cargo_transfers'] ?? 0.0);
  $returns = (float)($rows[$i]['total_returns'] ?? 0.0);
  $paid    = (float)($rows[$i]['total_paid'] ?? 0.0);

  $netPurchases = $cost - $cargo;
  if ($netPurchases < 0) $netPurchases = 0.0;

  // Add opening balance for this supplier if available
  $supplierId = (int)($rows[$i]['id'] ?? 0);
  $openingBal = 0.0;
  if ($hasSuppOpening) {
    $openingBal = $supplierOpeningMap[$supplierId] ?? 0.0;
  }

  // Outstanding includes opening balance
  $outstanding = $netPurchases - $returns - $paid + $openingBal;
  if ($outstanding < 0) $outstanding = 0.0;

  $rows[$i]['net_purchases'] = $netPurchases;
  $rows[$i]['outstanding']   = $outstanding;
  $rows[$i]['opening_balance'] = $openingBal;

  $sum_cost  += $cost;
  $sum_cargo += $cargo;
  $sum_net   += $netPurchases;
  $sum_ret   += $returns;
  $sum_paid  += $paid;
  $sum_open += $openingBal;
  $sum_out   += $outstanding;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Supplier Report</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
  <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
  <style>
    body{background:#f6f7fb}
    .badge-ghost{background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;padding:6px 10px;color:#374151}
  </style>
  <script>
    function toggleCustomRange(val){
      const show = (val === 'custom');
      const s = document.getElementById('startDateDiv');
      const e = document.getElementById('endDateDiv');
      if (s && e) { s.style.display = show ? 'block' : 'none'; e.style.display = show ? 'block' : 'none'; }
    }
    document.addEventListener('DOMContentLoaded', function(){
      const sel = document.getElementById('rangeSelect');
      if (sel) toggleCustomRange(sel.value);
    });
  </script>
</head>
<body>
<?php include 'includes/nav.php'; ?>
<div class="container my-4">
  <div class="d-flex align-items-center gap-2 mb-2">
    <h3 class="mb-0">Supplier Report</h3>
    <?php if ($role === 'manager'): ?>
      <span class="badge-ghost">My Branch</span>
    <?php elseif ($role === 'admin' && !is_null($branchId)): ?>
      <span class="badge-ghost">Branch ID: <?php echo (int)$branchId; ?></span>
    <?php else: ?>
      <span class="badge-ghost">All Branches</span>
    <?php endif; ?>
  </div>

  <!-- Range / Branch Filter -->
  <form class="row g-2 align-items-end mb-3" method="get">
    <div class="col-md-2">
      <label class="form-label">Range</label>
      <select class="form-select" name="range" id="rangeSelect" onchange="toggleCustomRange(this.value)">
        <option value="all"   <?php echo $range==='all'?'selected':''; ?>>All</option>
        <option value="today" <?php echo $range==='today'?'selected':''; ?>>Today</option>
        <option value="month" <?php echo $range==='month'?'selected':''; ?>>This Month</option>
        <option value="year"  <?php echo $range==='year'?'selected':''; ?>>This Year</option>
        <option value="custom"<?php echo $range==='custom'?'selected':''; ?>>Custom</option>
      </select>
    </div>
    <div class="col-md-3" id="startDateDiv" style="display:none;">
      <label class="form-label">Start Date</label>
      <input type="date" class="form-control" name="start_date" value="<?php echo htmlspecialchars($startDate); ?>">
    </div>
    <div class="col-md-3" id="endDateDiv" style="display:none;">
      <label class="form-label">End Date</label>
      <input type="date" class="form-control" name="end_date" value="<?php echo htmlspecialchars($endDate); ?>">
    </div>
    <?php if ($role === 'admin'): ?>
      <div class="col-md-3">
        <label class="form-label">Branch</label>
        <select name="branch_id" class="form-select">
          <option value="">All Branches</option>
          <?php foreach($branches as $b): ?>
            <option value="<?php echo (int)$b['id']; ?>" <?php echo (!is_null($branchId) && (int)$branchId===(int)$b['id'])?'selected':''; ?>>
              <?php echo htmlspecialchars($b['name']); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    <?php endif; ?>
    <div class="col-md-2">
      <button class="btn btn-primary w-100" type="submit">Apply</button>
    </div>
  </form>

  <div class="table-responsive">
    <table id="supplierReportTable" class="table table-striped table-bordered align-middle">
      <thead>
        <tr>
          <th>Supplier</th>
          <th class="text-end">Purchases (Rs.)</th>
          <th class="text-end">Cargo Transfers (Rs.)</th>
          <th class="text-end">Net Purchases (Rs.)</th>
          <th class="text-end">Returns (Rs.)</th>
          <th class="text-end">Payments (Rs.)</th>
          <?php if ($hasSuppOpening): ?>
          <th class="text-end">Opening (Rs.)</th>
          <?php endif; ?>
          <th class="text-end">Outstanding (Rs.)</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <?php
          $cost  = (float)$r['total_cost'];
          $cargo = (float)$r['cargo_transfers'];
          $net   = (float)($r['net_purchases'] ?? max($cost - $cargo, 0));
          $ret   = (float)$r['total_returns'];
          $paid  = (float)$r['total_paid'];
          $out   = (float)($r['outstanding'] ?? max($net - $ret - $paid, 0));
        ?>
        <tr>
          <td><?php echo htmlspecialchars($r['name']); ?></td>
          <td class="text-end"><?php echo number_format($cost, 2); ?></td>
          <td class="text-end"><?php echo number_format($cargo, 2); ?></td>
          <td class="text-end fw-semibold"><?php echo number_format($net, 2); ?></td>
          <td class="text-end"><?php echo number_format($ret, 2); ?></td>
          <td class="text-end"><?php echo number_format($paid, 2); ?></td>
          <?php if ($hasSuppOpening): ?>
          <?php $openBal = (float)($r['opening_balance'] ?? 0.0); ?>
          <td class="text-end"><?php echo number_format($openBal, 2); ?></td>
          <?php endif; ?>
          <td class="text-end fw-bold"><?php echo number_format($out, 2); ?></td>
          <td>
            <a class="btn btn-sm btn-primary" href="supplier_view.php?id=<?php echo (int)$r['id']; ?>">View</a>
            <?php if ($hasSuppOpening && isset($openBal) && $openBal > 0): ?>
              <!-- Button to pay opening balance for this supplier -->
              <button type="button"
                      class="btn btn-sm btn-warning ms-1 pay-opening-btn"
                      data-supplier-id="<?php echo (int)$r['id']; ?>"
                      data-open-amount="<?php echo number_format($openBal, 2, '.', ''); ?>">
                Pay Opening
              </button>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr class="table-light fw-bold">
          <td class="text-end">TOTAL</td>
          <td class="text-end"><?php echo number_format($sum_cost, 2); ?></td>
          <td class="text-end"><?php echo number_format($sum_cargo, 2); ?></td>
          <td class="text-end"><?php echo number_format($sum_net, 2); ?></td>
          <td class="text-end"><?php echo number_format($sum_ret, 2); ?></td>
          <td class="text-end"><?php echo number_format($sum_paid, 2); ?></td>
          <?php if ($hasSuppOpening): ?>
          <td class="text-end"><?php echo number_format($sum_open, 2); ?></td>
          <?php endif; ?>
          <td class="text-end"><?php echo number_format($sum_out, 2); ?></td>
          <td></td>
        </tr>
      </tfoot>
    </table>
  </div>
    </div>

    <!-- Pay Opening Balance Modal -->
    <div class="modal fade" id="payOpeningModal" tabindex="-1" aria-labelledby="payOpeningLabel" aria-hidden="true">
      <div class="modal-dialog">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="payOpeningLabel">Pay Opening Balance</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <form id="payOpeningForm">
              <input type="hidden" name="supplier_id" id="paySupplierId">
              <div class="mb-3">
                <label class="form-label">Amount</label>
                <input type="number" step="0.01" min="0" class="form-control" name="amount" id="payOpenAmount" required>
              </div>
              <div class="mb-3">
                <label class="form-label">Method</label>
                <select class="form-select" name="method" id="payOpenMethod" required>
                  <option value="cash">Cash</option>
                  <option value="bank">Bank</option>
                  <option value="wallet">Wallet</option>
                  <option value="cheque">Cheque</option>
                </select>
              </div>
            </form>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="button" class="btn btn-primary" id="savePayOpeningBtn">Save Opening Payment</button>
          </div>
        </div>
      </div>
    </div>

  </div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script>
  $(function(){
    $('#supplierReportTable').DataTable({ pageLength: 25 });
  });

  // Show modal to pay opening balance
  $(document).on('click', '.pay-opening-btn', function(){
    const suppId = $(this).data('supplier-id');
    const openAmt = $(this).data('open-amount');
    $('#paySupplierId').val(suppId);
    $('#payOpenAmount').val(openAmt);
    $('#payOpenMethod').val('cash');
    const modal = new bootstrap.Modal(document.getElementById('payOpeningModal'));
    modal.show();
  });

  // Save opening payment via AJAX
  $('#savePayOpeningBtn').on('click', function(){
    const suppId = $('#paySupplierId').val();
    let amount = $('#payOpenAmount').val();
    const method = $('#payOpenMethod').val() || 'cash';
    if(!suppId){
      alert('Missing supplier');
      return;
    }
    amount = parseFloat(String(amount).replace(/,/g,''));
    if(!amount || isNaN(amount) || amount <= 0){
      alert('Enter a valid amount');
      return;
    }
    const payload = {
      supplier_id: suppId,
      amount: amount,
      method: method
    };
    $.post('ajax_pay_supplier_opening.php', payload, function(resp){
      if(resp.status === 'success'){
        window.location.reload();
      }else{
        alert(resp.message || 'Failed to record opening payment');
      }
    }, 'json').fail(function(xhr){
      alert('Server error:\n' + (xhr.responseText || '(no response)'));
    });
  });
</script>
</body>
</html>
