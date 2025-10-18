<?php
require_once 'includes/header.php';

// Admin, manager, and cashier can view customer reports
checkRole(['admin','manager','cashier']);

$message     = '';
$results     = [];
$totals      = ['billed'=>0,'paid'=>0,'due'=>0,'change'=>0];
$customerId  = '';
$startDate   = '';
$endDate     = '';

// Determine if the customers table has an opening_balance column.  If present we will incorporate it into summaries and detailed reports.
$hasCustOpening = false;
try {
    $colCheck = $pdo->prepare("SHOW COLUMNS FROM customers LIKE 'opening_balance'");
    $colCheck->execute();
    $hasCustOpening = (bool)$colCheck->fetch();
} catch (Throwable $e) {
    $hasCustOpening = false;
}

// Normalize the branch id for managers/cashiers.  If a manager/cashier has an
// invalid or zero branch_id, normalise it to -1 to ensure that no other
// branch's customers appear by accident.
$userBranchIdRaw = isset($user['branch_id']) ? (int)$user['branch_id'] : 0;
$userBranchId    = ($userBranchIdRaw > 0) ? $userBranchIdRaw : -1;

// Load customers for live-search.  Restrict to the current branch for non-admin users.
if ($user['role_name']==='admin') {
    $allCustomers = $pdo->query("SELECT id, name, phone FROM customers ORDER BY name")
                        ->fetchAll(PDO::FETCH_ASSOC);
} else {
    $custStmt = $pdo->prepare("SELECT id, name, phone FROM customers WHERE branch_id = ? ORDER BY name");
    $custStmt->execute([$userBranchId]);
    $allCustomers = $custStmt->fetchAll(PDO::FETCH_ASSOC);
}

