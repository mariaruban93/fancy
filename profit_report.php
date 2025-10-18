<?php
require_once 'includes/header.php';
// Only admins and managers can access profit report
checkRole(['admin', 'manager']);

// Range handling: today, yesterday, month, year, custom
$range = $_GET['range'] ?? 'today';
$start = $_GET['start_date'] ?? '';
$end   = $_GET['end_date'] ?? '';
$branchFilter = $_GET['branch_id'] ?? '';

// Determine date range based on selection
function get_range_dates($range) {
    $tz = new DateTimeZone(date_default_timezone_get());
    $today = new DateTime('today', $tz);
    switch ($range) {
        case 'today':
            return [$today->format('Y-m-d'), $today->format('Y-m-d')];
        case 'yesterday':
            $y = (clone $today)->modify('-1 day');
            return [$y->format('Y-m-d'), $y->format('Y-m-d')];
        case 'month':
            $start = (clone $today)->modify('first day of this month');
            $end   = (clone $today)->modify('last day of this month');
            return [$start->format('Y-m-d'), $end->format('Y-m-d')];
        case 'year':
            $start = (clone $today)->modify('first day of January');
            $end   = (clone $today)->modify('last day of December');
            return [$start->format('Y-m-d'), $end->format('Y-m-d')];
        default:
            return [null, null];
    }
}

// If a preset range is chosen (not custom), override start and end
if ($range !== 'custom') {
    [$start, $end] = get_range_dates($range);
}

// Fetch list of branches for admin filter
$branchOptions = [];
if ($user['role_name'] === 'admin') {
    $branchOptions = $pdo->query("SELECT id, name FROM branches ORDER BY name")->fetchAll();
}

$message = '';
$records = [];
$totals = ['revenue'=>0,'discount'=>0,'cost'=>0,'profit'=>0];

