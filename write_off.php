<?php
/**
 * write_off.php (Items + Cash/Bank/Wallet withdrawals)
 *
 * - Keeps your existing Item Write-Off (owner use) flow.
 * - Adds Cash/Bank/Wallet Write-Off (owner withdrawal) with:
 *     source = cash | bank | wallet (+ bank select when bank)
 * - On save:
 *     * inserts a row in cash_draws (for listing/audit)
 *     * inserts a negative journal_entries row:
 *         - account_type='cash'  → reduces Cash in Hand
 *         - account_type='wallet'→ reduces Wallet Balance
 *         - account_type='bank'  → reduces Bank Balance (see FS note below)
 * - Creates table cash_draws if it does not exist.
 * - Two tables at bottom:
 *     1) Recorded Item Write-Offs
 *     2) Cash/Bank/Wallet Withdrawals
 */

require_once 'includes/header.php';

// Only admin, manager or inventory officer can access
checkRole(['admin','manager','inventory_officer']);

$is_admin = (($user['role_name'] ?? '') === 'admin');
$user_branch_id = (int)($user['branch_id'] ?? 0);

// --- helpers ---------------------------------------------------
function show_alert($type, $text){
  return '<div class="alert alert-'.$type.' alert-dismissible fade show" role="alert">'
       . htmlspecialchars($text)
       . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>'
       . '</div>';
}
function col_exists(PDO $pdo, $table, $col){
  try { return (bool)$pdo->query("SHOW COLUMNS FROM `$table` LIKE ".$pdo->quote($col))->fetch(PDO::FETCH_ASSOC); }
  catch(Throwable $e){ return false; }
}
function table_exists(PDO $pdo, $table){
  try { $q=$pdo->query("SHOW TABLES LIKE ".$pdo->quote($table)); return (bool)($q && $q->fetchColumn()); }
  catch(Throwable $e){ return false; }
}
function ensure_cash_draw_table(PDO $pdo){
  if (!table_exists($pdo,'cash_draws')) {
    $sql = "CREATE TABLE IF NOT EXISTS cash_draws (
              id INT AUTO_INCREMENT PRIMARY KEY,
              branch_id INT NOT NULL,
              source_type ENUM('cash','bank','wallet') NOT NULL,
              bank_id INT NULL,
              amount DECIMAL(12,2) NOT NULL,
              draw_date DATE NOT NULL,
              remarks VARCHAR(255) NULL,
              created_by INT NOT NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              KEY idx_branch (branch_id),
              KEY idx_bank (bank_id),
              KEY idx_date (draw_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    try { $pdo->exec($sql); } catch(Throwable $e){}
  }
}
// product helpers
function getProd($pdo, $id) {
  $st = $pdo->prepare("SELECT * FROM products WHERE id = ?");
  $st->execute([$id]);
  return $st->fetch(PDO::FETCH_ASSOC);
}
function getProdBatches($pdo, $product_id, $branchFilter) {
  $sql = "SELECT sb.*, b.name AS branch_name
          FROM stock_batches sb
          JOIN branches b ON sb.branch_id = b.id
          WHERE sb.product_id = ? AND sb.quantity > 0";
  $params = [$product_id];
  if ($branchFilter) { $sql .= " AND sb.branch_id = ?"; $params[] = $branchFilter; }
  $sql .= " ORDER BY sb.batch_no";
  $st = $pdo->prepare($sql);
  $st->execute($params);
  return $st->fetchAll(PDO::FETCH_ASSOC);
}

// Make sure cash_draws exists
ensure_cash_draw_table($pdo);

// -------- Branch lists & banks (for admin bank pick) ----------
$branches = [];
try { $branches = $pdo->query("SELECT id,name FROM branches ORDER BY name")->fetchAll(PDO::FETCH_ASSOC); } catch(Throwable $e){}

$banks = [];
try {
  // We will show all banks to admin, or this user’s branch + global (NULL) to managers.
  if ($is_admin) {
    $banks = $pdo->query("SELECT ba.id, ba.name, ba.branch_id, b.name AS branch_name
                          FROM bank_accounts ba
                          LEFT JOIN branches b ON b.id=ba.branch_id
                          ORDER BY ba.name")->fetchAll(PDO::FETCH_ASSOC);
  } else {
    $st = $pdo->prepare("SELECT ba.id, ba.name, ba.branch_id, b.name AS branch_name
                         FROM bank_accounts ba
                         LEFT JOIN branches b ON b.id=ba.branch_id
                         WHERE (ba.branch_id IS NULL OR ba.branch_id = ?)
                         ORDER BY ba.name");
    $st->execute([$user_branch_id]);
    $banks = $st->fetchAll(PDO::FETCH_ASSOC);
  }
} catch(Throwable $e){ $banks = []; }

// --------- state for Item write-off ---------------------------
$branch_filter = $is_admin ? null : $user_branch_id;

$search_term   = trim($_GET['search'] ?? '');
$product_id_in = (isset($_GET['product_id']) && ctype_digit($_GET['product_id'])) ? (int)$_GET['product_id'] : 0;
$product_id = $product_id_in;
$product    = $product_id ? getProd($pdo, $product_id) : null;
$avail_batches = [];
$msg = '';
$just_saved_success = false;

// --------- SUBMIT: Item write-off -----------------------------
if (isset($_POST['record_write_off']) && $product_id) {
  $batch_id = (int)($_POST['batch_id'] ?? 0);
  $qty      = (int)($_POST['quantity'] ?? 0);
  $remarks  = trim($_POST['remarks'] ?? '');
  if ($batch_id && $qty > 0) {
    $st = $pdo->prepare("SELECT sb.*, b.name AS branch_name
                         FROM stock_batches sb
                         JOIN branches b ON sb.branch_id = b.id
                         WHERE sb.id = ? AND sb.product_id = ?" . ($branch_filter ? " AND sb.branch_id = ?" : ""));
    $params = [$batch_id, $product_id];
    if ($branch_filter) { $params[] = $branch_filter; }
    $st->execute($params);
    $batch = $st->fetch(PDO::FETCH_ASSOC);
    if ($batch) {
      if ($qty > $batch['quantity']) {
        $msg .= show_alert('danger','Quantity exceeds available stock.');
      } else {
        $cost_price = (float)$batch['cost_price'];
        $pdo->beginTransaction();
        try {
          // reduce stock
          $pdo->prepare("UPDATE stock_batches SET quantity = quantity - ? WHERE id = ?")
              ->execute([$qty,$batch_id]);
          // record write-off
          $pdo->prepare("INSERT INTO write_offs
              (batch_id, product_id, branch_id, quantity, cost_price, write_off_date, remarks, created_by)
              VALUES (?,?,?,?,?,?,?,?)")
              ->execute([
                $batch_id, $product_id, (int)$batch['branch_id'], $qty, $cost_price,
                date('Y-m-d H:i:s'), $remarks, (int)$user['id']
              ]);
          // optional log
          try {
            $pdo->prepare("INSERT INTO activity_logs (user_id, action, description, branch_id)
                           VALUES (?,?,?,?)")
                ->execute([(int)$user['id'], 'WriteOff',
                  sprintf('Write off %d units of product ID %d (batch %s)',$qty,$product_id,$batch['batch_no']),
                  (int)$batch['branch_id']
                ]);
          } catch(Throwable $e){}

          $pdo->commit();
          $msg .= show_alert('success','Item write-off recorded.');
          $just_saved_success = true;
          $product_id = 0; $product=null; $avail_batches=[];
        } catch(Throwable $e) {
          $pdo->rollBack();
          $msg .= show_alert('danger','Failed to record item write-off: '.$e->getMessage());
        }
      }
    } else {
      $msg .= show_alert('danger','Invalid batch selected.');
    }
  } else {
    $msg .= show_alert('danger','Please select a batch and quantity.');
  }
}

// If not just saved and have product id, load batches
if (!$just_saved_success && $product_id && $product) {
  $avail_batches = getProdBatches($pdo, $product_id, $branch_filter);
}

/* ---------- SUBMIT: Cash/Bank/Wallet withdrawal -------------- */
if (isset($_POST['record_cash_draw'])) {
  $cd_source  = $_POST['cd_source'] ?? '';
  $cd_amount  = (float)($_POST['cd_amount'] ?? 0);
  $cd_date    = trim($_POST['cd_date'] ?? date('Y-m-d'));
  $cd_remarks = trim($_POST['cd_remarks'] ?? '');
  $cd_bank_id = isset($_POST['cd_bank_id']) && ctype_digit($_POST['cd_bank_id']) ? (int)$_POST['cd_bank_id'] : null;
  $cd_branch  = $is_admin ? ((isset($_POST['cd_branch_id']) && ctype_digit($_POST['cd_branch_id'])) ? (int)$_POST['cd_branch_id'] : $user_branch_id) : $user_branch_id;

  if (!in_array($cd_source, ['cash','bank','wallet'], true)) {
    $msg .= show_alert('danger','Please select a valid source (cash/bank/wallet).');
  } elseif ($cd_amount <= 0) {
    $msg .= show_alert('danger','Amount must be greater than zero.');
  } elseif ($cd_source === 'bank' && !$cd_bank_id) {
    $msg .= show_alert('danger','Please choose the bank account.');
  } else {
    // Insert into cash_draws + journal_entries (negative amount)
    $pdo->beginTransaction();
    try {
      ensure_cash_draw_table($pdo);

      // 1) cash_draws row
      $ins = $pdo->prepare("INSERT INTO cash_draws (branch_id, source_type, bank_id, amount, draw_date, remarks, created_by)
                            VALUES (?,?,?,?,?,?,?)");
      $ins->execute([$cd_branch, $cd_source, $cd_bank_id, $cd_amount, $cd_date, $cd_remarks, (int)$user['id']]);

      // 2) journal_entries row (negative)
      $has_desc  = col_exists($pdo,'journal_entries','description');
      $has_user  = col_exists($pdo,'journal_entries','created_by');
      $has_bkid  = col_exists($pdo,'journal_entries','bank_id'); // optional

      $cols = ['account_type','amount','entry_date','branch_id'];
      $vals = [$cd_source, -abs($cd_amount), $cd_date, $cd_branch];

      if ($has_desc) { $cols[]='description'; $vals[]='Owner Withdrawal (write_off.php)'; }
      if ($has_user) { $cols[]='created_by';   $vals[]=(int)$user['id']; }
      if ($cd_source==='bank' && $has_bkid) { $cols[]='bank_id'; $vals[]=$cd_bank_id; }

      $placeholders = implode(',', array_fill(0, count($cols), '?'));
      $sql = "INSERT INTO journal_entries (".implode(',',$cols).") VALUES ($placeholders)";
      $pdo->prepare($sql)->execute($vals);

      // activity log
      try {
        $pdo->prepare("INSERT INTO activity_logs (user_id, action, description, branch_id)
                       VALUES (?,?,?,?)")
            ->execute([(int)$user['id'], 'CashDraw',
              sprintf('Owner withdrawal %s Rs. %.2f%s on %s',
                      strtoupper($cd_source), $cd_amount,
                      ($cd_source==='bank' && $cd_bank_id) ? (" (bank #".$cd_bank_id.")") : '',
                      $cd_date),
              $cd_branch
            ]);
      } catch(Throwable $e){}

      $pdo->commit();
      $msg .= show_alert('success','Withdrawal recorded.');
    } catch(Throwable $e) {
      $pdo->rollBack();
      $msg .= show_alert('danger','Failed to record withdrawal: '.$e->getMessage());
    }
  }
}

/* ---------- lists: item write-offs + cash_draws ---------------- */
$where = "1=1"; $params = [];
if ($branch_filter) { $where .= " AND w.branch_id=?"; $params[]=$branch_filter; }
$sql = "SELECT w.*, p.name AS product_name, sb.batch_no, b.name AS branch_name, u.username
        FROM write_offs w
        JOIN products p ON p.id=w.product_id
        JOIN stock_batches sb ON sb.id=w.batch_id
        JOIN branches b ON b.id=w.branch_id
        JOIN users u ON u.id=w.created_by
        WHERE $where
        ORDER BY w.write_off_date DESC, w.id DESC";
$st = $pdo->prepare($sql);
$st->execute($params);
$writeOffs = $st->fetchAll(PDO::FETCH_ASSOC);

// cash_draws list
$where2 = "1=1"; $params2=[];
if ($branch_filter) { $where2 .= " AND cd.branch_id=?"; $params2[]=$branch_filter; }
$sql2 = "SELECT cd.*, ba.name AS bank_name, br.name AS branch_name, u.username
         FROM cash_draws cd
         LEFT JOIN bank_accounts ba ON ba.id=cd.bank_id
         LEFT JOIN branches br ON br.id=cd.branch_id
         LEFT JOIN users u ON u.id=cd.created_by
         WHERE $where2
         ORDER BY cd.draw_date DESC, cd.id DESC";
$st2 = $pdo->prepare($sql2);
$st2->execute($params2);
$cashDraws = $st2->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Write Off & Withdrawals - POS System</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
  <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
  <style>
    #woSearchResults { z-index: 1000; max-height: 220px; overflow-y:auto; width: 50%; }
  </style>
</head>
<body>
<?php include 'includes/nav.php'; ?>
<div class="container my-4">
  <h3 class="mb-3">Write-Offs & Owner Withdrawals</h3>

  <?php echo $msg; ?>

  <!-- ===== ITEMS WRITE-OFF (owner use) ===== -->
  <div class="card mb-4">
    <div class="card-header"><strong>Item Write-Off (Owner Use)</strong></div>
    <div class="card-body">
      <!-- Product search -->
      <div class="mb-3 position-relative">
        <label class="form-label">Search Products</label>
        <input type="text" id="woProductSearch" class="form-control" placeholder="Start typing name or barcode..." autocomplete="off">
        <div id="woSearchResults" class="list-group position-absolute"></div>
        <div class="form-text">Tip: type 2–3 letters (or barcode) and pick from the list.</div>
      </div>

      <?php if ($product && !$just_saved_success): ?>
        <div class="mb-2">
          <h6 class="mb-1">Selected: <?php echo htmlspecialchars($product['name']); ?></h6>
          <small class="text-muted">Barcode: <?php echo htmlspecialchars($product['barcode']); ?></small><br>
          <a href="write_off.php" class="btn btn-outline-secondary btn-sm mt-2">Choose Another Product</a>
        </div>

        <?php if ($avail_batches): ?>
          <form method="post" class="card card-body">
            <input type="hidden" name="product_id" value="<?php echo (int)$product['id']; ?>">
            <div class="row g-3 align-items-end">
              <div class="col-md-4">
                <label class="form-label">Batch</label>
                <select name="batch_id" class="form-select" required>
                  <?php foreach($avail_batches as $b): ?>
                    <option value="<?php echo (int)$b['id']; ?>">
                      <?php echo htmlspecialchars($b['batch_no'].' - '.$b['branch_name'].' (Qty: '.$b['quantity'].')'); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-2">
                <label class="form-label">Quantity</label>
                <input type="number" name="quantity" class="form-control" min="1" max="9999" required>
              </div>
              <div class="col-md-4">
                <label class="form-label">Remarks (optional)</label>
                <input type="text" name="remarks" class="form-control" placeholder="e.g. Owner use">
              </div>
              <div class="col-md-2 d-grid">
                <button type="submit" name="record_write_off" class="btn btn-primary">Record</button>
              </div>
            </div>
          </form>
        <?php else: ?>
          <p class="text-warning">No available batches for this product.</p>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- ===== CASH/BANK/WALLET WITHDRAWAL ===== -->
  <div class="card mb-4">
    <div class="card-header"><strong>Cash / Bank / Wallet Write-Off (Owner Withdrawal)</strong></div>
    <div class="card-body">
      <form method="post">
        <div class="row g-3">
          <?php if ($is_admin): ?>
            <div class="col-md-3">
              <label class="form-label">Branch</label>
              <select class="form-select" name="cd_branch_id" required>
                <?php foreach($branches as $br): ?>
                  <option value="<?php echo (int)$br['id']; ?>" <?php echo ((int)$br['id']===$user_branch_id?'selected':''); ?>>
                    <?php echo htmlspecialchars($br['name']); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          <?php else: ?>
            <input type="hidden" name="cd_branch_id" value="<?php echo $user_branch_id; ?>">
          <?php endif; ?>

          <div class="col-md-3">
            <label class="form-label">Source</label>
            <select class="form-select" name="cd_source" id="cd_source" required>
              <option value="cash">Cash</option>
              <option value="bank">Bank</option>
              <option value="wallet">Wallet</option>
            </select>
          </div>

          <div class="col-md-4" id="bankWrap" style="display:none;">
            <label class="form-label">Bank Account</label>
            <select class="form-select" name="cd_bank_id" id="cd_bank_id">
              <option value="">Select bank…</option>
              <?php foreach($banks as $bk): ?>
                <option value="<?php echo (int)$bk['id']; ?>">
                  <?php
                    $bn = $bk['bank_name'] ?? $bk['name'];
                    $brn= $bk['branch_name'] ?? '';
                    echo htmlspecialchars($bn . ($brn ? " — ".$brn : ''));
                  ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-2">
            <label class="form-label">Amount (Rs.)</label>
            <input type="number" step="0.01" min="0.01" class="form-control" name="cd_amount" required>
          </div>

          <div class="col-md-2">
            <label class="form-label">Date</label>
            <input type="date" class="form-control" name="cd_date" value="<?php echo date('Y-m-d'); ?>" required>
          </div>

          <div class="col-md-6">
            <label class="form-label">Remarks (optional)</label>
            <input type="text" class="form-control" name="cd_remarks" placeholder="e.g. Owner cash withdrawal">
          </div>

          <div class="col-md-2 d-grid align-self-end">
            <button type="submit" name="record_cash_draw" class="btn btn-success">Record Withdrawal</button>
          </div>
        </div>
      </form>
    </div>
  </div>

  <!-- ===== LISTS ===== -->

  <h4 class="mb-2">Recorded Item Write-Offs</h4>
  <div class="table-responsive mb-4">
    <table id="woTable" class="table table-striped table-bordered align-middle">
      <thead>
        <tr>
          <th>ID</th>
          <th>Date</th>
          <th>Product</th>
          <th>Batch</th>
          <th>Branch</th>
          <th>Qty</th>
          <th>Cost Price</th>
          <th>Remarks</th>
          <th>By</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach($writeOffs as $wo): ?>
        <tr>
          <td><?php echo (int)$wo['id']; ?></td>
          <td><?php echo htmlspecialchars($wo['write_off_date']); ?></td>
          <td><?php echo htmlspecialchars($wo['product_name']); ?></td>
          <td><?php echo htmlspecialchars($wo['batch_no']); ?></td>
          <td><?php echo htmlspecialchars($wo['branch_name']); ?></td>
          <td><?php echo (int)$wo['quantity']; ?></td>
          <td><?php echo number_format((float)$wo['cost_price'],2); ?></td>
          <td><?php echo htmlspecialchars($wo['remarks']); ?></td>
          <td><?php echo htmlspecialchars($wo['username']); ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <h4 class="mb-2">Cash / Bank / Wallet Withdrawals</h4>
  <div class="table-responsive">
    <table id="cdTable" class="table table-striped table-bordered align-middle">
      <thead>
        <tr>
          <th>ID</th>
          <th>Date</th>
          <th>Branch</th>
          <th>Source</th>
          <th>Bank</th>
          <th class="text-end">Amount (Rs.)</th>
          <th>Remarks</th>
          <th>By</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($cashDraws as $cd): ?>
        <tr>
          <td><?php echo (int)$cd['id']; ?></td>
          <td><?php echo htmlspecialchars($cd['draw_date']); ?></td>
          <td><?php echo htmlspecialchars($cd['branch_name'] ?? ''); ?></td>
          <td class="text-capitalize"><?php echo htmlspecialchars($cd['source_type']); ?></td>
          <td><?php echo htmlspecialchars($cd['bank_name'] ?? ''); ?></td>
          <td class="text-end"><?php echo number_format((float)$cd['amount'], 2); ?></td>
          <td><?php echo htmlspecialchars($cd['remarks']); ?></td>
          <td><?php echo htmlspecialchars($cd['username'] ?? ''); ?></td>
        </tr>
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
$(function(){
  $('#woTable').DataTable({ pageLength: 25 });
  $('#cdTable').DataTable({ pageLength: 25 });

  // show/hide bank select
  const srcSel = document.getElementById('cd_source');
  const bankWrap = document.getElementById('bankWrap');
  function toggleBank(){
    if (!srcSel) return;
    bankWrap.style.display = (srcSel.value === 'bank') ? 'block' : 'none';
  }
  if (srcSel) {
    srcSel.addEventListener('change', toggleBank);
    toggleBank();
  }

  // Live product search
  var $results = $('#woSearchResults');
  var $input   = $('#woProductSearch');

  function doSearch(q){
    if(q.length < 1){ $results.hide().empty(); return; }
    $.getJSON('ajax_search_products.php', { q: q }, function(data){
      var html = '';
      if (data && data.length){
        $.each(data, function(i, item){
          html += '<a href="write_off.php?product_id='+ item.id +'" class="list-group-item list-group-item-action">'
               +  $('<div>').text(item.name + ' (' + (item.barcode||'') + ')').html() + '</a>';
        });
      } else {
        html = '<div class="list-group-item disabled">No products found</div>';
      }
      $results.html(html).show();
    });
  }
  $input.on('keyup', function(){ doSearch($(this).val().trim()); });
  $(document).on('click', function(e){ if (!$(e.target).closest('#woProductSearch').length) { $results.hide(); } });

  // Focus convenience
  setTimeout(function(){ if (document.getElementById('woProductSearch')) { document.getElementById('woProductSearch').focus(); } }, 150);
});
</script>
</body>
</html>
