<?php
require_once 'includes/header.php';
checkRole(['admin','manager']);

$branchId = (int)($user['branch_id'] ?? 0);

// -----------------------------
// Supplier payable helper (purchases - returns - purchase_payments - cargo_transfers)
// -----------------------------
function supplier_payable_now(PDO $pdo, int $supplierId, int $branchId): float {
  // Purchases (at cost)
  $st = $pdo->prepare("
    SELECT COALESCE(SUM(pi.quantity * pi.cost_price),0)
    FROM purchase_items pi
    JOIN purchases p ON pi.purchase_id = p.id
    WHERE p.supplier_id = :sid AND p.branch_id = :bid
  ");
  $st->execute([":sid"=>$supplierId, ":bid"=>$branchId]);
  $purch = (float)$st->fetchColumn();

  // Purchase returns
  $st = $pdo->prepare("
    SELECT COALESCE(SUM(pr.total_refund),0)
    FROM purchase_returns pr
    JOIN purchases p ON pr.purchase_id = p.id
    WHERE p.supplier_id = :sid AND p.branch_id = :bid
  ");
  $st->execute([":sid"=>$supplierId, ":bid"=>$branchId]);
  $returns = (float)$st->fetchColumn();

  // Direct supplier payments
  $st = $pdo->prepare("
    SELECT COALESCE(SUM(pp.amount),0)
    FROM purchase_payments pp
    JOIN purchases p ON pp.purchase_id = p.id
    WHERE p.supplier_id = :sid AND p.branch_id = :bid
  ");
  $st->execute([":sid"=>$supplierId, ":bid"=>$branchId]);
  $payments = (float)$st->fetchColumn();

  // Cargo transfers (reduce supplier payable)
  $st = $pdo->prepare("
    SELECT COALESCE(SUM(cs.amount),0)
    FROM cargo_services cs
    WHERE cs.supplier_id = :sid AND cs.branch_id = :bid
  ");
  $st->execute([":sid"=>$supplierId, ":bid"=>$branchId]);
  $cargoTransfers = (float)$st->fetchColumn();

  return max($purch - $returns - $payments - $cargoTransfers, 0.0);
}

// -----------------------------
// Dropdown data
// -----------------------------
$providers = $pdo->query("SELECT id, name FROM cargo_providers WHERE is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$suppliers = [];
try {
  // Restrict suppliers to current branch if branch_id column exists
  $supBranchCheck = $pdo->prepare("SHOW COLUMNS FROM suppliers LIKE 'branch_id'");
  $supBranchCheck->execute();
  $hasSupBranch = (bool)$supBranchCheck->fetch();
  if ($hasSupBranch) {
    $stSup = $pdo->prepare("SELECT id, name FROM suppliers WHERE branch_id = ? ORDER BY name");
    $stSup->execute([$branchId]);
    $suppliers = $stSup->fetchAll(PDO::FETCH_ASSOC);
  } else {
    $suppliers = $pdo->query("SELECT id, name FROM suppliers ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
  }
} catch (Throwable $e) {
  $suppliers = [];
}

// -----------------------------
// Provider balances (from view)
// v_cargo_provider_balance must sum cargo_services.amount - cargo_payments.amount per branch/provider
// -----------------------------
$providerBalances = [];
try {
  $bal = $pdo->prepare("SELECT cargo_provider_id, branch_id, balance FROM v_cargo_provider_balance WHERE branch_id=:bid");
  $bal->execute([":bid"=>$branchId]);
  foreach($bal->fetchAll(PDO::FETCH_ASSOC) as $b){
    $providerBalances[(int)$b['cargo_provider_id']] = (float)$b['balance'];
  }
} catch (Throwable $e) {
  // If the view is missing, fallback to 0 balances so UI still works
  $providerBalances = [];
}

// -----------------------------
// Precompute supplier payable map (shows in New Transfer modal)
// -----------------------------
$supplierPayableMap = [];
foreach ($suppliers as $s) {
  $supplierPayableMap[(int)$s['id']] = supplier_payable_now($pdo, (int)$s['id'], $branchId);
}

// -----------------------------
// Filters & list
// -----------------------------
$where = ["cs.branch_id = :bid"];
$params = [":bid"=>$branchId];
if (!empty($_GET['provider_id'])) { $where[] = "cs.cargo_provider_id = :pid"; $params[':pid'] = (int)$_GET['provider_id']; }
if (!empty($_GET['from']))        { $where[] = "DATE(cs.transfer_date) >= :from"; $params[':from'] = $_GET['from']; }
if (!empty($_GET['to']))          { $where[] = "DATE(cs.transfer_date) <= :to";   $params[':to']   = $_GET['to']; }

$sql = "SELECT cs.*, s.name AS supplier_name, p.name AS provider_name
        FROM cargo_services cs
        JOIN suppliers s ON s.id = cs.supplier_id
        JOIN cargo_providers p ON p.id = cs.cargo_provider_id
        WHERE ".implode(" AND ", $where)."
        ORDER BY cs.transfer_date DESC, cs.id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Flash
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Cargo Services</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body{background:#f6f7fb}
    .page-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem}
    .page-head h3{margin:0}
    .badge-ghost{background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;padding:6px 10px;color:#374151}
    .small-muted{font-size:.825rem;color:#6b7280}
    .table td, .table th { vertical-align: middle; }
    .hint{font-size:.85rem;color:#6b7280}
    .method-extra{display:none}
  </style>
</head>
<body>
<?php include 'includes/nav.php'; ?>

<div class="container my-4">
  <div class="page-head">
    <div>
      <h3 class="mb-0">Cargo Services</h3>
      <div class="small-muted">Transfer supplier payable to cargo providers, and settle payments safely.</div>
    </div>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalTransfer">New Transfer</button>
  </div>

  <?php if ($flash): ?>
    <div class="alert alert-<?= htmlspecialchars($flash['type']) ?>"><?= htmlspecialchars($flash['msg']) ?></div>
  <?php endif; ?>

  <div class="card mb-3">
    <div class="card-body">
      <form class="row g-2" method="get">
        <div class="col-md-3">
          <label class="form-label">Provider</label>
          <select name="provider_id" class="form-select">
            <option value="">All</option>
            <?php foreach($providers as $p): ?>
              <option value="<?= (int)$p['id'] ?>" <?= (($_GET['provider_id'] ?? '')==(string)$p['id']?'selected':'') ?>>
                <?= htmlspecialchars($p['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">From</label>
          <input type="date" name="from" class="form-control" value="<?= htmlspecialchars($_GET['from'] ?? '') ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">To</label>
          <input type="date" name="to" class="form-control" value="<?= htmlspecialchars($_GET['to'] ?? '') ?>">
        </div>
        <div class="col-md-3 d-flex align-items-end">
          <button class="btn btn-secondary me-2">Filter</button>
          <a href="cargo_service.php" class="btn btn-outline-secondary">Reset</a>
        </div>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="card-body table-responsive">
      <table class="table table-sm table-striped align-middle">
        <thead>
          <tr>
            <th>#</th>
            <th>Date</th>
            <th>Supplier</th>
            <th>Provider</th>
            <th>Reference</th>
            <th class="text-end">Amount</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($rows as $r):
                $pid = (int)$r['cargo_provider_id'];
                $provBal = (float)($providerBalances[$pid] ?? 0);
                $canPay = $provBal > 0.0001;
          ?>
            <tr>
              <td><?= (int)$r['id'] ?></td>
              <td><?= htmlspecialchars($r['transfer_date']) ?></td>
              <td><?= htmlspecialchars($r['supplier_name']) ?></td>
              <td>
                <?= htmlspecialchars($r['provider_name']) ?>
                <div class="small <?= $canPay ? 'text-danger' : 'text-muted' ?>">
                  Provider Balance: Rs. <?= number_format($provBal,2) ?>
                </div>
              </td>
              <td><?= htmlspecialchars($r['reference_no']) ?></td>
              <td class="text-end"><?= number_format((float)$r['amount'],2) ?></td>
              <td class="text-center">
                <?php if ($canPay): ?>
                  <button
                    type="button"
                    class="btn btn-sm btn-success btn-pay"
                    data-pid="<?= (int)$pid ?>"
                    data-pname="<?= htmlspecialchars($r['provider_name'], ENT_QUOTES) ?>"
                    data-balance="<?= htmlspecialchars(number_format($provBal,2,'.','')) ?>">
                    Pay
                  </button>
                <?php else: ?>
                  <span class="badge bg-secondary">Settled</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($rows)): ?>
            <tr><td colspan="7" class="text-center text-muted">No records</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- New Transfer Modal -->
<div class="modal fade" id="modalTransfer" tabindex="-1">
  <div class="modal-dialog">
    <form class="modal-content" method="post" action="cargo_service_add.php">
      <div class="modal-header">
        <h5 class="modal-title">New Cargo Transfer</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-2">
          <label class="form-label">Supplier</label>
          <select name="supplier_id" id="transfer_supplier_id" class="form-select" required>
            <option value="">-- Select --</option>
            <?php foreach($suppliers as $s):
                  $sid=(int)$s['id']; $spay=(float)($supplierPayableMap[$sid] ?? 0);
            ?>
              <option value="<?= $sid ?>" data-payable="<?= htmlspecialchars(number_format($spay,2,'.','')) ?>">
                <?= htmlspecialchars($s['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <div class="small mt-1">
            Supplier Payable: <strong id="supplier_payable_txt">—</strong>
          </div>
        </div>
        <div class="mb-2">
          <label class="form-label">Provider</label>
          <select name="provider_id" class="form-select" required>
            <?php foreach($providers as $p): $pid=(int)$p['id']; $pb=(float)($providerBalances[$pid] ?? 0); ?>
              <option value="<?= $pid ?>" data-provbal="<?= htmlspecialchars(number_format($pb,2,'.','')) ?>">
                <?= htmlspecialchars($p['name']) ?> (Bal: Rs. <?= number_format($pb,2) ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="row">
          <div class="col-md-6 mb-2">
            <label class="form-label">Reference</label>
            <input type="text" name="reference_no" class="form-control">
          </div>
          <div class="col-md-6 mb-2">
            <label class="form-label">Amount (Rs.)</label>
            <input type="number" step="0.01" min="0.01" name="amount" id="transfer_amount" class="form-control" required>
            <div class="form-text">Max = supplier payable</div>
          </div>
        </div>
        <div class="mb-2">
          <label class="form-label">Remarks</label>
          <input type="text" name="description" class="form-control" placeholder="Optional">
        </div>
      </div>
      <div class="modal-footer">
        <button type="submit" class="btn btn-primary">Save Transfer</button>
      </div>
    </form>
  </div>
</div>

<!-- Pay Modal -->
<div class="modal fade" id="modalPay" tabindex="-1">
  <div class="modal-dialog">
    <form class="modal-content" method="post" action="cargo_service_pay.php" id="payForm">
      <div class="modal-header">
        <h5 class="modal-title">Pay Cargo Provider</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="provider_id" id="pay_provider_id">
        <input type="hidden" id="pay_provider_balance_val" value="0">

        <div class="mb-2">
          <label class="form-label">Provider</label>
          <input type="text" class="form-control" id="pay_provider_name" disabled>
        </div>

        <div class="mb-2 d-flex justify-content-between">
          <label class="form-label mb-0">Current Provider Balance</label>
          <span id="pay_provider_balance" class="fw-bold">Rs. 0.00</span>
        </div>

        <div class="row">
          <div class="col-md-6 mb-2">
            <label class="form-label">Amount (Rs.)</label>
            <input type="number" step="0.01" min="0.01" name="amount" id="pay_amount" class="form-control" required>
            <div class="hint">Cannot exceed current balance</div>
          </div>
          <div class="col-md-6 mb-2">
            <label class="form-label">Method</label>
            <select name="method" id="pay_method" class="form-select" required>
              <option value="cash">Cash</option>
              <option value="bank">Bank</option>
              <option value="card">Card</option>
              <option value="cheque">Cheque</option>
            </select>
          </div>
        </div>

        <!-- Bank / Card extra -->
        <div id="method_bank_card" class="method-extra">
          <div class="row">
            <div class="col-md-12 mb-2">
              <label class="form-label">Bank Account (if bank/card)</label>
              <select name="bank_account_id" id="pay_bank_account" class="form-select">
                <option value="">-- Select --</option>
                <?php
                  $banks = $pdo->prepare("SELECT id, name, account_no FROM bank_accounts WHERE branch_id=:b OR branch_id IS NULL ORDER BY name");
                  $banks->execute([":b"=>$branchId]);
                  foreach($banks->fetchAll(PDO::FETCH_ASSOC) as $bk){
                    echo '<option value="'.$bk['id'].'">'.htmlspecialchars($bk['name'].' ('.$bk['account_no'].')').'</option>';
                  }
                ?>
              </select>
            </div>
            <div class="col-md-12 mb-2">
              <label class="form-label">Transaction Ref (optional)</label>
              <input type="text" name="txn_ref" id="pay_txn_ref" class="form-control" placeholder="Slip / TXN ID">
            </div>
          </div>
        </div>

        <!-- Cheque extra (UI only; details saved into notes since table has only cheque_no) -->
        <div id="method_cheque" class="method-extra">
          <div class="row">
            <div class="col-md-6 mb-2">
              <label class="form-label">Cheque Number</label>
              <input type="text" name="cheque_no" id="pay_cheque_no" class="form-control">
            </div>
            <div class="col-md-6 mb-2">
              <label class="form-label">Deposit Date</label>
              <input type="date" name="deposit_date" id="pay_deposit_date" class="form-control">
            </div>
            <div class="col-md-6 mb-2">
              <label class="form-label">Bank Name</label>
              <input type="text" name="bank_name" id="pay_bank_name" class="form-control">
            </div>
            <div class="col-md-6 mb-2">
              <label class="form-label">Bank Branch</label>
              <input type="text" name="bank_branch" id="pay_bank_branch" class="form-control">
            </div>
          </div>
        </div>

        <div class="mb-2">
          <label class="form-label">Notes</label>
          <input type="text" name="notes" id="pay_notes" class="form-control" placeholder="Optional">
        </div>

        <div class="mb-2">
          <label class="form-label">Payment Date</label>
          <input type="date" name="paid_at" id="pay_paid_at" class="form-control" value="<?= htmlspecialchars(date('Y-m-d')) ?>">
        </div>
      </div>
      <div class="modal-footer">
        <button type="submit" id="pay_submit_btn" class="btn btn-success">Pay</button>
      </div>
    </form>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Supplier chosen → show payable & cap amount for transfer modal
document.addEventListener('change', function(e){
  if (e.target && e.target.id === 'transfer_supplier_id') {
    const opt = e.target.options[e.target.selectedIndex];
    let payable = 0;
    if (opt && opt.dataset.payable) payable = parseFloat(opt.dataset.payable || '0');
    const txt = document.getElementById('supplier_payable_txt');
    if (txt) txt.innerText = payable ? ('Rs. ' + payable.toFixed(2)) : '—';
    const amt = document.getElementById('transfer_amount');
    if (amt) { amt.max = payable > 0 ? payable : ''; if (payable > 0 && !amt.value) amt.value = payable.toFixed(2); }
  }
});

// Delegated click so Pay button always works
document.addEventListener('click', function (e) {
  const btn = e.target.closest('.btn-pay');
  if (!btn) return;
  const pid = parseInt(btn.dataset.pid || '0', 10);
  const pname = btn.dataset.pname || '';
  const balStr = btn.dataset.balance || '0';
  openPayModal(pid, pname, balStr);
});

function openPayModal(pid, pname, provBalanceStr){
  const bal = parseFloat(provBalanceStr || '0') || 0;
  document.getElementById('pay_provider_id').value = pid;
  document.getElementById('pay_provider_name').value = pname;
  document.getElementById('pay_provider_balance').innerText = 'Rs. ' + bal.toFixed(2);
  document.getElementById('pay_provider_balance_val').value = bal.toFixed(2);

  const amt = document.getElementById('pay_amount');
  const btn = document.getElementById('pay_submit_btn');
  amt.value = bal > 0 ? bal.toFixed(2) : '';
  amt.max = bal > 0 ? bal : '';
  amt.disabled = bal <= 0.0001;
  btn.disabled = bal <= 0.0001;

  // reset method extras to cash
  const method = document.getElementById('pay_method');
  if (method) { method.value = 'cash'; toggleMethodExtras('cash'); }

  new bootstrap.Modal(document.getElementById('modalPay')).show();
}

// Toggle method-specific fields + required flags
const payMethodSel = document.getElementById('pay_method');
if (payMethodSel) payMethodSel.addEventListener('change', function(){ toggleMethodExtras(this.value); });

function toggleMethodExtras(method){
  const bankCard = document.getElementById('method_bank_card');
  const cheque   = document.getElementById('method_cheque');
  if (bankCard) bankCard.style.display = (method === 'bank' || method === 'card') ? 'block' : 'none';
  if (cheque)   cheque.style.display   = (method === 'cheque') ? 'block' : 'none';

  const bankAccount = document.getElementById('pay_bank_account');
  const chqNo = document.getElementById('pay_cheque_no');

  if (method === 'bank' || method === 'card') {
    if (bankAccount) bankAccount.required = true;
    if (chqNo) chqNo.required = false;
  } else if (method === 'cheque') {
    if (bankAccount) bankAccount.required = false;
    if (chqNo) chqNo.required = true;
  } else { // cash
    if (bankAccount) bankAccount.required = false;
    if (chqNo) chqNo.required = false;
  }
}

// Client-side validation before submit
const payForm = document.getElementById('payForm');
if (payForm) {
  payForm.addEventListener('submit', function(ev){
    const amtEl  = document.getElementById('pay_amount');
    const maxBal = parseFloat(document.getElementById('pay_provider_balance_val').value || '0') || 0;
    const amount = parseFloat(amtEl.value || '0') || 0;
    if (amount <= 0.0) {
      ev.preventDefault(); alert('Amount must be greater than 0.'); return;
    }
    if (maxBal > 0 && amount - maxBal > 0.00001) {
      ev.preventDefault(); alert('Amount cannot exceed provider balance.'); amtEl.focus(); return;
    }
  });
}
</script>
</body>
</html>