// Only query when we have a valid start and end date
if ($start && $end) {
    // Build query to fetch sale item details and cost
    $sql = "SELECT s.id AS sale_id, s.sale_date, b.name AS branch_name, p.name AS product_name, si.quantity, si.selling_price, si.discount_type, si.discount_value, sb.cost_price
            FROM sales s
            JOIN sale_items si ON s.id = si.sale_id
            JOIN stock_batches sb ON si.batch_id = sb.id
            JOIN products p ON si.product_id = p.id
            JOIN branches b ON s.branch_id = b.id
            WHERE DATE(s.sale_date) BETWEEN ? AND ?";
    $params = [$start, $end];
    // Branch scope: manager locked to own branch; admin can choose
    if ($user['role_name'] === 'manager') {
        $sql .= " AND s.branch_id = ?";
        $params[] = $user['branch_id'];
    } elseif ($user['role_name'] === 'admin' && $branchFilter !== '' && $branchFilter !== '0') {
        $sql .= " AND s.branch_id = ?";
        $params[] = (int)$branchFilter;
    }
    $sql .= " ORDER BY s.sale_date ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $records = $stmt->fetchAll();
    foreach ($records as $rec) {
        $revenue = (float)$rec['selling_price'] * (int)$rec['quantity'];
        // discount
        $disc = 0;
        if ($rec['discount_type'] && (float)$rec['discount_value'] > 0) {
            if ($rec['discount_type'] === 'percentage') {
                // Percentage discount on the revenue
                $disc = ($revenue * (float)$rec['discount_value']) / 100.0;
            } else {
                // Fixed discount is stored per unit; multiply by quantity
                $perUnit = (float)$rec['discount_value'];
                $disc = $perUnit * (int)$rec['quantity'];
                if ($disc > $revenue) {
                    $disc = $revenue;
                }
            }
        }
        $cost = (float)$rec['cost_price'] * (int)$rec['quantity'];
        $profit = $revenue - $disc - $cost;
        $totals['revenue'] += $revenue;
        $totals['discount'] += $disc;
        $totals['cost'] += $cost;
        $totals['profit'] += $profit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profit Report - POS System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- DataTables CSS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
</head>
<body>
<?php include 'includes/nav.php'; ?>
<div class="container">
    <h1 class="mb-4">Profit Report</h1>
    <?php if ($message): ?>
        <div class="alert alert-warning"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>
    <form method="get" class="row g-3 mb-4 align-items-end" id="rangeForm">
        <div class="col-md-3">
            <label class="form-label">Range</label>
            <select name="range" class="form-select" onchange="toggleCustom(this.value)">
                <option value="today" <?php echo ($range==='today'?'selected':''); ?>>Today</option>
                <option value="yesterday" <?php echo ($range==='yesterday'?'selected':''); ?>>Yesterday</option>
                <option value="month" <?php echo ($range==='month'?'selected':''); ?>>This Month</option>
                <option value="year" <?php echo ($range==='year'?'selected':''); ?>>This Year</option>
                <option value="custom" <?php echo ($range==='custom'?'selected':''); ?>>Custom</option>
            </select>
        </div>
        <div class="col-md-3" id="startDiv" style="display:<?php echo ($range==='custom'?'block':'none'); ?>;">
            <label class="form-label">Start Date</label>
            <input type="date" name="start_date" class="form-control" value="<?php echo htmlspecialchars($start); ?>">
        </div>
        <div class="col-md-3" id="endDiv" style="display:<?php echo ($range==='custom'?'block':'none'); ?>;">
            <label class="form-label">End Date</label>
            <input type="date" name="end_date" class="form-control" value="<?php echo htmlspecialchars($end); ?>">
        </div>
        <?php if ($user['role_name'] === 'admin'): ?>
        <div class="col-md-3">
            <label class="form-label">Branch</label>
            <select name="branch_id" class="form-select">
                <option value="0" <?php echo ($branchFilter==='0' || $branchFilter===''?'selected':''); ?>>All Branches</option>
                <?php foreach ($branchOptions as $br): ?>
                    <option value="<?php echo (int)$br['id']; ?>" <?php echo ($branchFilter==(string)$br['id'] ? 'selected' : ''); ?>><?php echo htmlspecialchars($br['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <div class="col-md-2">
            <button type="submit" class="btn btn-primary w-100">Apply</button>
        </div>
    </form>
    <?php if (!empty($records)): ?>
        <div class="mb-3">
            <h5>Total Revenue: Rs. <?php echo number_format($totals['revenue'], 2); ?></h5>
            <h5>Total Discount: Rs. <?php echo number_format($totals['discount'], 2); ?></h5>
            <h5>Total Cost: Rs. <?php echo number_format($totals['cost'], 2); ?></h5>
            <h4>Total Profit: Rs. <?php echo number_format($totals['profit'], 2); ?></h4>
        </div>
        <table id="profitTable" class="table table-bordered table-striped">
            <thead>
                <tr>
                    <th>Sale ID</th>
                    <th>Date</th>
                    <th>Branch</th>
                    <th>Product</th>
                    <th class="text-center">Qty</th>
                    <th class="text-end">Price</th>
                    <th class="text-end">Cost</th>
                    <th class="text-end">Revenue</th>
                    <th class="text-end">Discount</th>
                    <th class="text-end">Profit</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($records as $rec): ?>
                    <?php
                        $revenue = (float)$rec['selling_price'] * (int)$rec['quantity'];
                        $disc = 0;
                        if ($rec['discount_type'] && (float)$rec['discount_value'] > 0) {
                            if ($rec['discount_type'] === 'percentage') {
                                // Percentage discount on the revenue
                                $disc = ($revenue * (float)$rec['discount_value']) / 100.0;
                            } else {
                                // Fixed discount values are per unit; multiply by quantity
                                $perUnit = (float)$rec['discount_value'];
                                $disc = $perUnit * (int)$rec['quantity'];
                                if ($disc > $revenue) {
                                    $disc = $revenue;
                                }
                            }
                        }
                        $cost = (float)$rec['cost_price'] * (int)$rec['quantity'];
                        $profit = $revenue - $disc - $cost;
                    ?>
                    <tr>
                        <td><?php echo $rec['sale_id']; ?></td>
                        <td><?php echo htmlspecialchars($rec['sale_date']); ?></td>
                        <td><?php echo htmlspecialchars($rec['branch_name']); ?></td>
                        <td><?php echo htmlspecialchars($rec['product_name']); ?></td>
                        <td class="text-center"><?php echo $rec['quantity']; ?></td>
                        <td class="text-end"><?php echo number_format($rec['selling_price'], 2); ?></td>
                        <td class="text-end"><?php echo number_format($rec['cost_price'], 2); ?></td>
                        <td class="text-end"><?php echo number_format($revenue, 2); ?></td>
                        <td class="text-end"><?php echo number_format($disc, 2); ?></td>
                        <td class="text-end"><?php echo number_format($profit, 2); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<!-- jQuery and DataTables JS -->
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script>
$(document).ready(function() {
  $('#profitTable').DataTable();
});

function toggleCustom(val) {
  const show = (val === 'custom');
  document.getElementById('startDiv').style.display = show ? 'block' : 'none';
  document.getElementById('endDiv').style.display = show ? 'block' : 'none';
}
</script>
</body>
</html>