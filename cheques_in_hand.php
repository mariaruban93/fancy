<?php
/**
 * cheques_in_hand.php
 * Show undeposited cheque payments. Display transfer_date (if present) before created_at.
 */

require_once 'includes/header.php';
checkRole(['admin','manager']);

$is_admin = ($user['role_name'] === 'admin');

function table_exists(PDO $pdo, string $table): bool {
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE ".$pdo->quote($table));
        return (bool)($stmt && $stmt->fetchColumn());
    } catch (Throwable $e) {
        return false;
    }
}

function col_exists(PDO $pdo, string $table, string $col): bool {
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `$table` LIKE ".$pdo->quote($col));
        return (bool)($stmt && $stmt->fetch(PDO::FETCH_ASSOC));
    } catch (Throwable $e) {
        return false;
    }
}

function first_available_col(PDO $pdo, string $table, array $candidates): ?string {
    foreach ($candidates as $c) {
        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM `$table` LIKE ".$pdo->quote($c));
            if ($stmt && $stmt->fetch(PDO::FETCH_ASSOC)) {
                return $c;
            }
        } catch (Throwable $e) {}
    }
    return null;
}
$filter_branch_id = 0;
if ($is_admin) {
    if (isset($_GET['branch_id']) && ctype_digit($_GET['branch_id'])) {
        $filter_branch_id = (int)$_GET['branch_id'];
    }
} else {
    $filter_branch_id = (int)($user['branch_id'] ?? 0);
}