// --- Load bank accounts for the current branch (or global accounts) ---
$bank_accounts = [];
try {
    if ($user['role_name']==='admin') {
        // Admin users can see all bank accounts across branches
        $stmtBA = $pdo->query("SELECT id, name, account_no FROM bank_accounts ORDER BY name");
        $bank_accounts = $stmtBA->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // Non-admin: include accounts for their branch or global (null/0) accounts
        $stmtBA = $pdo->prepare("SELECT id, name, account_no FROM bank_accounts WHERE branch_id = ? OR branch_id IS NULL OR branch_id = 0 ORDER BY name");
        $stmtBA->execute([$userBranchId]);
        $bank_accounts = $stmtBA->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    $bank_accounts = [];
}

// MODE 1: aggregated summary (no customer selected)
$customerAggregates = [];
if ($_SERVER['REQUEST_METHOD']==='GET' && (!isset($_GET['customer_id']) || $_GET['customer_id']==='')) {
    if ($user['role_name']==='admin') {
        // Admin: see all branches
        $sqlAggr = "
            SELECT c.id, c.name, c.phone, b.name AS branch_name,
                   SUM(s.total_amount) AS billed,
                   COALESCE(SUM(sp.amount),0) AS paid
            FROM customers c
            JOIN sales s ON s.customer_id = c.id
            LEFT JOIN sale_payments sp ON sp.sale_id = s.id
            JOIN branches b ON s.branch_id = b.id
            GROUP BY c.id, c.name, c.phone, b.name
            ORDER BY (SUM(s.total_amount) - COALESCE(SUM(sp.amount),0)) DESC
        ";
        $stmtAggr = $pdo->query($sqlAggr);
        $customerAggregates = $stmtAggr->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // Manager/Cashier: only their branch.  Restrict the customer list and
        // aggregate values to the manager's branch.
        // Use normalised branch id for managers/cashiers
        $mgr_branch = $userBranchId;
        $sql = "
          SELECT c.id, c.name, c.phone,
                 COALESCE((SELECT SUM(s.total_amount)
                           FROM sales s
                           WHERE s.customer_id = c.id AND s.branch_id = ?),0) AS billed,
                 COALESCE((SELECT SUM(sp.amount)
                           FROM sale_payments sp
                           JOIN sales s2 ON s2.id = sp.sale_id
                           WHERE s2.customer_id = c.id AND s2.branch_id = ?),0) AS paid
          FROM customers c
          WHERE c.branch_id = ?
          ORDER BY c.name
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$mgr_branch, $mgr_branch, $mgr_branch]);
        $customerAggregates = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Compute due + label after we have aggregates; see below for unified due calculation
        $branchName = $user['branch_name'] ?? 'My Branch';
        foreach ($customerAggregates as &$ca) {
            $ca['billed'] = (float)$ca['billed'];
            $ca['paid']   = (float)$ca['paid'];
            $ca['branch_name'] = $branchName;
        }
        unset($ca);
        // We'll compute due and sort below once opening balances are merged.
    }

    // Pull opening balances if the column exists
    $openMap = [];
    if ($hasCustOpening) {
        try {
            if ($user['role_name']==='admin') {
                $stOpen = $pdo->query("SELECT id, opening_balance FROM customers");
                while ($row = $stOpen->fetch(PDO::FETCH_ASSOC)) {
                    $openMap[(int)$row['id']] = (float)$row['opening_balance'];
                }
            } else {
                // Limit opening balances to customers of the manager's branch
                $stmtOpen = $pdo->prepare("SELECT id, opening_balance FROM customers WHERE branch_id = ?");
                $stmtOpen->execute([$userBranchId]);
                while ($row = $stmtOpen->fetch(PDO::FETCH_ASSOC)) {
                    $openMap[(int)$row['id']] = (float)$row['opening_balance'];
                }
            }
        } catch (Throwable $e) {
            $openMap = [];
        }
    }

    // Compute due for both admin and manager after retrieving opening balances
    foreach ($customerAggregates as &$ca) {
        $custId = (int)$ca['id'];
        $open   = $openMap[$custId] ?? 0.0;
        $ca['opening_balance'] = $open;
        $ca['due'] = max($ca['billed'] - $ca['paid'] + $open, 0);
    }
    unset($ca);
    // Sort by due desc if not already sorted
    usort($customerAggregates,function($a,$b){ return $b['due'] <=> $a['due']; });

    // Include customers who only have an opening balance but no sales/payments yet
    if ($hasCustOpening) {
        // Build a set of customer IDs already included in aggregates
        $existingIds = [];
        foreach ($customerAggregates as $row) {
            $existingIds[] = (int)$row['id'];
        }
        try {
            if ($user['role_name']==='admin') {
                $st = $pdo->query("SELECT id, name, phone, opening_balance FROM customers WHERE COALESCE(opening_balance,0) <> 0");
            } else {
                // Restrict to customers of the manager's branch
                $stmt = $pdo->prepare("SELECT id, name, phone, opening_balance FROM customers WHERE COALESCE(opening_balance,0) <> 0 AND branch_id = ?");
                $stmt->execute([$userBranchId]);
                $st = $stmt;
            }
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $cid = (int)$row['id'];
                if (!in_array($cid, $existingIds, true)) {
                    $openVal = (float)$row['opening_balance'];
                    // Determine branch name label
                    $branchLabel = ($user['role_name']==='admin') ? 'All Branches' : ($user['branch_name'] ?? 'My Branch');
                    $customerAggregates[] = [
                        'id' => $cid,
                        'name' => $row['name'],
                        'phone' => $row['phone'],
                        'branch_name' => $branchLabel,
                        'billed' => 0.0,
                        'paid' => 0.0,
                        'opening_balance' => $openVal,
                        'due' => max($openVal,0.0)
                    ];
                }
            }
        } catch (Throwable $e) {}
        // Re-sort after adding
        usort($customerAggregates,function($a,$b){ return $b['due'] <=> $a['due']; });
    }
}

