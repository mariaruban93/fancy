<?php
/**
 * purchase_cheques.php
 * - Lists supplier cheque payments from purchase_payments.
 * - "Deposited" if explicit clear/status exists, else fallback: deposit_date <= today.
 * - Single global modal + AJAX -> ajax_deposit_purchase_cheque.php (JSON).
 * - PHP 8 compatible.
 */

require_once 'includes/header.php';
checkRole(['admin','manager']);

function col_exists(PDO $pdo, string $table, string $col): bool {
  try { return (bool)$pdo->query("SHOW COLUMNS FROM `$table` LIKE ".$pdo->quote($col))->fetch(PDO::FETCH_ASSOC); }
  catch (Throwable $e) { return false; }
}

if (!function_exists('table_exists_purchase_cheques')) {
  function table_exists_purchase_cheques(PDO $pdo, string $table): bool {
    try {
      $stmt = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table));
      return (bool)$stmt->fetch(PDO::FETCH_NUM);
    } catch (Throwable $e) {
      return false;
    }
  }
}

$is_admin = (($user['role_name'] ?? '') === 'admin');

/* --- branch filter --- */
$filter_branch_id = 0;
if ($is_admin) {
  if (isset($_GET['branch_id']) && ctype_digit($_GET['branch_id'])) $filter_branch_id = (int)$_GET['branch_id'];
} else {
  $filter_branch_id = (int)($user['branch_id'] ?? 0);
}

/* --- extra filters --- */
$filter_cheque = trim($_GET['cheque_no'] ?? '');
$filter_date   = trim($_GET['deposit_date'] ?? '');

/* --- branches for dropdown --- */
$branches = [];
if ($is_admin) {
  try { $branches = $pdo->query("SELECT id,name FROM branches ORDER BY name")->fetchAll(PDO::FETCH_ASSOC); } catch(Throwable $e){}
}

/* --- choose column expressions once --- */
$has_method = col_exists($pdo,'purchase_payments','method');

$chq_no_expr = "''";
foreach (['cheque_number','cheque_no','ch_number','chq_no'] as $c) {
  if (col_exists($pdo,'purchase_payments',$c)) { $chq_no_expr = "pp.$c"; break; }
}

$bank_name_expr = "''";
foreach (['bank_name','bank'] as $c) {
  if (col_exists($pdo,'purchase_payments',$c)) { $bank_name_expr = "pp.$c"; break; }
}

$bank_branch_expr = "''";
foreach (['bank_branch','bank_branch_name','branch_name'] as $c) {
  if (col_exists($pdo,'purchase_payments',$c)) { $bank_branch_expr = "pp.$c"; break; }
}

/* cleared flag/status to decide UI "Deposited" */
$clear_expr = "NULL AS cleared_at";
foreach (['cleared_on','clear_date','transferred_on'] as $c) {
  if (col_exists($pdo,'purchase_payments',$c)) { $clear_expr = "pp.$c AS cleared_at"; break; }
}
$status_expr = col_exists($pdo,'purchase_payments','status') ? "pp.status AS status" : "'' AS status";

/* deposit bank (ajax writes either bank_account_id or bank_id) — not required for fallback-by-date */
$deposit_bank_expr = "NULL";
if (col_exists($pdo,'purchase_payments','bank_account_id')) $deposit_bank_expr = "pp.bank_account_id";
elseif (col_exists($pdo,'purchase_payments','bank_id'))     $deposit_bank_expr = "pp.bank_id";

/* flags used by UI logic */
$has_clear_col  = false;
foreach (['cleared_on','clear_date','transferred_on'] as $c) {
  if (col_exists($pdo,'purchase_payments',$c)) { $has_clear_col = true; break; }
}
$has_status_col = col_exists($pdo,'purchase_payments','status');

/* --- load cheques --- */
$sql = "SELECT
          pp.id AS payment_id,
          pp.purchase_id,
          pp.amount,
          $chq_no_expr      AS cheque_number,
          $bank_name_expr   AS bank_name,
          $bank_branch_expr AS bank_branch,
          $clear_expr,
          $status_expr,
          $deposit_bank_expr AS deposit_bank_id,
          pp.deposit_date,
          pp.paid_at,
          p.branch_id,
          b.name AS branch_name,
          s.name AS supplier_name
        FROM purchase_payments pp
        JOIN purchases p ON pp.purchase_id = p.id
        JOIN branches  b ON p.branch_id    = b.id
        JOIN suppliers s ON p.supplier_id  = s.id
        WHERE 1=1";
$params = [];