$branches = [];
if ($is_admin) {
    $branches = $pdo->query("SELECT id, name FROM branches ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
}

/* ---- Detect optional columns on sale_payments ---- */
$hasCreatedAt  = false;
$hasTransferDt = false;
try {
    $hasCreatedAt  = (bool)$pdo->query("SHOW COLUMNS FROM sale_payments LIKE 'created_at'")->fetch();
} catch (Throwable $ignored) {}
try {
    $hasTransferDt = (bool)$pdo->query("SHOW COLUMNS FROM sale_payments LIKE 'transfer_date'")->fetch();
} catch (Throwable $ignored) {}

/* preferred ts = transfer_date -> created_at -> sale_date */
$selectCreated = $hasCreatedAt ? "sp.created_at" : "s.sale_date";
$selectTransfer= $hasTransferDt ? "sp.transfer_date" : "NULL";

/* Order by most-relevant time */
if ($hasTransferDt && $hasCreatedAt) {
    $orderBy = "ORDER BY COALESCE(sp.transfer_date, sp.created_at, s.sale_date) DESC";
} elseif ($hasTransferDt) {
    $orderBy = "ORDER BY COALESCE(sp.transfer_date, s.sale_date) DESC";
} elseif ($hasCreatedAt) {
    $orderBy = "ORDER BY sp.created_at DESC";
} else {
    $orderBy = "ORDER BY s.sale_date DESC";
}

$sql = "
SELECT
    sp.id AS payment_id,
    sp.sale_id,
    sp.amount,
    sp.cheque_number,
    sp.bank_name,
    sp.bank_branch,
    sp.deposit_date,
    {$selectTransfer} AS transfer_date,
    {$selectCreated}  AS created_at,
    IFNULL(c.name, 'Walk-in') AS customer_name,
    s.sale_date,
    s.branch_id,
    b.name AS branch_name
FROM sale_payments sp
JOIN sales s     ON sp.sale_id   = s.id
JOIN branches b  ON s.branch_id  = b.id
LEFT JOIN customers c ON s.customer_id = c.id
WHERE sp.method = 'cheque' AND sp.deposit_date IS NULL
";

$params = [];
if ($filter_branch_id) {
    $sql .= " AND s.branch_id = ?";
    $params[] = $filter_branch_id;
}
$sql .= ' ' . $orderBy;

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$cheques = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($cheques as &$chk) {
    $chk['source']     = 'sale';
    $chk['unique_key'] = 'sale_' . $chk['payment_id'];
    $times = [];
    if (!empty($chk['transfer_date'])) $times[] = strtotime($chk['transfer_date']);
    if (!empty($chk['created_at']))    $times[] = strtotime($chk['created_at']);
    if (!empty($chk['sale_date']))     $times[] = strtotime($chk['sale_date']);
    $chk['sort_key'] = $times ? max($times) : 0;
}
unset($chk);

if (table_exists($pdo, 'customer_opening_payments')) {
    $openBranchCol = col_exists($pdo, 'customer_opening_payments', 'branch_id') ? 'cop.branch_id' : null;
    $openDepositCol = col_exists($pdo, 'customer_opening_payments', 'deposit_date') ? 'cop.deposit_date' : null;
    $openExpectedCol = col_exists($pdo, 'customer_opening_payments', 'expected_deposit_date') ? 'cop.expected_deposit_date' : 'NULL';
    $openDateCol = first_available_col($pdo, 'customer_opening_payments', ['received_at','created_at','updated_at']);
    $customerHasBranch = col_exists($pdo, 'customers', 'branch_id');
    $branchJoin = 'LEFT JOIN branches b ON ';
    if ($openBranchCol) {
        $branchJoin .= 'cop.branch_id = b.id';
    } elseif ($customerHasBranch) {
        $branchJoin .= 'c.branch_id = b.id';
    } else {
        $branchJoin .= '1=0';
    }

    $sqlOpen = "SELECT
            cop.id AS payment_id,
            NULL AS sale_id,
            cop.amount,
            cop.cheque_number,
            cop.bank_name,
            cop.bank_branch,
            cop.deposit_date,
            $openExpectedCol AS transfer_date,
            " . ($openDateCol ? "cop.$openDateCol" : 'cop.created_at') . " AS created_at,
            IFNULL(c.name, 'Opening Balance') AS customer_name,
            NULL AS sale_date,
            " . ($openBranchCol ? $openBranchCol : 'NULL') . " AS branch_id,
            b.name AS branch_name
        FROM customer_opening_payments cop
        LEFT JOIN customers c ON cop.customer_id = c.id
        $branchJoin
        WHERE cop.method='cheque'";

    $paramsOpen = [];
    if ($openDepositCol) {
        $sqlOpen .= " AND ($openDepositCol IS NULL OR $openDepositCol = '0000-00-00')";
    }
    if ($openDateCol) {
        $sqlOpen .= " AND cop.$openDateCol <= :cutoff";
        $paramsOpen[':cutoff'] = date('Y-m-d H:i:s');
    }
    if ($filter_branch_id && $openBranchCol) {
        $sqlOpen .= " AND cop.branch_id = :branch";
        $paramsOpen[':branch'] = $filter_branch_id;
    } elseif ($filter_branch_id && !$openBranchCol && $customerHasBranch) {
        $sqlOpen .= " AND c.branch_id = :branch";
        $paramsOpen[':branch'] = $filter_branch_id;
    } elseif (!$is_admin && $filter_branch_id === 0) {
        // Non admin branch scoping when no branch column exists
        if ($openBranchCol) {
            $sqlOpen .= " AND cop.branch_id = :branchLim";
            $paramsOpen[':branchLim'] = (int)($user['branch_id'] ?? 0);
        } elseif ($customerHasBranch) {
            $sqlOpen .= " AND c.branch_id = :branchLim";
            $paramsOpen[':branchLim'] = (int)($user['branch_id'] ?? 0);
        }
    }

    try {
        $stmtOpen = $pdo->prepare($sqlOpen);
        $stmtOpen->execute($paramsOpen);
        $openingCheques = $stmtOpen->fetchAll(PDO::FETCH_ASSOC);
        foreach ($openingCheques as &$oc) {
            $oc['source']     = 'opening';
            $oc['unique_key'] = 'opening_' . $oc['payment_id'];
            $times = [];
            if (!empty($oc['transfer_date'])) $times[] = strtotime($oc['transfer_date']);
            if (!empty($oc['created_at']))    $times[] = strtotime($oc['created_at']);
            $oc['sort_key'] = $times ? max($times) : 0;
        }
        unset($oc);
        $cheques = array_merge($cheques, $openingCheques);
    } catch (Throwable $e) {}
}

usort($cheques, function(array $a, array $b) {
    return ($b['sort_key'] <=> $a['sort_key']);
});

/* Banks by branch (include NULL branch_id as global under key 0) */
$banksByBranch = [];
$rows = $pdo->query("SELECT id, name, branch_id FROM bank_accounts ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $bk) {
    $bid = $bk['branch_id'] === null ? 0 : (int)$bk['branch_id'];
    if (!isset($banksByBranch[$bid])) $banksByBranch[$bid] = [];
    $banksByBranch[$bid][] = $bk;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Cheques In Hand - POS System</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
</head>
<body>
<?php include 'includes/nav.php'; ?>
<div class="container">
    <h1 class="mb-4">Cheques In Hand</h1>

    <?php if ($is_admin): ?>
    <form method="get" class="row g-3 mb-3">
        <div class="col-md-4">
            <label class="form-label">Branch</label>
            <select name="branch_id" class="form-select" onchange="this.form.submit()">
                <option value="">All Branches</option>
                <?php foreach ($branches as $br): ?>
                    <option value="<?php echo (int)$br['id']; ?>" <?php echo ($filter_branch_id == $br['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($br['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>
    <?php endif; ?>

    <div class="table-responsive">
        <table id="chequeTable" class="table table-bordered table-striped">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Sale / Ref</th>
                    <?php if ($is_admin): ?><th>Branch</th><?php endif; ?>
                    <th>Customer</th>
                    <th class="text-end">Amount (Rs.)</th>
                    <th>Cheque #</th>
                    <th>Bank</th>
                    <th>Branch</th>
                    <th>Transfer Date</th>   <!-- NEW: before Created At -->
                    <th>Created At</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($cheques as $chk): ?>
                <tr>
                    <td><?php echo (int)$chk['payment_id']; ?></td>
                    <td>
                        <?php
                          if ($chk['source'] === 'opening') {
                              echo 'Opening';
                          } else {
                              echo (int)$chk['sale_id'];
                          }
                        ?>
                    </td>
                    <?php if ($is_admin): ?><td><?php echo htmlspecialchars($chk['branch_name']); ?></td><?php endif; ?>
                    <td><?php echo htmlspecialchars($chk['customer_name']); ?></td>
                    <td class="text-end"><?php echo number_format((float)$chk['amount'], 2); ?></td>
                    <td><?php echo htmlspecialchars($chk['cheque_number']); ?></td>
                    <td><?php echo htmlspecialchars($chk['bank_name']); ?></td>
                    <td><?php echo htmlspecialchars($chk['bank_branch']); ?></td>
                    <td>
                        <?php
                          echo ($chk['transfer_date'] ? date('d-M-Y', strtotime($chk['transfer_date'])) : '—');
                        ?>
                    </td>
                    <td>
                        <?php echo htmlspecialchars(date('d-M-Y', strtotime($chk['created_at']))); ?>
                    </td>
                    <td>
                        <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#depositModal<?php echo htmlspecialchars($chk['unique_key']); ?>">Deposit</button>
                    </td>
                </tr>

                <!-- Deposit modal -->
                <div class="modal fade" id="depositModal<?php echo htmlspecialchars($chk['unique_key']); ?>" tabindex="-1" aria-hidden="true">
                  <div class="modal-dialog">
                    <div class="modal-content">
                      <div class="modal-header">
                        <h5 class="modal-title">Deposit Cheque #<?php echo htmlspecialchars($chk['cheque_number']); ?></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                      </div>
                      <div class="modal-body">
                        <p>
                          <strong><?php echo ($chk['source'] === 'opening') ? 'Customer' : 'Customer'; ?>:</strong> <?php echo htmlspecialchars($chk['customer_name']); ?><br>
                          <strong>Amount:</strong> Rs. <?php echo number_format((float)$chk['amount'], 2); ?><br>
                          <strong>Bank:</strong> <?php echo htmlspecialchars($chk['bank_name']); ?><?php if (!empty($chk['bank_branch'])) echo ', '.htmlspecialchars($chk['bank_branch']); ?>
                        </p>

                        <!-- Deposit date (EMPTY by default now) -->
                        <div class="mb-3">
                          <label class="form-label">Deposit Date</label>
                          <input type="date" class="form-control deposit-date-input"
                                 id="depositDate<?php echo htmlspecialchars($chk['unique_key']); ?>" value="">
                        </div>

                        <div class="mb-3">
                          <label class="form-label">Deposit To Bank</label>
                          <select class="form-select" id="bankId<?php echo htmlspecialchars($chk['unique_key']); ?>">
                            <?php
                              $bId     = (int)$chk['branch_id'];
                              $options = [];
                              if (isset($banksByBranch[$bId])) $options = array_merge($options, $banksByBranch[$bId]);
                              if (isset($banksByBranch[0]))    $options = array_merge($options, $banksByBranch[0]);
                              foreach ($options as $bank):
                            ?>
                              <option value="<?php echo (int)$bank['id']; ?>"><?php echo htmlspecialchars($bank['name']); ?></option>
                            <?php endforeach; ?>
                          </select>
                        </div>
                      </div>
                      <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-primary deposit-btn" data-id="<?php echo (int)$chk['payment_id']; ?>" data-source="<?php echo htmlspecialchars($chk['source']); ?>" data-key="<?php echo htmlspecialchars($chk['unique_key']); ?>">Confirm Deposit</button>
                      </div>
                    </div>
                  </div>
                </div>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script>
$(function() {
  $('#chequeTable').DataTable();

  $(document).on('click', '.deposit-btn', function() {
    const paymentId   = $(this).data('id');
    const source      = $(this).data('source');
    const key         = $(this).data('key');
    const dateInput   = $('#depositDate' + key);
    const bankSelect  = $('#bankId' + key);
    const depositDate = dateInput.val();
    const bankId      = bankSelect.val();

    if (!depositDate) { alert('Please select a deposit date.'); return; }
    if (!bankId)      { alert('Please select a bank to deposit into.'); return; }
    if (!confirm('Mark this cheque as deposited on ' + depositDate + '?')) return;

    $.post('ajax_deposit_cheque.php', {payment_id: paymentId, source: source, deposit_date: depositDate, bank_id: bankId}, function(resp) {
      if (resp.status === 'success') location.reload();
      else alert(resp.message || 'Failed to deposit cheque');
    }, 'json').fail(function(){ alert('Error communicating with server'); });
  });
});
</script>
</body>
</html>