// MODE 2: customer_id provided → detailed sales/payment report
if ($_SERVER['REQUEST_METHOD']==='GET' && isset($_GET['customer_id']) && $_GET['customer_id']!=='') {
    $customerId = (int)($_GET['customer_id'] ?? 0);
    $startDate  = trim($_GET['start_date'] ?? '');
    $endDate    = trim($_GET['end_date'] ?? '');
    if ($customerId) {
        $params = [$customerId];
        $sql = "SELECT s.id, s.sale_date, s.branch_id, s.total_amount
                FROM sales s WHERE s.customer_id = ?";
        if ($startDate!=='' && $endDate!=='') {
            $sql .= " AND DATE(s.sale_date) BETWEEN ? AND ?";
            $params[]=$startDate; $params[]=$endDate;
        }
        if ($user['role_name']==='manager') {
            $sql .= " AND s.branch_id = ?";
            $params[] = $userBranchId;
        }
        $sql .= " ORDER BY s.sale_date ASC";
        $stmt=$pdo->prepare($sql); $stmt->execute($params);
        $sales=$stmt->fetchAll();

        foreach ($sales as $s) {
            $saleId   = (int)$s['id'];
            $branchId = (int)$s['branch_id'];
            $total    = (float)$s['total_amount'];

            $payStmt=$pdo->prepare("SELECT method, amount, cheque_number, bank_name, bank_branch, deposit_date 
                                     FROM sale_payments WHERE sale_id=?");
            $payStmt->execute([$saleId]);
            $payments=$payStmt->fetchAll(PDO::FETCH_ASSOC);

            $paid=0.0; foreach($payments as $p){ $paid+=(float)$p['amount']; }
            $due=0.0; $change=0.0;
            if ($paid >= $total) { $change=$paid-$total; } else { $due=$total-$paid; }

            $daysPending=0;
            try { $saleDate=new DateTime($s['sale_date']); $now=new DateTime(); $daysPending=$saleDate->diff($now)->days; } catch(Exception $e){}

            $brStmt=$pdo->prepare("SELECT name FROM branches WHERE id=?");
            $brStmt->execute([$branchId]);
            $branchName=(string)$brStmt->fetchColumn();

            $results[]=[
              'sale_id'=>$saleId,'sale_date'=>$s['sale_date'],'branch_name'=>$branchName,
              'total'=>$total,'paid'=>$paid,'due'=>$due,'change'=>$change,
              'payments'=>$payments,'days_pending'=>$daysPending
            ];
            $totals['billed']+=$total; $totals['paid']+=$paid;
            $totals['due']+=$due; $totals['change']+=$change;
        }
        // After processing all sales, incorporate opening balance if present
        $openingBalanceForCust = 0.0;
        if ($hasCustOpening) {
            try {
                $stOpen = $pdo->prepare("SELECT opening_balance FROM customers WHERE id=?");
                $stOpen->execute([$customerId]);
                $openingBalanceForCust = (float)$stOpen->fetchColumn();
            } catch (Throwable $e) {
                $openingBalanceForCust = 0.0;
            }
            // Opening balance contributes entirely to due
            $totals['due'] += $openingBalanceForCust;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Customer Report - POS System</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
  <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
  <style>
    .search-container { position: relative; }
    .search-results { position: absolute; top: 100%; left: 0; right: 0; background: #fff; border: 1px solid #ddd; border-top: none; border-radius: 0 0 5px 5px; max-height: 300px; overflow-y: auto; z-index: 1000; box-shadow: 0 4px 10px rgba(0,0,0,.1); display: none; }
    .search-item { padding: 10px 15px; border-bottom: 1px solid #eee; cursor: pointer; display: flex; justify-content: space-between; align-items: center; }
    .search-item:hover { background: #f5f5f5; }
  </style>
</head>
<body>
<?php include 'includes/nav.php'; ?>
<div class="container">
  <h1 class="mb-4">Customer Report</h1>
  <?php if($message): ?><div class="alert alert-warning"><?=htmlspecialchars($message)?></div><?php endif; ?>

  <!-- search + filter -->
  <form method="get" class="row g-3 mb-4">
    <div class="col-md-4 search-container">
      <label class="form-label">Search Customer</label>
      <input type="text" id="customerSearch" class="form-control" placeholder="Type name or phone">
      <div class="search-results" id="customerResults"></div>
      <input type="hidden" name="customer_id" id="customer_id" value="<?=htmlspecialchars($customerId)?>">
    </div>
    <div class="col-md-3"><label class="form-label">Start Date</label><input type="date" name="start_date" class="form-control" value="<?=htmlspecialchars($startDate)?>"></div>
    <div class="col-md-3"><label class="form-label">End Date</label><input type="date" name="end_date" class="form-control" value="<?=htmlspecialchars($endDate)?>"></div>
    <div class="col-md-2 d-flex align-items-end"><button type="submit" class="btn btn-primary w-100">Show Report</button></div>
  </form>

  <?php if($customerId): ?>
    <!-- Quick ranges -->
    <div class="mb-3">
      <?php
        $today = date('Y-m-d');
        $monthStart = date('Y-m-01');
        $yearStart  = date('Y-01-01');
      ?>
      <a href="customer_report.php?customer_id=<?=$customerId?>&start_date=<?=$today?>&end_date=<?=$today?>" class="btn btn-sm btn-outline-primary me-2">Today</a>
      <a href="customer_report.php?customer_id=<?=$customerId?>&start_date=<?=$monthStart?>&end_date=<?=$today?>" class="btn btn-sm btn-outline-primary me-2">This Month</a>
      <a href="customer_report.php?customer_id=<?=$customerId?>&start_date=<?=$yearStart?>&end_date=<?=$today?>" class="btn btn-sm btn-outline-primary">This Year</a>
    </div>
  <?php endif; ?>

  <?php if(!$customerId): ?>
    <!-- Aggregated list -->
    <table id="customerListTable" class="table table-bordered table-striped">
      <thead>
        <tr>
          <th>Customer</th>
          <th>Phone</th>
          <th>Branch</th>
          <th class="text-end">Billed</th>
          <th class="text-end">Paid</th>
          <?php if ($hasCustOpening): ?>
          <th class="text-end">Opening</th>
          <?php endif; ?>
          <th class="text-end">Due</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach($customerAggregates as $cust): ?>
          <tr>
            <td><?= htmlspecialchars($cust['name']) ?></td>
            <td><?= htmlspecialchars($cust['phone']) ?></td>
            <td><?= htmlspecialchars($cust['branch_name']) ?></td>
            <td class="text-end"><?= number_format((float)$cust['billed'], 2) ?></td>
            <td class="text-end"><?= number_format((float)$cust['paid'], 2) ?></td>
            <?php if ($hasCustOpening): ?>
            <td class="text-end"><?= number_format((float)($cust['opening_balance'] ?? 0), 2) ?></td>
            <?php endif; ?>
            <td class="text-end"><?= number_format((float)$cust['due'], 2) ?></td>
            <td>
              <a href="customer_report.php?customer_id=<?= (int)$cust['id'] ?>" class="btn btn-sm btn-primary">View</a>
              <?php if ($hasCustOpening && (float)($cust['opening_balance'] ?? 0) > 0): ?>
                <!-- Button to receive payment against opening balance -->
                <button type="button"
                        class="btn btn-sm btn-success ms-1 receive-opening-btn"
                        data-customer-id="<?= (int)$cust['id'] ?>"
                        data-open-amount="<?= number_format((float)($cust['opening_balance'] ?? 0), 2, '.', '') ?>">
                  Opening Payment
                </button>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php elseif(!empty($results)): ?>
    <!-- Detailed sales -->
    <div class="mb-3">
      <h5>Total Billed: Rs. <?= number_format($totals['billed'], 2) ?></h5>
      <h5>Total Paid: Rs. <?= number_format($totals['paid'], 2) ?></h5>
      <?php if ($hasCustOpening && isset($openingBalanceForCust) && $openingBalanceForCust > 0): ?>
      <h5>
        Opening Balance: Rs. <?= number_format($openingBalanceForCust, 2) ?>
        <?php if ($openingBalanceForCust > 0): ?>
          <button type="button"
                  class="btn btn-sm btn-success ms-2 receive-opening-btn"
                  data-customer-id="<?= (int)$customerId ?>"
                  data-open-amount="<?= number_format($openingBalanceForCust, 2, '.', '') ?>">
            Receive Opening Payment
          </button>
        <?php endif; ?>
      </h5>
      <?php endif; ?>
      <h5>Total Due: Rs. <?= number_format($totals['due'], 2) ?></h5>
      <h5>Total Change: Rs. <?= number_format($totals['change'], 2) ?></h5>
    </div>
    <table id="customerReportTable" class="table table-bordered table-striped">
      <thead><tr><th>Sale ID</th><th>Date</th><th>Branch</th><th class="text-end">Billed</th><th class="text-end">Paid</th><th class="text-end">Due</th><th class="text-end">Change</th><th>Payments</th><th>Action</th></tr></thead>
      <tbody>
        <?php foreach($results as $row): ?>
          <tr class="<?=($row['due']>0 && $row['days_pending']>=20)?'table-danger':''?>">
            <td><?=$row['sale_id']?></td>
            <td><?=htmlspecialchars($row['sale_date'])?></td>
            <td><?=htmlspecialchars($row['branch_name'])?></td>
            <td class="text-end"><?=number_format($row['total'],2)?></td>
            <td class="text-end"><?=number_format($row['paid'],2)?></td>
            <td class="text-end">
              <?=number_format($row['due'],2)?>
              <?php if ($row['due']>0): ?>
                <small class="ms-1 <?=($row['days_pending']>=20 ? 'text-danger' : 'text-muted')?>">(<?=$row['days_pending']?> days)</small>
              <?php endif; ?>
            </td>
            <td class="text-end"><?=number_format($row['change'],2)?></td>
            <td>
              <?php foreach ($row['payments'] as $pm): ?>
                <div>
                  <strong><?=htmlspecialchars(ucfirst($pm['method']))?>:</strong> Rs. <?=number_format((float)$pm['amount'],2)?>
                  <?php if (($pm['method']??'')==='cheque'): ?>
                    <small>
                      (Cheque: <?=htmlspecialchars($pm['cheque_number'])?>,
                      Bank: <?=htmlspecialchars($pm['bank_name'])?>,
                      Branch: <?=htmlspecialchars($pm['bank_branch'])?>,
                      Deposit: <?=htmlspecialchars($pm['deposit_date'])?>)
                    </small>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </td>
            <td class="text-center">
              <?php if ($row['due']>0): ?>
                <button type="button"
                        class="btn btn-sm btn-success receive-payment-btn"
                        data-sale-id="<?=$row['sale_id']?>"
                        data-due="<?=number_format($row['due'],2,'.','')?>">
                  Receive Payment
                </button>
              <?php else: ?>
                <span class="text-muted">Paid</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <!-- Receive Payment Modal (single, reused) -->
    <div class="modal fade" id="receivePaymentModal" tabindex="-1" aria-labelledby="receivePaymentLabel" aria-hidden="true">
      <div class="modal-dialog">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="receivePaymentLabel">Receive Payment</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <form id="receivePaymentForm">
              <input type="hidden" name="sale_id" id="modalSaleId">
              <div class="mb-3">
                <label class="form-label">Amount</label>
                <input type="number" step="0.01" min="0" class="form-control" name="amount" id="modalAmount" required>
              </div>
              <div class="mb-3">
                <label class="form-label">Method</label>
                <select class="form-select" name="method" id="modalMethod" required>
                  <option value="cash">Cash</option>
                  <option value="bank">Bank</option>
                  <option value="cheque">Cheque</option>
                </select>
              </div>
              <!-- Bank details for sale payment; shown only when method=bank -->
              <div id="bankDetails" style="display:none;">
                <div class="mb-3">
                  <label class="form-label">Bank</label>
                  <select class="form-select" id="modalBankSelect" name="bank_name"></select>
                  <div id="bankMsg" class="text-danger small mt-1" style="display:none;">No bank accounts found</div>
                </div>
              </div>
              <div id="chequeDetails" style="display:none;">
                <div class="mb-3">
                  <label class="form-label">Cheque Number</label>
                  <input type="text" class="form-control" name="cheque_number" id="modalChequeNumber">
                </div>
                <div class="mb-3">
                  <label class="form-label">Bank Name</label>
                  <input type="text" class="form-control" name="bank_name" id="modalBankName">
                </div>
                <div class="mb-3">
                  <label class="form-label">Bank Branch</label>
                  <input type="text" class="form-control" name="bank_branch" id="modalBankBranch">
                </div>

                <div class="mb-3" style="display:none;">
                  <label class="form-label">Deposit Date</label>
                  <input type="date" class="form-control" name="deposit_date" id="modalDepositDate">
                </div>
                  <!-- ✅ NEW: Transfer Date -->
  <div class="mb-3">
    <label class="form-label">Transfer Date</label>
    <input type="date" class="form-control" name="transfer_date" id="modalTransferDate">
  </div>
              </div>
            </form>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="button" class="btn btn-primary" id="savePaymentBtn">Save Payment</button>
          </div>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <!-- Receive Opening Balance Modal (available in both aggregated and detailed views) -->
  <div class="modal fade" id="receiveOpeningModal" tabindex="-1" aria-labelledby="receiveOpeningLabel" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="receiveOpeningLabel">Receive Opening Payment</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <form id="openingPaymentForm">
            <input type="hidden" name="customer_id" id="openCustomerId">
            <div class="mb-3">
              <label class="form-label">Amount</label>
              <input type="number" step="0.01" min="0" class="form-control" name="amount" id="openAmount" required>
            </div>
            <div class="mb-3">
              <label class="form-label">Method</label>
              <select class="form-select" name="method" id="openMethod" required>
                <option value="cash">Cash</option>
                <option value="bank">Bank</option>
                <option value="wallet">Wallet</option>
                <option value="cheque">Cheque</option>
              </select>
            </div>
            <!-- Bank details for opening payment; shown only when method=bank -->
            <div id="openBankDetails" style="display:none;">
              <div class="mb-3">
                <label class="form-label">Bank</label>
                <select class="form-select" id="openBankSelect" name="bank_name"></select>
                <div id="openBankMsg" class="text-danger small mt-1" style="display:none;">No bank accounts found</div>
              </div>
            </div>

            <!-- Cheque details for opening payments; shown only when method=cheque -->
            <div id="openChequeDetails" style="display:none;">
              <div class="mb-3">
                <label class="form-label">Cheque Number</label>
                <input type="text" class="form-control" name="cheque_number" id="openChequeNumber">
              </div>
              <div class="mb-3">
                <label class="form-label">Bank Name</label>
                <input type="text" class="form-control" name="bank_name" id="openBankName">
              </div>
              <div class="mb-3">
                <label class="form-label">Bank Branch</label>
                <input type="text" class="form-control" name="bank_branch" id="openBankBranch">
              </div>
              <div class="mb-3">
                <label class="form-label">Deposit Date</label>
                <input type="date" class="form-control" name="deposit_date" id="openDepositDate">
              </div>
            </div>
          </form>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="button" class="btn btn-primary" id="saveOpeningBtn">Save Opening Payment</button>
        </div>
      </div>
    </div>
  </div>

</div>

<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script>
/* ===== Live Customer Search ===== */
const customers = <?php echo json_encode($allCustomers); ?>;
/* ===== Bank Accounts ===== */
// Provide bank accounts to JS as an array of objects with id, name and account_no.
// If no bank accounts exist for the current branch, this will be an empty array.
const bankAccounts = <?php echo json_encode($bank_accounts); ?>;
const searchInput = document.getElementById('customerSearch');
const searchResults = document.getElementById('customerResults');
const hiddenCustomerId = document.getElementById('customer_id');

function renderCustomerResults(matches) {
  if (!matches.length) {
    searchResults.innerHTML = '<div class="search-item">No customers found</div>';
    searchResults.style.display = 'block';
    return;
  }
  let html = '';
  matches.forEach(c => {
    html += `<div class="search-item" data-customer-id="${c.id}">
      <div>${c.name} <small class="text-muted ms-2">${c.phone || ''}</small></div>
    </div>`;
  });
  searchResults.innerHTML = html;
  searchResults.style.display = 'block';
  document.querySelectorAll('#customerResults .search-item').forEach(item => {
    item.addEventListener('click', () => {
      const id = item.getAttribute('data-customer-id');
      const cust = customers.find(c => c.id == id);
      hiddenCustomerId.value = id;
      searchInput.value = cust ? cust.name : '';
      searchResults.style.display = 'none';
    });
  });
}

searchInput.addEventListener('input', function() {
  const q = this.value.toLowerCase().trim();
  if (q.length === 0) {
    searchResults.style.display = 'none';
    hiddenCustomerId.value = '';
    return;
  }
  const matches = customers.filter(c =>
    (c.name && c.name.toLowerCase().includes(q)) ||
    (c.phone && String(c.phone).toLowerCase().includes(q))
  );
  renderCustomerResults(matches);
});
document.addEventListener('click', function(e) {
  if (!e.target.closest('.search-container')) searchResults.style.display = 'none';
});

/* ===== DataTables ===== */
$(function(){
  if($('#customerListTable').length){ $('#customerListTable').DataTable({order:[[5,'desc']]}); }
  if($('#customerReportTable').length){ $('#customerReportTable').DataTable(); }
  // Initialize bank selects on page load
  populateBankSelect('modalBankSelect');
  populateBankSelect('openBankSelect');
});

/* ===== Bank Select Helpers ===== */
// Populate a select element with available bank accounts.  If no accounts exist, leave empty.
function populateBankSelect(selectId) {
  const sel = document.getElementById(selectId);
  if (!sel) return;
  // Clear existing options
  sel.innerHTML = '';
  if (bankAccounts && bankAccounts.length > 0) {
    bankAccounts.forEach(b => {
      const opt = document.createElement('option');
      // Use bank name as value; show account number in parentheses if available
      opt.value = b.name;
      opt.text  = b.account_no ? `${b.name} (Acct ${b.account_no})` : b.name;
      sel.appendChild(opt);
    });
  }
}


/* ===== Receive Payment button -> modal ===== */
$(document).on('click', '.receive-payment-btn', function() {
  const saleId = $(this).data('sale-id');
  const due    = $(this).data('due');
  $('#modalSaleId').val(saleId);
  $('#modalAmount').val(due);
  $('#modalMethod').val('cash').trigger('change');
  const modal = new bootstrap.Modal(document.getElementById('receivePaymentModal'));
  modal.show();
});

/* Toggle cheque/bank fields (+ default dates) */
$('#modalMethod').on('change', function() {
  const method = $(this).val();
  if (method === 'cheque') {
    // Show cheque details, hide bank details
    $('#chequeDetails').show();
    $('#bankDetails').hide();
    // Pre-fill deposit date if empty
    // if (!$('#modalDepositDate').val()) {
    //   const today = new Date().toISOString().slice(0,10);
    //   $('#modalDepositDate').val(today);
    // }
  } else if (method === 'bank') {
    // Show bank details, hide cheque details
    $('#chequeDetails').hide();
    // Populate bank select
    populateBankSelect('modalBankSelect');
    if (bankAccounts && bankAccounts.length > 0) {
      $('#modalBankSelect').show();
      $('#bankMsg').hide();
    } else {
      // No banks available: hide select and show message
      $('#modalBankSelect').hide();
      $('#bankMsg').show();
    }
    $('#bankDetails').show();
  } else {
    // Cash or other: hide both
    $('#chequeDetails').hide();
    $('#bankDetails').hide();
  }
});

/* Save payment via AJAX (explicit payload) */
$('#savePaymentBtn').on('click', function() {
  const selectedMethod = $('#modalMethod').val();
  // Determine bank_name based on method: from select for bank, from text input for cheque
  let bankName = '';
  let bankBranch = '';
  if (selectedMethod === 'bank') {
    bankName = $('#modalBankSelect option:selected').val() || '';
  } else if (selectedMethod === 'cheque') {
    bankName = $('#modalBankName').val() || '';
    bankBranch = $('#modalBankBranch').val() || '';
  }
  const payload = {
    sale_id:       $('#modalSaleId').val(),
    amount:        $('#modalAmount').val(),
    method:        selectedMethod,
    cheque_number: $('#modalChequeNumber').val(),
    bank_name:     bankName,
    bank_branch:   bankBranch,
    deposit_date:  $('#modalDepositDate').val(),
    transfer_date: $('#modalTransferDate').val()   // ✅ NEW
  };

  if (!payload.sale_id) { alert('Missing sale id'); return; }
  const amt = parseFloat(String(payload.amount).replace(/,/g,''));
  if (!payload.amount || isNaN(amt) || amt <= 0) { alert('Enter a valid amount'); return; }
  if (!payload.method) payload.method = 'cash';

  $.post('ajax_receive_payment.php', payload, function(resp) {
    if (resp.status === 'success') {
      window.location.reload();
    } else {
      alert(resp.message || 'Failed to record payment');
    }
  }, 'json').fail(function(xhr) {
    alert('Server error:\n' + (xhr.responseText || '(no response)'));
  });
});

/* ===== Receive Opening Payment button -> modal ===== */
$(document).on('click', '.receive-opening-btn', function() {
  const custId = $(this).data('customer-id');
  const openAmt = $(this).data('open-amount');
  $('#openCustomerId').val(custId);
  $('#openAmount').val(openAmt);
  $('#openMethod').val('cash').trigger('change');
  const modal = new bootstrap.Modal(document.getElementById('receiveOpeningModal'));
  modal.show();
});

// Show or hide cheque/bank detail fields for opening payment based on method
$('#openMethod').on('change', function() {
  const method = $(this).val();
  if (method === 'cheque') {
    $('#openChequeDetails').show();
    $('#openBankDetails').hide();
    // Default deposit date for opening cheques
    if (!$('#openDepositDate').val()) {
      const today = new Date().toISOString().slice(0,10);
      $('#openDepositDate').val(today);
    }
  } else if (method === 'bank') {
    $('#openChequeDetails').hide();
    // Populate bank select for opening
    populateBankSelect('openBankSelect');
    if (bankAccounts && bankAccounts.length > 0) {
      $('#openBankSelect').show();
      $('#openBankMsg').hide();
    } else {
      $('#openBankSelect').hide();
      $('#openBankMsg').show();
    }
    $('#openBankDetails').show();
  } else {
    // Cash/wallet: hide both
    $('#openChequeDetails').hide();
    $('#openBankDetails').hide();
  }
});

/* ===== Save opening payment via AJAX ===== */
$('#saveOpeningBtn').on('click', function() {
  const custId = $('#openCustomerId').val();
  let amount = $('#openAmount').val();
  const method = $('#openMethod').val() || 'cash';
  if (!custId) {
    alert('Missing customer');
    return;
  }
  // Normalize amount string (remove commas) and parse float
  amount = parseFloat(String(amount).replace(/,/g,''));
  if (!amount || isNaN(amount) || amount <= 0) {
    alert('Enter a valid amount');
    return;
  }
  // Determine bank name/branch based on selected method for opening payment
  let openBankName = '';
  let openBankBranch = '';
  if (method === 'bank') {
    openBankName = $('#openBankSelect option:selected').val() || '';
  } else if (method === 'cheque') {
    openBankName = $('#openBankName').val() || '';
    openBankBranch = $('#openBankBranch').val() || '';
  }
  const payload = {
    customer_id: custId,
    amount: amount,
    method: method,
    cheque_number: $('#openChequeNumber').val(),
    bank_name:     openBankName,
    bank_branch:   openBankBranch,
    deposit_date:  $('#openDepositDate').val()
  };
  $.post('ajax_receive_opening_payment.php', payload, function(resp) {
    if (resp.status === 'success') {
      window.location.reload();
    } else {
      alert(resp.message || 'Failed to record opening payment');
    }
  }, 'json').fail(function(xhr) {
    alert('Server error:\n' + (xhr.responseText || '(no response)'));
  });
});
</script>
</body>
</html>