if ($has_method) {
  $sql .= " AND LOWER(pp.method) IN ('cheque','chuque','chq','cheq','check','cq')";
}
if ($filter_branch_id) { $sql .= " AND p.branch_id = ?"; $params[] = $filter_branch_id; }
if ($filter_cheque !== '') {
  $like = '%'.$filter_cheque.'%';
  $sql .= " AND ( $chq_no_expr LIKE ? OR $bank_name_expr LIKE ? OR $bank_branch_expr LIKE ? )";
  array_push($params,$like,$like,$like);
}
if ($filter_date !== '') { $sql .= " AND pp.deposit_date = ?"; $params[] = $filter_date; }

$sql .= " ORDER BY pp.paid_at DESC";
$st = $pdo->prepare($sql); $st->execute($params);
$rows = [];
$purchaseRows = $st->fetchAll(PDO::FETCH_ASSOC);
foreach ($purchaseRows as $row) {
  $row['source'] = 'purchase';
  $row['reference'] = 'Purchase #' . (int)$row['purchase_id'];
  $rows[] = $row;
}

if (table_exists_purchase_cheques($pdo, 'opening_supplier_payments')) {
  $sqlOpen = "SELECT
                osp.id AS payment_id,
                osp.amount,
                osp.cheque_number,
                osp.bank_name,
                osp.bank_branch,
                osp.deposit_date,
                osp.issued_on,
                osp.created_at,
                COALESCE(osp.branch_id, s.branch_id, 0) AS branch_id,
                b.name AS branch_name,
                s.name AS supplier_name
              FROM opening_supplier_payments osp
              LEFT JOIN suppliers s ON osp.supplier_id = s.id
              LEFT JOIN branches  b ON COALESCE(osp.branch_id, s.branch_id) = b.id
              WHERE osp.method = 'cheque'";
  $paramsOpen = [];
  $sqlOpen .= " AND (osp.deposit_date IS NULL OR osp.deposit_date = '' OR osp.deposit_date = '0000-00-00')";
  if ($filter_branch_id) {
    $sqlOpen .= " AND COALESCE(osp.branch_id, s.branch_id, 0) = ?";
    $paramsOpen[] = $filter_branch_id;
  }
  if ($filter_cheque !== '') {
    $like = '%'.$filter_cheque.'%';
    $sqlOpen .= " AND (osp.cheque_number LIKE ? OR osp.bank_name LIKE ? OR osp.bank_branch LIKE ? )";
    array_push($paramsOpen, $like, $like, $like);
  }
  if ($filter_date !== '') {
    $sqlOpen .= " AND osp.deposit_date = ?";
    $paramsOpen[] = $filter_date;
  }
  $sqlOpen .= " ORDER BY osp.created_at DESC";
  $stmtOpen = $pdo->prepare($sqlOpen);
  $stmtOpen->execute($paramsOpen);
  $openRows = $stmtOpen->fetchAll(PDO::FETCH_ASSOC);
  foreach ($openRows as $row) {
    $row['purchase_id'] = null;
    $row['paid_at'] = $row['issued_on'] ?? $row['created_at'];
    $row['source'] = 'opening';
    $row['reference'] = 'Opening Balance';
    $row['status'] = $row['status'] ?? null;
    $rows[] = $row;
  }
}

usort($rows, function(array $a, array $b) {
  $aDate = $a['paid_at'] ?? $a['created_at'] ?? '';
  $bDate = $b['paid_at'] ?? $b['created_at'] ?? '';
  return strcmp($bDate, $aDate);
});

