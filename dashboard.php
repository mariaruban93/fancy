<?php
require_once 'includes/header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Dashboard - POS System</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet">
  <style>
    .kpi .card {min-height: 110px;}
    .mini-branch{font-size:.9rem}
    .metric-card .stat {font-size:1.1rem; font-weight:600;}
    .metric-card small {font-size:0.75rem;}
  </style>
</head>
<body>
<?php include 'includes/nav.php'; ?>
<div class="container-fluid py-3">

  <div class="d-flex align-items-center mb-3">
    <h1 class="h3 mb-0">Dashboard</h1>
    <div class="ms-auto" style="max-width:320px">
      <?php
      // Admin can choose branch; others are locked to their own
      $selectedBranch = null;
      if ($user['role_name'] === 'admin') {
          // list branches
          $branches = $pdo->query("SELECT id, name FROM branches ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
          if (isset($_GET['branch_id']) && $_GET['branch_id'] !== '' && ctype_digit($_GET['branch_id'])) {
              $selectedBranch = (int)$_GET['branch_id'];
          }
          echo '<form method="get" class="d-flex gap-2">
                  <select name="branch_id" class="form-select">
                    <option value="">All branches</option>';
          foreach ($branches as $b) {
              $sel = ($selectedBranch === (int)$b['id']) ? 'selected' : '';
              echo '<option value="'.$b['id'].'" '.$sel.'>'.htmlspecialchars($b['name']).'</option>';
          }
          echo   '</select>
                  <button class="btn btn-primary">Filter</button>
                </form>';
      } else {
          $selectedBranch = (int)$user['branch_id'];
      }

      // WHERE clause helper
      $where = '';
      $params = [];
      if (!empty($selectedBranch)) { $where = ' WHERE sb.branch_id = ? '; $params[] = $selectedBranch; }

      // Totals
      // total distinct products in scope (with or without stock)
      if (!empty($selectedBranch)) {
          $totalProducts = $pdo->prepare("SELECT COUNT(DISTINCT p.id)
              FROM products p
              LEFT JOIN stock_batches sb ON sb.product_id=p.id AND sb.branch_id=?
          ");
          $totalProducts->execute([$selectedBranch]);
          $totalProducts = (int)$totalProducts->fetchColumn();
      } else {
          $totalProducts = (int)$pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
      }

      // total stock qty and value (cost-based)
      $sqlTotals = "SELECT COALESCE(SUM(sb.quantity),0) AS qty,
                           COALESCE(SUM(sb.quantity*sb.cost_price),0) AS value
                    FROM stock_batches sb" . $where;
      $stmt = $pdo->prepare($sqlTotals);
      $stmt->execute($params);
      [$totalQty, $totalValue] = $stmt->fetch(PDO::FETCH_NUM);

      // total sales (scope)
      if (!empty($selectedBranch)) {
          $salesStmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE branch_id=?");
          $salesStmt->execute([$selectedBranch]);
      } else {
          $salesStmt = $pdo->query("SELECT COALESCE(SUM(total_amount),0) FROM sales");
      }
      $totalSales = (float)$salesStmt->fetchColumn();

      // total branches (for admin card)
      $totalBranches = (int)$pdo->query("SELECT COUNT(*) FROM branches")->fetchColumn();

      // ------------------------------------------------------------------
      // Additional metrics: Sales, Sales Returns, Purchases, Expenses
      // Compute totals for today, current month and current year for the
      // selected scope (branch and/or user).
      // Determine scope for cashier: only their own sales/returns; manager: branch; admin: branch filter
      $todayDate = date('Y-m-d');
      $firstDayOfMonth = date('Y-m-01');
      $firstDayOfYear = date('Y-01-01');

      // Build conditions and parameters based on role
      $cond = '';
      $paramsSales = [];
      if ($user['role_name'] === 'cashier') {
          // only this cashier's sales
          $cond = ' AND sold_by = ?';
          $paramsSales[] = $user['id'];
      }
      if (!empty($selectedBranch)) {
          $cond .= ' AND branch_id = ?';
          $paramsSales[] = $selectedBranch;
      }

      // Helper function to fetch sum from sales table
      $sumSales = function($startDate) use ($pdo, $cond, $paramsSales) {
          $sql = "SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE DATE(sale_date) >= ?" . $cond;
          $st = $pdo->prepare($sql);
          $st->execute(array_merge([$startDate], $paramsSales));
          return (float)$st->fetchColumn();
      };
      $salesToday  = $sumSales($todayDate);
      $salesMonth  = $sumSales($firstDayOfMonth);
      $salesYear   = $sumSales($firstDayOfYear);

      // Sales returns
      $condRet = '';
      $paramsRet = [];
      if ($user['role_name'] === 'cashier') {
          $condRet = ' AND processed_by = ?';
          $paramsRet[] = $user['id'];
      }
      if (!empty($selectedBranch)) {
          $condRet .= ' AND branch_id = ?';
          $paramsRet[] = $selectedBranch;
      }
      $sumReturns = function($startDate) use ($pdo, $condRet, $paramsRet) {
          $sql = "SELECT COALESCE(SUM(total_refund),0) FROM sale_returns WHERE DATE(return_date) >= ?" . $condRet;
          $st = $pdo->prepare($sql);
          $st->execute(array_merge([$startDate], $paramsRet));
          return (float)$st->fetchColumn();
      };
      $returnsToday = $sumReturns($todayDate);
      $returnsMonth = $sumReturns($firstDayOfMonth);
      $returnsYear  = $sumReturns($firstDayOfYear);

      // Pre-compute date ranges for quick links used by metric cards.  These
      // variables will be used to build report URLs.  For month and year
      // ranges we determine the last day of the period.  The branch filter is
      // appended to each URL if a specific branch is selected by the user.
      $todayStart = $todayDate;
      $todayEnd   = $todayDate;
      $monthStart = $firstDayOfMonth;
      $monthEnd   = date('Y-m-t', strtotime($todayDate));
      $yearStart  = $firstDayOfYear;
      $yearEnd    = date('Y-12-31', strtotime($todayDate));
      $branchQuery = '';
      if (!empty($selectedBranch)) {
          $branchQuery = '&branch_id=' . urlencode($selectedBranch);
      }

      // Purchases: sum of quantity * cost price per purchase items
      $condPur = '';
      $paramsPur = [];
      if (!empty($selectedBranch)) {
          $condPur .= ' AND pu.branch_id = ?';
          $paramsPur[] = $selectedBranch;
      }
      // manager/cashier: restrict by branch automatically by $selectedBranch
      $sumPurchases = function($startDate) use ($pdo, $condPur, $paramsPur) {
          $sql = "SELECT COALESCE(SUM(pi.quantity * pi.cost_price),0)
                FROM purchases pu
                JOIN purchase_items pi ON pi.purchase_id = pu.id
                WHERE DATE(pu.purchase_date) >= ?" . $condPur;
          $st = $pdo->prepare($sql);
          $st->execute(array_merge([$startDate], $paramsPur));
          return (float)$st->fetchColumn();
      };
      $purchasesToday = $sumPurchases($todayDate);
      $purchasesMonth = $sumPurchases($firstDayOfMonth);
      $purchasesYear  = $sumPurchases($firstDayOfYear);

      // Expenses
      $condExp = '';
      $paramsExp = [];
      if (!empty($selectedBranch)) {
          $condExp .= ' AND branch_id = ?';
          $paramsExp[] = $selectedBranch;
      }
      if ($user['role_name'] === 'cashier') {
          // cashiers typically don't record expenses; treat as none
          $expensesToday = $expensesMonth = $expensesYear = 0.0;
      } else {
          $sumExpenses = function($startDate) use ($pdo, $condExp, $paramsExp) {
              $sql = "SELECT COALESCE(SUM(amount),0) FROM expenses WHERE DATE(expense_date) >= ?" . $condExp;
              $st = $pdo->prepare($sql);
              $st->execute(array_merge([$startDate], $paramsExp));
              return (float)$st->fetchColumn();
          };
          $expensesToday = $sumExpenses($todayDate);
          $expensesMonth = $sumExpenses($firstDayOfMonth);
          $expensesYear  = $sumExpenses($firstDayOfYear);
      }

      // ------------------------------------------------------------------
      // Prepare data for chart: show last 7 days of sales, returns, purchases, expenses
      $chartLabels = [];
      $chartSales = [];
      $chartReturns = [];
      $chartPurchases = [];
      $chartExpenses = [];
      for ($i = 6; $i >= 0; $i--) {
          $date = date('Y-m-d', strtotime('-' . $i . ' days'));
          $chartLabels[] = $date;
          // Sales
          $sql = "SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE DATE(sale_date) = ?" . $cond;
          $st = $pdo->prepare($sql);
          $st->execute(array_merge([$date], $paramsSales));
          $chartSales[] = (float)$st->fetchColumn();
          // Returns
          $sqlr = "SELECT COALESCE(SUM(total_refund),0) FROM sale_returns WHERE DATE(return_date) = ?" . $condRet;
          $str = $pdo->prepare($sqlr);
          $str->execute(array_merge([$date], $paramsRet));
          $chartReturns[] = (float)$str->fetchColumn();
          // Purchases
          $sqlp = "SELECT COALESCE(SUM(pi.quantity * pi.cost_price),0)
                   FROM purchases pu JOIN purchase_items pi ON pi.purchase_id = pu.id
                   WHERE DATE(pu.purchase_date) = ?" . $condPur;
          $stp = $pdo->prepare($sqlp);
          $stp->execute(array_merge([$date], $paramsPur));
          $chartPurchases[] = (float)$stp->fetchColumn();
          // Expenses
          if ($user['role_name'] === 'cashier') {
              $chartExpenses[] = 0.0;
          } else {
              $sqle = "SELECT COALESCE(SUM(amount),0) FROM expenses WHERE DATE(expense_date) = ?" . $condExp;
              $ste = $pdo->prepare($sqle);
              $ste->execute(array_merge([$date], $paramsExp));
              $chartExpenses[] = (float)$ste->fetchColumn();
          }
      }

      // Recent activity logs (last 5 entries)
      $activityLogs = [];
      try {
          $sql = "SELECT al.description, al.created_at, br.name as branch_name
                  FROM activity_logs al
                  LEFT JOIN branches br ON br.id = al.branch_id
                  WHERE 1";
          $logParams = [];
          if (!empty($selectedBranch)) {
              $sql .= ' AND al.branch_id = ?';
              $logParams[] = $selectedBranch;
          }
          $sql .= " ORDER BY al.created_at DESC LIMIT 5";
          $st = $pdo->prepare($sql);
          $st->execute($logParams);
          $activityLogs = $st->fetchAll(PDO::FETCH_ASSOC);
      } catch (Throwable $ex) {
          $activityLogs = [];
      }
      ?>
    </div>
  </div>

  <!-- KPI CARDS: Inventory Overview -->
  <div class="row g-3 kpi">
    <div class="col-lg-3 col-sm-6">
      <div class="card text-white bg-info">
        <div class="card-body">
          <div class="card-title fw-bold"><i class="bi bi-boxes me-1"></i>Products</div>
          <div class="display-6"><?php echo number_format($totalProducts); ?></div>
          <small class="opacity-75"><?php echo $selectedBranch ? 'In this branch' : 'All products'; ?></small>
        </div>
      </div>
    </div>
    <div class="col-lg-3 col-sm-6">
      <div class="card text-white bg-success">
        <div class="card-body">
          <div class="card-title fw-bold"><i class="bi bi-stack me-1"></i>Total Stock (Qty)</div>
          <div class="display-6"><?php echo number_format($totalQty); ?></div>
          <small class="opacity-75"><?php echo $selectedBranch ? 'Current branch' : 'All branches'; ?></small>
        </div>
      </div>
    </div>
    <div class="col-lg-3 col-sm-6">
      <div class="card text-white bg-primary">
        <div class="card-body">
          <div class="card-title fw-bold"><i class="bi bi-currency-dollar me-1"></i>Stock Value (Cost)</div>
          <div class="display-6">LKR <?php echo number_format($totalValue, 2); ?></div>
          <small class="opacity-75">Sum of qty × cost</small>
        </div>
      </div>
    </div>
    <div class="col-lg-3 col-sm-6">
      <div class="card text-white bg-warning">
        <div class="card-body">
          <div class="card-title fw-bold"><i class="bi bi-cash-coin me-1"></i>Total Sales</div>
          <div class="display-6">LKR <?php echo number_format($totalSales, 2); ?></div>
          <small class="opacity-75"><?php echo $selectedBranch ? 'Branch' : 'All branches'; ?></small>
        </div>
      </div>
    </div>
  </div>

  <!-- Metrics: Sales, Returns, Purchases, Expenses (Today / Month / Year) -->
  <div class="row g-3 mt-4">
    <div class="col-lg-3 col-sm-6">
      <div class="card border metric-card">
        <div class="card-body">
          <div class="card-title fw-bold"><i class="bi bi-cart-plus me-1"></i>Sales</div>
          <div class="stat">
            <a href="sales_list.php?start_date=<?php echo $todayStart; ?>&end_date=<?php echo $todayEnd; ?><?php echo $branchQuery; ?>" class="text-decoration-none">
              Today: LKR <?php echo number_format($salesToday,2); ?>
            </a>
          </div>
          <div class="stat">
            <a href="sales_list.php?start_date=<?php echo $monthStart; ?>&end_date=<?php echo $monthEnd; ?><?php echo $branchQuery; ?>" class="text-decoration-none">
              Month: LKR <?php echo number_format($salesMonth,2); ?>
            </a>
          </div>
          <div class="stat">
            <a href="sales_list.php?start_date=<?php echo $yearStart; ?>&end_date=<?php echo $yearEnd; ?><?php echo $branchQuery; ?>" class="text-decoration-none">
              Year: LKR <?php echo number_format($salesYear,2); ?>
            </a>
          </div>
        </div>
      </div>
    </div>

    <!-- FIXED: link Sales Returns to a returns list page with date range -->
    <div class="col-lg-3 col-sm-6">
      <div class="card border metric-card">
        <div class="card-body">
          <div class="card-title fw-bold"><i class="bi bi-arrow-return-left me-1"></i>Sales Returns</div>
          <div class="stat">
            <a href="sale_returns_list.php?start_date=<?php echo $todayStart; ?>&end_date=<?php echo $todayEnd; ?><?php echo $branchQuery; ?>" class="text-decoration-none">
              Today: LKR <?php echo number_format($returnsToday,2); ?>
            </a>
          </div>
          <div class="stat">
            <a href="sale_returns_list.php?start_date=<?php echo $monthStart; ?>&end_date=<?php echo $monthEnd; ?><?php echo $branchQuery; ?>" class="text-decoration-none">
              Month: LKR <?php echo number_format($returnsMonth,2); ?>
            </a>
          </div>
          <div class="stat">
            <a href="sale_returns_list.php?start_date=<?php echo $yearStart; ?>&end_date=<?php echo $yearEnd; ?><?php echo $branchQuery; ?>" class="text-decoration-none">
              Year: LKR <?php echo number_format($returnsYear,2); ?>
            </a>
          </div>
        </div>
      </div>
    </div>

    <div class="col-lg-3 col-sm-6">
      <div class="card border metric-card">
        <div class="card-body">
          <div class="card-title fw-bold"><i class="bi bi-basket-fill me-1"></i>Purchases</div>
          <div class="stat">
            <a href="purchase_list.php?start_date=<?php echo $todayStart; ?>&end_date=<?php echo $todayEnd; ?><?php echo $branchQuery; ?>" class="text-decoration-none">
              Today: LKR <?php echo number_format($purchasesToday,2); ?>
            </a>
          </div>
          <div class="stat">
            <a href="purchase_list.php?start_date=<?php echo $monthStart; ?>&end_date=<?php echo $monthEnd; ?><?php echo $branchQuery; ?>" class="text-decoration-none">
              Month: LKR <?php echo number_format($purchasesMonth,2); ?>
            </a>
          </div>
          <div class="stat">
            <a href="purchase_list.php?start_date=<?php echo $yearStart; ?>&end_date=<?php echo $yearEnd; ?><?php echo $branchQuery; ?>" class="text-decoration-none">
              Year: LKR <?php echo number_format($purchasesYear,2); ?>
            </a>
          </div>
        </div>
      </div>
    </div>

    <div class="col-lg-3 col-sm-6">
      <div class="card border metric-card">
        <div class="card-body">
          <div class="card-title fw-bold"><i class="bi bi-wallet2 me-1"></i>Expenses</div>
          <div class="stat">
            <a href="expenses.php?start_date=<?php echo $todayStart; ?>&end_date=<?php echo $todayEnd; ?><?php echo $branchQuery; ?>" class="text-decoration-none">
              Today: LKR <?php echo number_format($expensesToday,2); ?>
            </a>
          </div>
          <div class="stat">
            <a href="expenses.php?start_date=<?php echo $monthStart; ?>&end_date=<?php echo $monthEnd; ?><?php echo $branchQuery; ?>" class="text-decoration-none">
              Month: LKR <?php echo number_format($expensesMonth,2); ?>
            </a>
          </div>
          <div class="stat">
            <a href="expenses.php?start_date=<?php echo $yearStart; ?>&end_date=<?php echo $yearEnd; ?><?php echo $branchQuery; ?>" class="text-decoration-none">
              Year: LKR <?php echo number_format($expensesYear,2); ?>
            </a>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Chart and Activity Section -->
  <div class="row mt-4">
    <div class="col-lg-8 mb-4">
      <div class="card">
        <div class="card-header">
          <strong>Weekly Overview</strong>
        </div>
        <div class="card-body">
          <canvas id="kpiChart" height="200"></canvas>
        </div>
      </div>
    </div>
    <div class="col-lg-4 mb-4">
      <div class="card h-100">
        <div class="card-header">
          <strong>Recent Activities</strong>
        </div>
        <div class="card-body">
          <?php if (empty($activityLogs)): ?>
            <p class="text-muted">No recent activities.</p>
          <?php else: ?>
            <ul class="list-unstyled mb-0">
              <?php foreach ($activityLogs as $log): ?>
                <li class="mb-2">
                  <small class="text-muted"><?php echo htmlspecialchars($log['created_at']); ?><?php if ($log['branch_name']): ?> (<?php echo htmlspecialchars($log['branch_name']); ?>)<?php endif; ?></small><br>
                  <?php echo htmlspecialchars($log['description']); ?>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <?php if ($user['role_name'] === 'admin' && empty($selectedBranch)): ?>
    <!-- mini by-branch snapshot when viewing ALL -->
    <div class="row g-2 mt-2">
      <?php
      $mini = $pdo->query("
        SELECT b.id, b.name,
               COALESCE(SUM(sb.quantity),0) AS qty,
               COALESCE(SUM(sb.quantity*sb.cost_price),0) AS val
        FROM branches b
        LEFT JOIN stock_batches sb ON sb.branch_id=b.id
        GROUP BY b.id, b.name
        ORDER BY b.name
      ")->fetchAll(PDO::FETCH_ASSOC);
      foreach ($mini as $m): ?>
      <div class="col-sm-6 col-lg-3">
        <div class="card border">
          <div class="card-body">
            <div class="mini-branch fw-semibold mb-1"><?php echo htmlspecialchars($m['name']); ?></div>
            <div class="d-flex justify-content-between">
              <span class="text-muted">Qty</span>
              <span class="fw-bold"><?php echo number_format($m['qty']); ?></span>
            </div>
            <div class="d-flex justify-content-between">
              <span class="text-muted">Value</span>
              <span class="fw-bold">LKR <?php echo number_format($m['val'],2); ?></span>
            </div>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<!-- Chart.js for visualising weekly metrics -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
$(function(){
  // Only init DataTables if #stockTable exists on this page
  if ($('#stockTable').length) {
    $('#stockTable').DataTable({
      pageLength: 25,
      order: [[0, 'asc'], [1, 'asc']],
      columnDefs: [{ targets: [6,7,8,9], className: 'text-end' }]
    });
  }

  // Render the KPI chart
  const ctx = document.getElementById('kpiChart');
  if (ctx) {
    const chart = new Chart(ctx, {
      type: 'line',
      data: {
        labels: <?php echo json_encode($chartLabels); ?>,
        datasets: [
          {
            label: 'Sales',
            data: <?php echo json_encode($chartSales); ?>,
            borderColor: 'rgba(13, 110, 253, 0.9)',
            backgroundColor: 'rgba(13, 110, 253, 0.2)',
            tension: 0.3
          },
          {
            label: 'Returns',
            data: <?php echo json_encode($chartReturns); ?>,
            borderColor: 'rgba(220, 53, 69, 0.9)',
            backgroundColor: 'rgba(220, 53, 69, 0.2)',
            tension: 0.3
          },
          {
            label: 'Purchases',
            data: <?php echo json_encode($chartPurchases); ?>,
            borderColor: 'rgba(25, 135, 84, 0.9)',
            backgroundColor: 'rgba(25, 135, 84, 0.2)',
            tension: 0.3
          },
          {
            label: 'Expenses',
            data: <?php echo json_encode($chartExpenses); ?>,
            borderColor: 'rgba(255, 193, 7, 0.9)',
            backgroundColor: 'rgba(255, 193, 7, 0.2)',
            tension: 0.3
          }
        ]
      },
      options: {
        scales: {
          y: {
            beginAtZero: true,
            ticks: {
              callback: function(value) {
                return 'Rs. ' + value.toLocaleString();
              }
            }
          },
          x: {
            ticks: {
              callback: function(value, index) {
                // Format label to show month-day
                const label = this.getLabelForValue(index);
                return label.substring(5); // MM-DD
              }
            }
          }
        },
        plugins: {
          legend: { position: 'top' },
          tooltip: {
            callbacks: {
              label: function(ctx) {
                return ctx.dataset.label + ': Rs. ' + Number(ctx.raw).toLocaleString();
              }
            }
          }
        },
        maintainAspectRatio: false
      }
    });
  }
});
</script>
</body>
</html>