/* --- banks for dropdown (branch-specific + global/NULL) --- */
$banksByBranch = [];
try {
  $bankRows = $pdo->query("SELECT * FROM bank_accounts ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
  foreach ($bankRows as $bk) {
    $bid = is_null($bk['branch_id']) ? 0 : (int)$bk['branch_id'];
    if (!isset($banksByBranch[$bid])) $banksByBranch[$bid] = [];
    $label = '';
    if (isset($bk['name']) && $bk['name']!=='') $label = $bk['name'];
    else {
      $label = trim(($bk['bank_name'] ?? '').' '.(isset($bk['account_no']) && $bk['account_no']!=='' ? '(' . $bk['account_no'] . ')' : ''));
      if ($label==='') $label = 'Bank #'.$bk['id'];
    }
    $banksByBranch[$bid][] = ['id'=>(int)$bk['id'], 'label'=>$label];
  }
} catch(Throwable $e){}

$today = date('Y-m-d');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Supplier Cheques</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css" rel="stylesheet">
  <link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet">
  <style>.table-sm td,.table-sm th{padding:.5rem .6rem}</style>
</head>
<body>
<?php include 'includes/nav.php'; ?>

<div class="container my-4">
  <h4 class="mb-3">Supplier Cheques</h4>

  <form method="get" class="row g-3 mb-3">
    <?php if ($is_admin): ?>
      <div class="col-md-3">
        <label class="form-label">Branch</label>
        <select name="branch_id" class="form-select" onchange="this.form.submit()">
          <option value="">All Branches</option>
          <?php foreach ($branches as $br): ?>
            <option value="<?php echo (int)$br['id']; ?>" <?php echo ($filter_branch_id==$br['id']?'selected':''); ?>>
              <?php echo htmlspecialchars($br['name']); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    <?php endif; ?>
    <div class="col-md-3">
      <label class="form-label">Cheque/Bank Search</label>
      <input type="text" name="cheque_no" class="form-control" placeholder="Cheque No., Bank or Branch" value="<?php echo htmlspecialchars($filter_cheque); ?>">
    </div>
    <div class="col-md-3">
      <label class="form-label">Deposit Date</label>
      <input type="date" name="deposit_date" class="form-control" value="<?php echo htmlspecialchars($filter_date); ?>">
    </div>
    <div class="col-md-2 d-flex align-items-end">
      <button class="btn btn-primary w-100" type="submit">Filter</button>
    </div>
    <div class="col-md-1 d-flex align-items-end">
      <a class="btn btn-outline-secondary w-100" href="purchase_cheques.php">Clear</a>
    </div>
  </form>

  <div class="table-responsive">
    <table id="purchaseChequeTable" class="table table-bordered table-striped table-sm align-middle">
      <thead>
      <tr>
        <th>ID</th>
        <th>Reference</th>
        <?php if ($is_admin): ?><th>Branch</th><?php endif; ?>
        <th>Supplier</th>
        <th class="text-end">Amount (Rs.)</th>
        <th>Cheque #</th>
        <th>Bank</th>
        <th>Branch</th>
        <th>Paid At</th>
        <th>Deposit Date</th>
        <th>Action</th>
      </tr>
      </thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="<?php echo $is_admin?11:10; ?>" class="text-center text-muted">No cheques found</td></tr>
      <?php else: foreach ($rows as $r): ?>
        <?php
          $isOpening = isset($r['source']) && $r['source'] === 'opening';
          if ($isOpening) {
            $isCleared = !empty($r['deposit_date']);
          } else {
            // explicit: cleared flag or status='cleared'
            $isCleared = (!empty($r['cleared_at']))
                      || (isset($r['status']) && strtolower($r['status'])==='cleared');

            // FALLBACK when there is NO clear/status column: treat deposit_date <= today as deposited
            if (!$isCleared && !$has_clear_col && !$has_status_col) {
              $dd = !empty($r['deposit_date']) ? substr((string)$r['deposit_date'], 0, 10) : '';
              $isCleared = ($dd !== '' && $dd <= $today);
            }
          }
        ?>
        <tr>
          <td><?php echo (int)$r['payment_id']; ?></td>
          <td><?php echo htmlspecialchars($r['reference']); ?></td>
          <?php if ($is_admin): ?><td><?php echo htmlspecialchars($r['branch_name'] ?? '—'); ?></td><?php endif; ?>
          <td><?php echo htmlspecialchars($r['supplier_name']); ?></td>
          <td class="text-end"><?php echo number_format((float)$r['amount'],2); ?></td>
          <td><?php echo htmlspecialchars($r['cheque_number']); ?></td>
          <td><?php echo htmlspecialchars($r['bank_name']); ?></td>
          <td><?php echo htmlspecialchars($r['bank_branch']); ?></td>
          <td><?php echo !empty($r['paid_at']) ? htmlspecialchars(date('d-M-Y',strtotime($r['paid_at']))) : '-'; ?></td>
          <td><?php echo $r['deposit_date'] ? htmlspecialchars(date('d-M-Y',strtotime($r['deposit_date']))) : '<span class="text-muted">Not deposited</span>'; ?></td>
          <td>
            <?php if (!$isCleared): ?>
              <button class="btn btn-sm btn-primary open-deposit"
                      data-id="<?php echo (int)$r['payment_id']; ?>"
                      data-branch="<?php echo (int)$r['branch_id']; ?>"
                      data-source="<?php echo htmlspecialchars($r['source']); ?>"
                      data-supplier="<?php echo htmlspecialchars($r['supplier_name']); ?>"
                      data-amount="<?php echo (float)$r['amount']; ?>"
                      data-chq="<?php echo htmlspecialchars($r['cheque_number']); ?>"
                      data-bank="<?php echo htmlspecialchars($r['bank_name']); ?>"
                      data-bankbranch="<?php echo htmlspecialchars($r['bank_branch']); ?>">
                Deposit
              </button>
            <?php else: ?>
              <span class="text-success">Deposited</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Global Modal -->
<div class="modal fade" id="depositModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Deposit Cheque</h5>
        <button class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="m_payment_id">
        <input type="hidden" id="m_branch_id">
        <input type="hidden" id="m_source" value="purchase">
        <div class="mb-2"><strong>Supplier:</strong> <span id="m_supplier">-</span></div>
        <div class="mb-2"><strong>Cheque #:</strong> <span id="m_chq">-</span></div>
        <div class="mb-2"><strong>Bank:</strong> <span id="m_bank">-</span> | <span id="m_bankbranch">-</span></div>
        <div class="mb-3"><strong>Amount:</strong> Rs. <span id="m_amount">0.00</span></div>

        <div class="mb-3">
          <label class="form-label">Deposit Date</label>
          <input type="date" class="form-control" id="m_deposit_date" value="<?php echo $today; ?>">
          <div class="form-text">Must be today or later.</div>
        </div>

        <div class="mb-3">
          <label class="form-label">Deposit To Bank</label>
          <select class="form-select" id="m_bank_id"></select>
          <div class="form-text">Shows banks for this purchase branch + global accounts.</div>
        </div>

        <div class="alert alert-info py-2 mb-0">
          On confirm, cheque is marked <em>cleared now</em> (so Cheque Payable ↓ and Bank ↓ together).
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-primary" id="confirmDeposit">Confirm Deposit</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script>
$(function(){
  $('#purchaseChequeTable').DataTable();

  const banksByBranch = <?php
    $emit = [];
    foreach ($banksByBranch as $bid=>$list) $emit[(string)$bid] = $list;
    echo json_encode($emit);
  ?>;

  var depositModal = new bootstrap.Modal(document.getElementById('depositModal'));

  $(document).on('click','.open-deposit',function(){
    const btn = $(this);
    $('#m_payment_id').val(btn.data('id'));
    $('#m_source').val(btn.data('source') || 'purchase');
    const bid = String(btn.data('branch'));
    $('#m_branch_id').val(bid);
    $('#m_supplier').text(btn.data('supplier') || '-');
    $('#m_chq').text(btn.data('chq') || '-');
    $('#m_bank').text(btn.data('bank') || '-');
    $('#m_bankbranch').text(btn.data('bankbranch') || '-');
    $('#m_amount').text(parseFloat(btn.data('amount')||0).toFixed(2));
    $('#m_deposit_date').val('<?php echo $today; ?>');

    // build bank select
    const $sel = $('#m_bank_id').empty();
    var opts = [];
    if (banksByBranch[bid])  opts = opts.concat(banksByBranch[bid]);
    if (banksByBranch['0'])  opts = opts.concat(banksByBranch['0']);
    if (!opts.length) $sel.append('<option value="">No bank accounts</option>');
    for (var i=0;i<opts.length;i++) {
      var o = opts[i];
      $sel.append('<option value="'+o.id+'">'+$('<div>').text(o.label).html()+'</option>');
    }

    depositModal.show();
  });

  $('#confirmDeposit').on('click', function(){
    const id = $('#m_payment_id').val();
    const dd = $('#m_deposit_date').val();
    const bank = $('#m_bank_id').val();
    const today = '<?php echo $today; ?>';
    if (!dd) { alert('Please choose a deposit date.'); return; }
    if (dd < today) { alert('Deposit date cannot be before today.'); return; }
    if (!bank) { alert('Please choose a bank.'); return; }

    const $btn = $(this).prop('disabled', true).text('Saving...');
    $.ajax({
      url: 'ajax_deposit_purchase_cheque.php',
      type: 'POST',
      dataType: 'json',
      data: { payment_id: id, deposit_date: dd, bank_id: bank, source: $('#m_source').val() }
    }).done(function(resp){
      if (resp && resp.status === 'success') location.reload();
      else alert(resp && resp.message ? resp.message : 'Deposit failed');
    }).fail(function(xhr){
      alert('Server error:\n' + (xhr.responseText || '(no response)'));
    }).always(function(){
      $btn.prop('disabled', false).text('Confirm Deposit');
    });
  });
});
</script>
</body>
</html>
