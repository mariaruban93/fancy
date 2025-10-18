<?php
// POS page with modern UI

require_once 'includes/header.php';

// Only allow admin, manager, or cashier roles
checkRole(['admin', 'manager', 'cashier']);

// Determine current branch for sales
// Admins can pick a branch via query parameter; for other roles use the user's branch
$branch_id = 0;
if ($user['role_name'] === 'admin') {
    // Use branch from query, session, or user record
    if (isset($_GET['branch_id']) && ctype_digit($_GET['branch_id'])) {
        $branch_id = (int)$_GET['branch_id'];
        // Persist admin's chosen branch in session for subsequent requests
        $_SESSION['sale_branch_id'] = $branch_id;
    } elseif (isset($_SESSION['sale_branch_id'])) {
        $branch_id = (int)$_SESSION['sale_branch_id'];
    } elseif (!empty($user['branch_id'])) {
        $branch_id = (int)$user['branch_id'];
    }
} else {
    // For non-admin roles, always use the user's assigned branch
    $branch_id = (int)($user['branch_id'] ?? 0);
}

// Fetch list of active branches for admins
$branches = [];
$branch_name = '';
try {
    $stmt = $pdo->query("SELECT id, name FROM branches WHERE is_active = 1 ORDER BY name");
    $branches = $stmt->fetchAll();
    foreach ($branches as $b) {
        if ((int)$b['id'] === $branch_id) {
            $branch_name = $b['name'];
        }
    }
} catch (Throwable $e) {
    // leave $branches empty on error
}

// Fallback branch name if not set
if (!$branch_name && !empty($user['branch_name'])) {
    $branch_name = $user['branch_name'];
}

// Cashier/username display
$cashier_name = $user['username'] ?? 'Cashier';

// Fetch categories and brands for filter dropdowns (active only)
$cats = [];
$brands = [];
try {
    $cats = $pdo->query("SELECT name FROM categories WHERE is_active = 1 ORDER BY name")->fetchAll();
    $brands = $pdo->query("SELECT name FROM brands WHERE is_active = 1 ORDER BY name")->fetchAll();
} catch (Throwable $e) {
    // ignore on error
}

// Fetch customers for selection dropdown.  Restrict to the current branch if a branch_id column
// exists on the customers table.  This prevents mixing customers across branches.
$customers = [];
try {
    // Check if branch_id column exists on customers table
    $custBranchCheck = $pdo->prepare("SHOW COLUMNS FROM customers LIKE 'branch_id'");
    $custBranchCheck->execute();
    $custHasBranch = (bool)$custBranchCheck->fetch();
    if ($custHasBranch) {
        // Also fetch phone for live search
        $stmtCust = $pdo->prepare("SELECT id, name, phone FROM customers WHERE branch_id = ? ORDER BY name");
        $stmtCust->execute([$branch_id]);
        $customers = $stmtCust->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // Also fetch phone for live search
        $customers = $pdo->query("SELECT id, name, phone FROM customers ORDER BY name")->fetchAll();
    }
} catch (Throwable $e) {
    // ignore if customers table does not exist yet
    $customers = [];
}

// Fetch workers for selection dropdown based on branch
$workers = [];
try {
    // Only fetch workers for the selected branch
    if ($branch_id > 0) {
        $stmt = $pdo->prepare("SELECT id, name FROM workers WHERE branch_id = ? ORDER BY name");
        $stmt->execute([$branch_id]);
        $workers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    // ignore errors
}
// Fetch bank accounts for selection on bank transfer payments.  Pull accounts for the current
// branch and any global accounts (where branch_id is NULL).  This allows showing only
// relevant banks when a branch is chosen.
$bank_accounts = [];
try {
    $stmt = $pdo->prepare("SELECT id, name, account_no FROM bank_accounts WHERE (branch_id = ? OR branch_id IS NULL) ORDER BY name");
    $stmt->execute([$branch_id]);
    $bank_accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    // ignore if bank_accounts table does not exist
    $bank_accounts = [];
}
// Prepare JSON for front‑end: each bank entry includes id, name and account_no
$bankAccountList = [];
foreach ($bank_accounts as $ba) {
    $bankAccountList[] = [
        'id'         => isset($ba['id']) ? (int)$ba['id'] : 0,
        'name'       => $ba['name'] ?? '',
        'account_no' => $ba['account_no'] ?? ''
    ];
}
$bank_accounts_json = json_encode($bankAccountList, JSON_UNESCAPED_UNICODE);

// Fetch inventory: products with available batches for the selected branch
$products = [];
if ($branch_id > 0) {
    // Note: Our products table does not have a separate code field, so we reuse the product ID as a code for display/search.
    $sql = "SELECT
              p.id            AS product_id,
              p.name          AS product_name,
              p.size          AS product_size,
              pb.cost_pin     AS cost_pin,
              p.id            AS product_code,
              COALESCE(p.barcode, '') AS barcode,
              c.name          AS category_name,
              b.name          AS brand_name,
              pb.id           AS batch_id,
              COALESCE(pb.batch_no, '') AS batch_number,
              pb.expiry_date  AS expiry_date,
              pb.cost_price   AS cost_price,
              pb.selling_price AS selling_price,
              pb.quantity     AS quantity
            FROM stock_batches pb
            INNER JOIN products p ON p.id = pb.product_id
            LEFT JOIN categories c ON c.id = p.category_id
            LEFT JOIN brands b ON b.id = p.brand_id
            WHERE pb.branch_id = :branch AND pb.quantity > 0
            ORDER BY p.name, pb.expiry_date";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['branch' => $branch_id]);
    $rows = $stmt->fetchAll();

    foreach ($rows as $r) {
        $pid = (int)$r['product_id'];
        if (!isset($products[$pid])) {
        $products[$pid] = [
            'id'       => $pid,
            'name'     => $r['product_name'],
            // Include size property for display in POS
            'size'     => $r['product_size'],
            'code'     => $r['product_code'],
            'cost_pin' => $r['cost_pin'],
            'barcode'  => $r['barcode'],
            'category' => $r['category_name'],
            'brand'    => $r['brand_name'],
            'tax'      => 0,
            'price'    => (float)$r['selling_price'],
            'cost'     => (float)$r['cost_price'],
            'batches'  => []
        ];
        }
        $products[$pid]['batches'][] = [
            'id'     => (int)$r['batch_id'],
            'number' => $r['batch_number'] ?: 'N/A',
            'expiry' => $r['expiry_date'] ?: null,
            'stock'  => (float)$r['quantity'],
            'price'  => (float)$r['selling_price'],
            'cost'   => (float)$r['cost_price']
        ];
    }
}

// Encode products for front-end consumption
$products_json = json_encode(array_values($products), JSON_UNESCAPED_UNICODE);

// Prepare customers JSON for front-end live search
$customerListForJS = [];
foreach ($customers as $c) {
    // Each entry includes id, name, and phone (string or empty)
    $customerListForJS[] = [
        'id'    => isset($c['id']) ? (int)$c['id'] : 0,
        'name'  => $c['name'] ?? '',
        'phone' => $c['phone'] ?? ''
    ];
}
$customers_json = json_encode($customerListForJS, JSON_UNESCAPED_UNICODE);

// Generate invoice number

$invoice_number = 'INV-' . date('Ymd') . '-' . strtoupper(substr(hash('sha256', microtime(true)), 0, 4));

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>POS System</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
  body{background:#f1f3f6;font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,'Helvetica Neue',Arial;}
  .topbar{background:linear-gradient(135deg,#0a3d91,#1a56db);color:#fff;padding:12px 20px;}
  .card-box{background:#fff;border:1px solid #eaeaea;border-radius:10px;box-shadow:0 3px 10px rgba(0,0,0,.08);padding:20px;margin-bottom:20px;}
  .product-card{border:1px solid #e0e0e0;border-radius:10px;text-align:center;padding:4px;background:#f9fdf9;cursor:pointer;transition:.2s;height:100%}
  .product-card:hover{background:#e8f5e8;transform:translateY(-2px);box-shadow:0 6px 16px rgba(0,0,0,.08)}
  .product-image{width:60px;height:60px;object-fit:contain;margin:0 auto 10px;background:#f8f9fa;padding:5px;border-radius:8px}
  .category-badge{position:absolute;top:8px;right:8px;font-size:.7rem}
  .cart-table input{width:70px;text-align:center}
  .footer-bar{background:#fff;padding:15px;border:1px solid #eaeaea;border-radius:10px;margin-top:20px;box-shadow:0 3px 10px rgba(0,0,0,.08)}
  .btn-hold{background:#d63384;color:#fff}.btn-multiple{background:#0d6efd;color:#fff}
  .btn-cash{background:#198754;color:#fff}.btn-payall{background:#212529;color:#fff}
  .editable-price,.editable-discount{cursor:pointer;border-bottom:1px dashed #0d6efd}
  .discount-badge{background:#28a745;color:#fff;padding:2px 5px;border-radius:3px;font-size:12px}
  .search-container{position:relative}
  .search-results{position:absolute;top:100%;left:0;right:0;background:#fff;border:1px solid #ddd;border-top:none;border-radius:0 0 5px 5px;max-height:300px;overflow-y:auto;z-index:1000;box-shadow:0 4px 10px rgba(0,0,0,.1);display:none}
  .search-item:hover{background:#f5f5f5}
  .scanner-active{border:2px solid #28a745!important;box-shadow:0 0 0 .2rem rgba(40,167,69,.25)}
  .scanner-on{background:#28a745;color:#fff;border-radius:20px;padding:2px 8px;font-size:12px}
  .scanner-off{background:#6c757d;color:#fff;border-radius:20px;padding:2px 8px;font-size:12px}
  .invoice-modal .modal-dialog{max-width:800px}
  .small-muted{font-size:.9rem;color:#6c757d}
</style>
</head>
<body>

<?php
// Include the main navigation bar so that POS page shares the same nav as other pages
include 'includes/nav.php';
?>
<!-- Navigation bar is included from nav.php, so the separate topbar is removed -->

<div class="container-fluid mt-4">
  <div class="row">
    <!-- LEFT: CART -->
    <div class="col-lg-7">
      <div class="card-box">
        <div class="row mb-3">
          <!-- Branch selection -->
          <div class="col-md-4">
            <?php if ($user['role_name'] === 'admin' && !empty($branches)): ?>
              <form method="get" class="d-flex gap-2">
                <select class="form-select" name="branch_id" onchange="this.form.submit()">
                  <?php foreach ($branches as $b): ?>
                    <option value="<?= (int)$b['id'] ?>" <?= ((int)$b['id'] === $branch_id ? 'selected' : '') ?>>
                      <?= htmlspecialchars($b['name']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </form>
            <?php else: ?>
              <select class="form-select" disabled>
                <option><?= htmlspecialchars($branch_name) ?></option>
              </select>
            <?php endif; ?>
          </div>
          <!-- Customer search (live) -->
          <div class="col-md-4 position-relative">
            <input type="text" class="form-control" id="customerSearch" placeholder="Search customer by name or phone" autocomplete="off">
            <!-- Results will appear here dynamically -->
            <div class="search-results" id="customerSearchResults" style="position:absolute; top:100%; left:0; right:0; z-index:1000;"></div>
            <!-- Hidden select remains for compatibility with existing JS logic -->
            <select id="customer_id" style="display:none;">
              <option value="">Walk-in Customer</option>
              <?php foreach ($customers as $c): ?>
                <option value="<?= (int)$c['id'] ?>" <?= ((int)$c['id'] === (int)($_GET['customer_id'] ?? 0) ? 'selected' : '') ?>>
                  <?= htmlspecialchars($c['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <!-- Worker selection (replaces free-form worker number) -->
          <div class="col-md-4">
            <select class="form-select" id="worker_number">
              <option value="">Select Worker</option>
              <?php foreach ($workers as $w): ?>
                <option value="<?= (int)$w['id'] ?>"><?= htmlspecialchars($w['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <!-- Customer due status row -->
        <div class="row mb-2">
          <div class="col-md-12 text-center">
            <span id="customerDueStatus" class="fw-bold"></span>
          </div>
        </div>

        <!-- Exchange credit row -->
        <div class="row mb-2" id="exchangeCreditRow" style="display:none;">
          <div class="col-md-12 text-center">
            <span class="fw-bold text-info">Exchange Credit: <span id="exchangeCreditAmount">Rs. 0.00</span></span>
          </div>
        </div>

        <div class="row mb-3">
          <div class="col-md-10 search-container">
            <input type="text" class="form-control" id="productSearch" placeholder="Search by name, code, or barcode" autofocus>
            <div class="search-results" id="searchResults"></div>
          </div>
          <div class="col-md-2 d-grid">
            <button class="btn btn-outline-primary position-relative" id="toggleScanner">
              <i class="bi bi-upc-scan"></i> <span id="scannerStatus" class="scanner-off">Scan</span>
              <span class="position-absolute top-0 end-0 translate-middle badge rounded-pill bg-danger" id="cartCount">0</span>
            </button>
          </div>
        </div>

        <p class="text-danger" id="customerDueInfo" style="display:none;">Previous Due: <span id="previousDueAmount">Rs. 0.00</span></p>

        <div class="table-responsive">
          <table class="table table-bordered cart-table">
            <thead class="table-light">
              <tr>
                <th>Item Name</th>
                <th>Batch</th>
                <th>Qty</th>
                <th>Price</th>
                <th>Discount</th>
                <th>Subtotal</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody id="cartItems">
              <tr><td colspan="7" class="text-center py-3">No items added</td></tr>
            </tbody>
          </table>
        </div>
      </div>

      <div class="footer-bar">
        <div class="container">
        <div class="row mb-3">
            <div class="col-md-3">Quantity:<br><strong id="totalQty">0</strong></div>
            <div class="col-md-3">Total Amount:<br><strong id="subtotal">Rs. 0.00</strong></div>
            <div class="col-md-3">Line Discount:<br><strong id="discountAmount">Rs. 0.00</strong></div>
            <div class="col-md-3">Grand Total:<br><strong id="grandTotal">Rs. 0.00</strong></div>
          </div>
          <div class="row mb-3 align-items-end">
            <div class="col-md-3">
              Overall Discount:<br>
              <input type="number" min="0" step="0.01" class="form-control" id="overallDiscount" value="0">
            </div>
            <div class="col-md-3">&nbsp;</div>
            <div class="col-md-3">
              <span class="small text-muted">Applied:</span><br>
              <strong id="overallDiscountDisplay">Rs. 0.00</strong>
            </div>
            <div class="col-md-3">
              Net Total:<br><strong id="netTotal">Rs. 0.00</strong>
            </div>
          </div>
          <div class="row g-2">
            <div class="col-md-3"><button class="btn btn-hold w-100" id="holdSale"><i class="fas fa-pause me-1"></i> Hold</button></div>
            <div class="col-md-3"><button class="btn btn-multiple w-100" id="multiplePayment">Multiple</button></div>
            <div class="col-md-3"><button class="btn btn-cash w-100" id="completeSale"><i class="fas fa-check-circle me-1"></i> Cash</button></div>
            <div class="col-md-3">
              <button class="btn btn-primary w-100" id="btnReturnMerge" data-bs-toggle="modal" data-bs-target="#returnMergeModal">
                Return + New Bill
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- RIGHT: PRODUCT GRID -->
    <div class="col-lg-5">
      <div class="card-box">
        <div class="row mb-3">
          <div class="col-md-6">
            <select class="form-select" id="categoryFilter">
              <option value="">All Categories</option>
              <?php foreach ($cats as $cat): ?>
                <option value="<?= htmlspecialchars($cat['name']) ?>"><?= htmlspecialchars($cat['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <select class="form-select" id="brandFilter">
              <option value="">All Brands</option>
              <?php foreach ($brands as $br): ?>
                <option value="<?= htmlspecialchars($br['name']) ?>"><?= htmlspecialchars($br['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="row g-3" id="productGrid"></div>
      </div>
    </div>
  </div>
</div>

<!-- BATCH MODAL -->
<div class="modal fade" id="batchModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-md"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Select Batch</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
      <p>Multiple batches available for <strong id="batchProductName"></strong>. Please select one:</p>
      <div id="batchOptions"></div>
    </div>
  </div></div>
</div>

<!-- EDIT PRICE MODAL -->
<div class="modal fade" id="editPriceModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Edit Price</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
      <div class="mb-2">
        <label class="form-label">Product</label>
        <input type="text" class="form-control" id="editPriceProductName" readonly>
      </div>
      <div class="mb-2">
        <label class="form-label">Cost Pin</label>
        <!-- show cost pin but do not allow editing -->
        <input type="text" class="form-control" id="editPriceCostPin" readonly>
      </div>
      <div class="mb-2">
        <label class="form-label">Selling Price</label>
        <input type="number" class="form-control" id="editPriceSelling" min="0" step="0.01">
        <div class="form-text text-danger" id="priceError" style="display:none;">Price cannot be below cost price!</div>
      </div>
    </div>
    <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" id="savePrice">Save Changes</button></div>
  </div></div>
</div>

<!-- DISCOUNT MODAL -->
<div class="modal fade" id="editDiscountModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Apply Discount</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
      <div class="mb-2"><label class="form-label">Product</label><input type="text" class="form-control" id="editDiscountProductName" readonly></div>
      <div class="mb-2"><label class="form-label">Current Price</label><input type="text" class="form-control" id="editDiscountPrice" readonly></div>
      <div class="mb-2">
        <label class="form-label">Discount Type</label>
        <select class="form-select" id="discountType"><option value="percentage">Percentage (%)</option><option value="fixed">Fixed Amount</option></select>
      </div>
      <div class="mb-2"><label class="form-label">Discount Value</label><input type="number" class="form-control" id="discountValue" min="0" step="0.01"></div>
      <div class="form-text text-danger" id="discountError" style="display:none;">Discount makes net price fall below cost price!</div>
      <div class="mb-2"><label class="form-label">Discount Amount</label><input type="text" class="form-control" id="discountAmountPreview" readonly></div>
      <div class="mb-2"><label class="form-label">Final Price</label><input type="text" class="form-control" id="discountFinalPrice" readonly></div>
    </div>
    <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" id="saveDiscount">Apply Discount</button></div>
  </div></div>
</div>

<!-- HOLD SALE MODAL -->
<div class="modal fade" id="holdSaleModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Hold Sale</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
      <div class="mb-2"><label class="form-label">Reference Note (optional)</label><input type="text" class="form-control" id="holdReference"></div>
      <div class="mb-2"><label class="form-label">Total Amount</label><input type="text" class="form-control" id="holdTotalAmount" readonly></div>
    </div>
    <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" id="confirmHoldSale">Hold Sale</button></div>
  </div></div>
</div>

<!-- RETRIEVE HELD SALES MODAL -->
<div class="modal fade" id="retrieveHeldModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Retrieve Held Sale</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
      <div class="table-responsive">
        <table class="table table-hover">
          <thead class="table-light">
            <tr><th>Date & Time</th><th>Reference</th><th>Items</th><th>Total Amount</th><th>Action</th></tr>
          </thead>
          <tbody id="heldSalesList"></tbody>
        </table>
      </div>
    </div>
  </div></div>
</div>

<!-- PAYMENT MODAL -->
<div class="modal fade" id="paymentModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Complete Payment</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
      <div class="mb-2"><label class="form-label">Grand Total</label><input type="text" class="form-control" id="paymentGrandTotal" readonly></div>
      <!-- Display applied exchange credit when present -->
      <div class="mb-2" id="creditInfo" style="display:none;">
        <label class="form-label">Exchange Credit Applied</label>
        <input type="text" class="form-control" id="creditAppliedAmount" readonly>
      </div>
      <div id="singlePaymentSection">
        <div class="mb-2"><label class="form-label">Payment Method</label>
          <select class="form-select" id="paymentMethod">
            <option value="cash">Cash</option>
            <option value="credit">Credit</option>
            <option value="card">Card</option>
            <option value="upi">UPI</option>
            <option value="bank_transfer">Bank Transfer</option>
            <option value="cheque">Cheque</option>
          </select>
        </div>
        <div class="mb-2"><label class="form-label">Amount Received</label><input type="number" class="form-control" id="amountReceived" min="0" step="0.01"></div>

        <!-- SHOW CARD COMMISSION BREAKDOWN (single payment) -->
        <div id="singleCardCommissionWrap" class="rounded border p-2 small-muted" style="display:none;">
          <div><strong>Card Commission (2.5%):</strong> <span id="singleCardCommission">Rs. 0.00</span></div>
          <div><strong>Net to Bank:</strong> <span id="singleCardBankNet">Rs. 0.00</span></div>
        </div>

        <div class="mb-2">
          <label class="form-label" id="changeLabel">Change/Due</label>
          <input type="text" class="form-control" id="changeAmount" readonly>
        </div>

        <!-- Cheque details (single) -->
        <div id="chequeDetailsSingle" style="display:none;">
          <div class="mb-2">
            <label class="form-label">Cheque Number</label>
            <input type="text" class="form-control" id="chequeNumber">
          </div>
          <div class="mb-2">
            <label class="form-label">Bank Name</label>
            <input type="text" class="form-control" id="chequeBankName">
          </div>
          <div class="mb-2">
            <label class="form-label">Bank Branch</label>
            <input type="text" class="form-control" id="chequeBankBranch">
          </div>
          <div class="mb-2" style="display:none;">
            <label class="form-label">Deposit Date</label>
            <input type="date" class="form-control" id="chequeDepositDate">
          </div>
          <!-- NEW Transfer Date input for single cheque -->
          <div class="mb-2">
            <label class="form-label">Transfer Date</label>
            <input type="date" class="form-control" id="chequeTransferDate">
          </div>
        </div>
        <!-- Bank transfer details (single) -->
        <div id="bankTransferDetailsSingle" style="display:none;">
          <div class="mb-2">
            <label class="form-label">Select Bank</label>
            <select class="form-select" id="bankTransferAccount"></select>
            <div class="form-text text-danger" id="noBankAccountsMsgSingle" style="display:none;">No bank accounts configured for this branch.</div>
          </div>
        </div>
      </div>

      <div id="multiplePaymentSection" style="display:none;">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <h6 class="m-0">Payment Methods</h6>
          <button class="btn btn-sm btn-outline-primary" id="addPaymentMethod"><i class="fas fa-plus"></i> Add Method</button>
        </div>
        <div id="paymentMethodsContainer"></div>

        <!-- CARD COMMISSION SUMMARY (multiple) -->
        <div id="multiCardSummaryWrap" class="rounded border p-2 small-muted" style="display:none;">
          <div class="fw-bold mb-1">Card Commission Summary</div>
          <div>Total Card Amount: <span id="multiCardTotal">Rs. 0.00</span></div>
          <div>Commission (2.5%): <span id="multiCardCommission">Rs. 0.00</span></div>
          <div>Net to Bank: <span id="multiCardBankNet">Rs. 0.00</span></div>
        </div>

        <div class="row mt-2">
          <div class="col-6"><strong>Total Paid:</strong></div><div class="col-6 text-end"><strong id="multipleTotalPaid">Rs. 0.00</strong></div>
        </div>
        <div class="row">
          <div class="col-6"><strong id="multipleBalanceLabel">Due/Change:</strong></div><div class="col-6 text-end"><strong id="multipleBalance">Rs. 0.00</strong></div>
        </div>
      </div>
    </div>
    <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" id="finalizePayment">Complete Sale</button></div>
  </div></div>
</div>

<!-- INVOICE MODAL -->
<div class="modal fade invoice-modal" id="invoiceModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Sale Completed Successfully</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
      <div class="mb-3" style="border-bottom:2px solid #dee2e6;padding-bottom:12px">
        <div class="row">
          <div class="col-md-6">
            <h4>INVOICE</h4>
            <p><strong>Invoice #:</strong> <span id="invoice-number"><?= htmlspecialchars($invoice_number) ?></span></p>
            <p><strong>Date:</strong> <span id="invoice-date"><?= date('d-M-Y H:i') ?></span></p>
          </div>
          <div class="col-md-6 text-end">
            <p><strong>Branch:</strong> <?= htmlspecialchars($branch_name) ?></p>
            <p><strong>Cashier:</strong> <?= htmlspecialchars($cashier_name) ?></p>
          </div>
        </div>
      </div>
      <div class="row mb-2">
        <div class="col-md-6"><p><strong>Customer:</strong> <span id="invoice-customer">Walk-in Customer</span></p></div>
        <div class="col-md-6 text-end"><p><strong>Payment Method:</strong> <span id="invoice-payment-method">Cash</span></p></div>
      </div>
      <div class="table-responsive">
        <table class="table table-bordered invoice-table">
          <thead class="table-light"><tr><th>Item</th><th class="text-center">Qty</th><th class="text-end">Price</th><th class="text-end">Discount</th><th class="text-end">Total</th></tr></thead>
          <tbody id="invoice-items"></tbody>
          <tfoot>
            <tr><td colspan="4" class="text-end"><strong>Subtotal:</strong></td><td class="text-end" id="invoice-subtotal">Rs. 0.00</td></tr>
            <tr><td colspan="4" class="text-end"><strong>Line Discount:</strong></td><td class="text-end" id="invoice-discount">Rs. 0.00</td></tr>
            <tr><td colspan="4" class="text-end"><strong>Overall Discount:</strong></td><td class="text-end" id="invoice-overall-discount">Rs. 0.00</td></tr>
            <tr><td colspan="4" class="text-end"><strong>Tax:</strong></td><td class="text-end" id="invoice-tax">Rs. 0.00</td></tr>
            <tr><td colspan="4" class="text-end"><strong>Grand Total:</strong></td><td class="text-end" id="invoice-grandtotal">Rs. 0.00</td></tr>
            <tr><td colspan="4" class="text-end"><strong>Amount Paid:</strong></td><td class="text-end" id="invoice-paid">Rs. 0.00</td></tr>
            <tr><td colspan="4" class="text-end"><strong>Change:</strong></td><td class="text-end" id="invoice-change">Rs. 0.00</td></tr>
          </tfoot>
        </table>
      </div>
      <div id="invoice-payment-breakdown" style="display:none;">
        <h6>Payment Breakdown:</h6>
        <table class="table table-sm">
          <thead><tr><th>Method</th><th class="text-end">Amount</th></tr></thead>
          <tbody id="invoice-payment-methods"></tbody>
        </table>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" id="newSale" data-bs-dismiss="modal">New Sale</button>
      <a class="btn btn-primary" id="thermalPrintBtn" href="#" target="_blank">Thermal</a>
      <button class="btn btn-primary" id="printInvoice">Print</button>
    </div>
  </div></div>
</div>

<!-- RETURN MERGE MODAL (unchanged except totals sync uses calcTotals) -->
<div class="modal fade" id="returnMergeModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h5 class="modal-title">Merge a Return with This New Bill</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="row g-2 mb-2">
          <div class="col-md-5">
            <label class="form-label mb-1">Return Bill ID</label>
            <div class="input-group">
              <input type="text" id="rm_return_id" class="form-control" placeholder="e.g. 123">
              <button class="btn btn-outline-secondary" id="rm_load" type="button">Load</button>
            </div>
            <div class="form-text">This is the <strong>Return</strong> number already created on the sales return page.</div>
          </div>
          <div class="col-md-3">
            <label class="form-label mb-1">4-Digit Code</label>
            <input type="password" maxlength="4" id="rm_pin" class="form-control" placeholder="****">
            <div class="form-text">Confirmation code required to finalize merge.</div>
          </div>
          <div class="col-md-4">
            <label class="form-label mb-1">Cart Total (this bill)</label>
            <input type="text" id="rm_new_total" class="form-control" readonly>
          </div>
        </div>
        <div id="rm_feedback" class="alert alert-warning d-none"></div>
        <div class="row g-3">
          <div class="col-md-6">
            <div class="border rounded p-2 h-100">
              <div class="d-flex justify-between align-items-center">
                <h6 class="mb-0">Return Summary</h6>
                <span class="badge text-bg-secondary" id="rm_return_total_badge">Rs. 0.00</span>
              </div>
              <div class="table-responsive mt-2" style="max-height:220px;">
                <table class="table table-sm table-striped">
                  <thead><tr><th>Item</th><th class="text-center">Qty</th><th class="text-end">Rate</th><th class="text-end">Subtotal</th></tr></thead>
                  <tbody id="rm_return_rows"><tr><td colspan="4" class="text-muted">Load a Return Bill…</td></tr></tbody>
                </table>
              </div>
              <div class="small text-muted">Returned stock is already in inventory; we only merge its value with the new sale.</div>
            </div>
          </div>
          <div class="col-md-6">
            <div class="border rounded p-2 h-100">
              <h6>Merge Result</h6>
              <div class="d-flex justify-content-between">
                <div>New Bill Total</div>
                <div id="rm_new_total_lbl">Rs. 0.00</div>
              </div>
              <div class="d-flex justify-content-between">
                <div>Return Total</div>
                <div id="rm_return_total_lbl">Rs. 0.00</div>
              </div>
              <hr class="my-2">
              <div class="d-flex justify-content-between fw-bold">
                <div>Balance</div>
                <div id="rm_balance_lbl">Rs. 0.00</div>
              </div>
              <div class="mt-2 small" id="rm_balance_hint">If positive: collect; if negative: refund to customer.</div>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer py-2">
        <button class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-success" id="rm_finalize" type="button">Finalize &amp; Print</button>
      </div>
    </div>
  </div>
</div>

<!-- QUANTITY MODAL -->
<div class="modal fade" id="qtyModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Select Quantity</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body">
      <p id="qtyProductName" class="fw-bold"></p>
      <div class="mb-3">
        <label class="form-label">Quantity</label>
        <input type="number" class="form-control" id="qtyInput" min="1" value="1">
        <div class="text-danger mt-1" id="qtyError" style="display:none;">Quantity exceeds available stock</div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
      <button class="btn btn-primary" id="qtyConfirm">Add</button>
    </div>
  </div></div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  // Data from PHP
  const products = <?= $products_json ?: '[]' ?>;
  // List of customers with id, name and phone for live search
  const customersData = <?= $customers_json ?: '[]' ?>;

  // List of bank accounts for bank transfer payments.  Each entry has id, name and account_no.
  const bankAccounts = <?= $bank_accounts_json ?: '[]' ?>;

  // Helper to populate a bank select element with options from bankAccounts.
  // Returns true if at least one bank exists, false otherwise.
  function populateBankSelect(selectEl) {
    if (!selectEl) return false;
    selectEl.innerHTML = '';
    if (!Array.isArray(bankAccounts) || bankAccounts.length === 0) {
      return false;
    }
    bankAccounts.forEach(acc => {
      const opt = document.createElement('option');
      // Use bank name for the value; combine account number for display clarity
      opt.value = acc.name;
      opt.textContent = acc.name + (acc.account_no ? ' (' + acc.account_no + ')' : '');
      selectEl.appendChild(opt);
    });
    return true;
  }

  // Populate bank selection for single payment immediately
  (function() {
    const bankSelSingle = document.getElementById('bankTransferAccount');
    const msgSingle     = document.getElementById('noBankAccountsMsgSingle');
    if (bankSelSingle) {
      const ok = populateBankSelect(bankSelSingle);
      if (!ok) {
        bankSelSingle.style.display = 'none';
        if (msgSingle) msgSingle.style.display = 'block';
      } else {
        bankSelSingle.style.display = '';
        if (msgSingle) msgSingle.style.display = 'none';
      }
    }
  })();

  // Pre-populate customer search input if a customer is already selected (e.g. via query string)
  (function() {
    const custSelectInit = document.getElementById('customer_id');
    const custSearchField = document.getElementById('customerSearch');
    if (custSelectInit && custSearchField && custSelectInit.value) {
      const cid = parseInt(custSelectInit.value, 10);
      const found = customersData.find(c => c.id === cid);
      if (found) {
        custSearchField.value = found.name + (found.phone ? ' (' + found.phone + ')' : '');
      }
    }
  })();

  // === CHANGE: make cart global & stable reference ===
  window.cart = window.cart || [];
  const cart = window.cart;

  let scannerActive = false;
  let barcodeBuffer = '';
  let barcodeTimeout = null;
  let isMultiplePayment = false;
  let paymentMethods = [];
  let heldSales = JSON.parse(localStorage.getItem('heldSales') || '[]');
  let currentEditingIndex = null;
  let lastSaleId = null;

  // Store temporary item when asking for quantity
  let pendingAddItem = null;
  // Store exchange credit (from previous exchange returns)
  let exchangeCredit = parseFloat(localStorage.getItem('exchangeCredit') || '0');
  // Amount of exchange credit applied on the current sale
  let creditApplied = 0;

  // CARD COMMISSION RATE (2.5%)
  const CARD_COMMISSION_RATE = 0.025;

  // UTIL
  const byId = (id) => document.getElementById(id);
  const fmt = (n) => 'Rs. ' + (Number(n)||0).toFixed(2);

  // ---------- PRODUCT GRID ----------
  function renderProducts() {
    const category = byId('categoryFilter').value;
    const brand = byId('brandFilter').value;
    const filtered = products.filter(p =>
      (!category || p.category === category) &&
      (!brand || p.brand === brand)
    );
    const grid = byId('productGrid');
    grid.innerHTML = '';
    filtered.forEach(p => {
      const totalStock = (p.batches || []).reduce((s,b)=>s + (Number(b.stock)||0), 0);
      const col = document.createElement('div');
      col.className = 'col-md-4 col-sm-6';
      col.innerHTML = `
        <div class="product-card position-relative" data-product-id="${p.id}">
          <p class="m-0"><strong>${p.name}${p.size ? ' ('+p.size+')' : ''}</strong></p>
          <small class="text-muted">${p.barcode ? 'Barcode: '+p.barcode : ''}</small>
          <p class="m-0">${p.cost_pin}</p>
          <p class="m-0">${fmt(p.price)}</p>
          <p class="m-0">Stock: ${totalStock}</p>
        </div>`;
      grid.appendChild(col);
    });
    grid.querySelectorAll('.product-card').forEach(card => {
      card.addEventListener('click', () => {
        const pid = parseInt(card.getAttribute('data-product-id'));
        const product = products.find(x => x.id === pid);
        if (!product) return;
        handleProductSelection(product);
      });
    });
  }

  function handleProductSelection(product) {
    if (!product.batches || product.batches.length === 0) return;
    if (product.batches.length === 1) {
      const b = product.batches[0];
      const item = {
        product_id: product.id,
        product_name: product.name,
        batch_id: b.id,
        batch_number: b.number,
        // include cost pin so we can show/edit it in the modal
        cost_pin: product.cost_pin || '',
        cost_price: Number(b.cost),
        price: Number(b.price),
        tax_rate: Number(product.tax),
        barcode: product.barcode,
        stock_quantity: Number(b.stock)
      };
      showQtyModal(item);
    } else {
      showBatchSelectionModal(product);
    }
  }

  function showBatchSelectionModal(product) {
    byId('batchProductName').textContent = product.name;
    const wrap = byId('batchOptions');
    wrap.innerHTML = '';
    product.batches.forEach(b => {
      const d = document.createElement('div');
      d.className = 'batch-item p-2 mb-2 border rounded';
      d.setAttribute('data-batch-id', b.id);
      d.setAttribute('data-batch-number', b.number);
      d.setAttribute('data-cost-price', b.cost);
      d.setAttribute('data-price', b.price);
      d.setAttribute('data-stock', b.stock);
      d.innerHTML = `
        <div class="row">
          <div class="col-6"><strong>Batch: ${b.number}</strong></div>
          <div class="col-3">Price: ${fmt(b.price)}</div>
          <div class="col-3">Stock: ${b.stock}</div>
        </div>
        <div class="row"><div class="col-12">${b.expiry ? ('Expiry: ' + b.expiry) : 'No expiry'}</div></div>
      `;
      d.addEventListener('click', () => {
        const selected = {
          product_id: product.id,
          product_name: product.name,
          batch_id: Number(d.getAttribute('data-batch-id')),
          batch_number: d.getAttribute('data-batch-number'),
          // include cost pin from the product
          cost_pin: product.cost_pin || '',
          cost_price: Number(d.getAttribute('data-cost-price')),
          price: Number(d.getAttribute('data-price')),
          tax_rate: Number(product.tax),
          barcode: product.barcode,
          stock_quantity: Number(d.getAttribute('data-stock'))
        };
        showQtyModal(selected);
        bootstrap.Modal.getInstance(byId('batchModal')).hide();
      });
      wrap.appendChild(d);
    });
    new bootstrap.Modal(byId('batchModal')).show();
  }

  function showQtyModal(item) {
    pendingAddItem = item;
    byId('qtyProductName').textContent = item.product_name + (item.batch_number && item.batch_number !== 'N/A' ? ' (Batch: ' + item.batch_number + ')' : '');
    byId('qtyInput').value = 1;
    byId('qtyError').style.display = 'none';
    new bootstrap.Modal(byId('qtyModal')).show();
  }

  // ---------- CART ----------
  function addToCart(item, qty = 1) {
    qty = parseInt(qty, 10) || 1;
    const idx = cart.findIndex(x => x.product_id === item.product_id && x.batch_id === item.batch_id);
    if (idx >= 0) {
      const newQty = cart[idx].quantity + qty;
      if (newQty <= cart[idx].stock_quantity) {
        cart[idx].quantity = newQty;
      } else {
        alert('Cannot add more than available stock');
      }
    } else {
      if (qty > (Number(item.stock_quantity) || 0)) {
        alert('Cannot add more than available stock');
        return;
      }
      cart.push({
        product_id: item.product_id,
        product_name: item.product_name,
        batch_id: item.batch_id,
        batch_number: item.batch_number || 'N/A',
        // store cost pin so it can be edited later
        cost_pin: (item.cost_pin ?? ''),
        cost_price: Number(item.cost_price) || 0,
        price: Number(item.price) || 0,
        quantity: qty,
        discount_type: null,
        discount_value: 0,
        tax_rate: Number(item.tax_rate) || 0,
        stock_quantity: Number(item.stock_quantity) || 0,
        barcode: item.barcode || ''
      });
    }
    updateCartDisplay();
  }

  function calcDiscountAmount(it) {
    if (!it.discount_type || !it.discount_value) return 0;
    const line = it.price * it.quantity;
    if (it.discount_type === 'percentage') return (line * it.discount_value) / 100;
    if (it.discount_type === 'fixed') return Math.min(it.discount_value * it.quantity, line);
    return 0;
  }

  function updateCartDisplay() {
    let html = '';
    let subtotal = 0, discountTotal = 0, taxTotal = 0, totalQty = 0;
    cart.forEach((it, i) => {
      const line = it.price * it.quantity;
      const disc = calcDiscountAmount(it);
      const tax = (line - disc) * (it.tax_rate / 100);
      const total = line - disc + tax;
      subtotal += line; discountTotal += disc; taxTotal += tax; totalQty += it.quantity;

      let discountDisplay = '0.00';
      if (it.discount_type && it.discount_value > 0) {
        discountDisplay = (it.discount_type === 'percentage')
          ? `<span class="discount-badge">${it.discount_value}%</span> ${fmt(disc)}`
          : `<span class="discount-badge">Fixed</span> ${fmt(disc)}`;
      }

      html += `<tr>
        <td>${it.product_name}</td>
        <td>${it.batch_number}</td>
        <td>
          <button class="btn btn-sm btn-danger minus" data-i="${i}">-</button>
          <input class="form-control d-inline-block qty" style="width:60px" data-i="${i}" value="${it.quantity}">
          <button class="btn btn-sm btn-success plus" data-i="${i}">+</button>
        </td>
        <td><span class="editable-price" data-i="${i}">${fmt(it.price)}</span></td>
        <td><span class="editable-discount" data-i="${i}">${discountDisplay}</span></td>
        <td>${fmt(total)}</td>
        <td><button class="btn btn-sm btn-danger rm" data-i="${i}"><i class="bi bi-trash"></i></button></td>
      </tr>`;
    });

    byId('cartItems').innerHTML = cart.length ? html : '<tr><td colspan="7" class="text-center py-3">No items added</td></tr>';

    const baseGrand = subtotal - discountTotal + taxTotal;
    const overallInput = document.getElementById('overallDiscount');
    let overallVal = 0;
    if (overallInput) {
      overallVal = parseFloat(overallInput.value) || 0;
      if (overallVal < 0) overallVal = 0;
      if (overallVal > baseGrand) overallVal = baseGrand;
    }
    const netTotal = baseGrand - overallVal;

    byId('subtotal').textContent = fmt(subtotal);
    byId('discountAmount').textContent = fmt(discountTotal);
    byId('grandTotal').textContent = fmt(baseGrand);
    const oDisplay = document.getElementById('overallDiscountDisplay');
    if (oDisplay) oDisplay.textContent = fmt(overallVal);
    const netElem = document.getElementById('netTotal');
    if (netElem) netElem.textContent = fmt(netTotal);

    byId('totalQty').textContent = totalQty;
    byId('cartCount').textContent = cart.length;

    document.querySelectorAll('.minus').forEach(b => b.addEventListener('click', () => changeQty(b.dataset.i, -1)));
    document.querySelectorAll('.plus').forEach(b => b.addEventListener('click', () => changeQty(b.dataset.i, +1)));
    document.querySelectorAll('.qty').forEach(inp => inp.addEventListener('change', () => setQty(inp.dataset.i, inp.value)));
    document.querySelectorAll('.rm').forEach(btn => btn.addEventListener('click', () => removeItem(btn.dataset.i)));
    document.querySelectorAll('.editable-price').forEach(s => s.addEventListener('click', () => openPriceModal(s.dataset.i)));
    document.querySelectorAll('.editable-discount').forEach(s => s.addEventListener('click', () => openDiscountModal(s.dataset.i)));
    document.querySelectorAll('#overallDiscount').forEach(inp => inp.addEventListener('change', () => updateCartDisplay()));
  }

  function changeQty(i, delta) {
    i = Number(i);
    const it = cart[i];
    const newQ = it.quantity + delta;
    if (newQ < 1) return;
    if (newQ > it.stock_quantity) { alert('Cannot add more than available stock'); return; }
    it.quantity = newQ;
    updateCartDisplay();
  }

  function setQty(i, v) {
    i = Number(i);
    v = Math.max(1, parseInt(v||'1', 10));
    const it = cart[i];
    if (v > it.stock_quantity) { alert('Cannot add more than available stock'); return; }
    it.quantity = v;
    updateCartDisplay();
  }

  function removeItem(i) {
    cart.splice(Number(i), 1);
    updateCartDisplay();
  }

  // Price modal
  function openPriceModal(i) {
    i = Number(i);
    currentEditingIndex = i;
    const it = cart[i];
    // populate product name, cost pin, and selling price for editing
    byId('editPriceProductName').value = it.product_name + (it.batch_number ? ` (Batch ${it.batch_number})` : '');
    if (byId('editPriceCostPin')) {
      byId('editPriceCostPin').value = (it.cost_pin ?? '');
    }
    if (byId('editPriceSelling')) {
      byId('editPriceSelling').value = (Number(it.price) || 0).toFixed(2);
    }
    if (byId('priceError')) {
      byId('priceError').style.display = 'none';
    }
    // show the modal
    new bootstrap.Modal(byId('editPriceModal')).show();
  }

  // selling price change validation
  byId('editPriceSelling').addEventListener('input', function(){
    const newPrice = parseFloat(this.value) || 0;
    const cost = cart[currentEditingIndex]?.cost_price || 0;
    byId('priceError').style.display = newPrice < cost ? 'block' : 'none';
  });

  // save handler for price modal
  byId('savePrice').addEventListener('click', function(){
    const newPrice = parseFloat(byId('editPriceSelling').value) || 0;
    const cost = cart[currentEditingIndex].cost_price;
    if (newPrice < cost && !confirm('Selling price is below cost. Continue?')) return;
    cart[currentEditingIndex].price = newPrice;
    cart[currentEditingIndex].discount_type = null;
    cart[currentEditingIndex].discount_value = 0;
    updateCartDisplay();
    bootstrap.Modal.getInstance(byId('editPriceModal')).hide();
  });

  // Discount modal
  function openDiscountModal(i) {
    i = Number(i);
    currentEditingIndex = i;
    const it = cart[i];
    byId('editDiscountProductName').value = it.product_name + (it.batch_number ? ` (Batch ${it.batch_number})` : '');
    byId('editDiscountPrice').value = fmt(it.price);
    byId('discountType').value = it.discount_type || 'percentage';
    byId('discountValue').value = it.discount_value || 0;
    updateDiscountPreview();
    new bootstrap.Modal(byId('editDiscountModal')).show();
  }

  function updateDiscountPreview() {
    if (currentEditingIndex === null) return;
    const it = cart[currentEditingIndex];
    const type = byId('discountType').value;
    const val = parseFloat(byId('discountValue').value)||0;
    const line = it.price * it.quantity;
    let disc;
    if (type === 'percentage') {
      disc = (line * val / 100);
    } else {
      disc = val * it.quantity;
      if (disc > line) disc = line;
    }
    const finalLine = line - disc;
    byId('discountAmountPreview').value = fmt(disc);
    byId('discountFinalPrice').value = fmt(finalLine);
  }

  function validateDiscountInput() {
    if (currentEditingIndex === null) return true;
    const it = cart[currentEditingIndex];
    const type = byId('discountType').value;
    const valRaw = byId('discountValue').value;
    const val = parseFloat(valRaw) || 0;
    const cost = parseFloat(it.cost_price) || 0;
    let netUnit = it.price;
    if (type === 'percentage') {
      netUnit = it.price * (1 - (val / 100));
    } else {
      netUnit = it.price - val;
    }
    if (netUnit + 0.000001 < cost) {
      byId('discountError').style.display = 'block';
      byId('discountValue').value = '';
      return false;
    }
    byId('discountError').style.display = 'none';
    return true;
  }
  byId('discountType').addEventListener('change', function(){
    validateDiscountInput();
    updateDiscountPreview();
  });
  byId('discountValue').addEventListener('input', function(){
    validateDiscountInput();
    updateDiscountPreview();
  });
  byId('saveDiscount').addEventListener('click', function(){
    const isValid = validateDiscountInput();
    const type = byId('discountType').value;
    const val = parseFloat(byId('discountValue').value) || 0;
    if (!isValid || val === 0) {
      cart[currentEditingIndex].discount_type = null;
      cart[currentEditingIndex].discount_value = 0;
    } else {
      cart[currentEditingIndex].discount_type = type;
      cart[currentEditingIndex].discount_value = val;
    }
    updateCartDisplay();
    bootstrap.Modal.getInstance(byId('editDiscountModal')).hide();
  });

  // ---------- SEARCH ----------
  const searchInput = byId('productSearch');
  const searchResults = byId('searchResults');
  searchInput.addEventListener('input', function(){
    const q = this.value.trim().toLowerCase();
    if (q.length < 1) { searchResults.style.display = 'none'; return; }
    const matches = products.filter(p =>
      (p.name && p.name.toLowerCase().includes(q)) ||
      (p.code && String(p.code).toLowerCase().includes(q)) ||
      (p.barcode && String(p.barcode).toLowerCase().includes(q))
    );
    if (!matches.length) {
      searchResults.innerHTML = '<div class="search-item">No products found</div>';
      searchResults.style.display = 'block';
      return;
    }
    searchResults.innerHTML = matches.map(p => `
      <div class="search-item" data-product-id="${p.id}">
        <div>
          <div>${p.name} <span class="badge bg-info">${p.code || ''}</span></div>
          <small class="text-muted">Barcode: ${p.barcode || ''} | ${fmt(p.price)}</small>
        </div>
        <div><span class="badge bg-secondary">${p.category ?? ''}</span></div>
      </div>`).join('');
    searchResults.style.display = 'block';
    searchResults.querySelectorAll('.search-item').forEach(item => {
      item.addEventListener('click', () => {
        const pid = parseInt(item.getAttribute('data-product-id'));
        const product = products.find(x => x.id === pid);
        if (product) handleProductSelection(product);
        searchResults.style.display = 'none';
        searchInput.value = '';
      });
    });
  });

  document.addEventListener('click', (e) => {
    if (!e.target.closest('.search-container')) searchResults.style.display = 'none';
  });

  // ---------- CUSTOMER SEARCH (live search) ----------
  const customerSearchInput = document.getElementById('customerSearch');
  const customerResults = document.getElementById('customerSearchResults');
  if (customerSearchInput) {
    customerSearchInput.addEventListener('input', function() {
      const query = this.value.trim().toLowerCase();
      const custSelectEl = document.getElementById('customer_id');
      if (!query) {
        // If empty search, reset to walk-in customer
        if (custSelectEl) {
          custSelectEl.value = '';
          // trigger change event so due status updates
          custSelectEl.dispatchEvent(new Event('change'));
        }
        customerResults.style.display = 'none';
        return;
      }
      const matches = customersData.filter(c =>
        (c.name && c.name.toLowerCase().includes(query)) ||
        (c.phone && c.phone.toLowerCase().includes(query))
      );
      if (!matches.length) {
        customerResults.innerHTML = '<div class="search-item">No customers found</div>';
        customerResults.style.display = 'block';
        return;
      }
      customerResults.innerHTML = matches.map(c => {
        const phonePart = c.phone ? ' <small class="text-muted">' + c.phone + '</small>' : '';
        return '<div class="search-item" data-id="' + c.id + '"><div>' + c.name + phonePart + '</div></div>';
      }).join('');
      customerResults.style.display = 'block';
      // Attach click handlers for each suggestion
      customerResults.querySelectorAll('.search-item').forEach(item => {
        item.addEventListener('click', () => {
          const id = item.getAttribute('data-id');
          const cust = customersData.find(x => String(x.id) === String(id));
          if (cust) {
            customerSearchInput.value = cust.name + (cust.phone ? ' (' + cust.phone + ')' : '');
            if (custSelectEl) {
              custSelectEl.value = cust.id;
              custSelectEl.dispatchEvent(new Event('change'));
            }
          }
          customerResults.style.display = 'none';
        });
      });
    });
    // Hide suggestions when clicking outside
    document.addEventListener('click', (evt) => {
      if (!evt.target.closest('#customerSearchResults') && !evt.target.closest('#customerSearch')) {
        customerResults.style.display = 'none';
      }
    });
  }

  // ---------- BARCODE SCAN ----------
  const toggleScannerBtn = byId('toggleScanner');
  const scannerStatus = byId('scannerStatus');
  toggleScannerBtn.addEventListener('click', () => {
    scannerActive = !scannerActive;
    if (scannerActive) {
      searchInput.classList.add('scanner-active');
      scannerStatus.textContent = 'Scanning...';
      scannerStatus.className = 'scanner-on';
      searchInput.placeholder = 'Ready to scan barcode...';
      searchInput.focus();
    } else {
      searchInput.classList.remove('scanner-active');
      scannerStatus.textContent = 'Scan';
      scannerStatus.className = 'scanner-off';
      searchInput.placeholder = 'Search by name, code, or barcode';
    }
  });

  searchInput.addEventListener('keypress', function(e){
    if (!scannerActive) return;
    if (barcodeTimeout) clearTimeout(barcodeTimeout);
    if (e.which >= 48 && e.which <= 57) {
      barcodeBuffer += String.fromCharCode(e.which);
      barcodeTimeout = setTimeout(() => {
        if (barcodeBuffer.length >= 8) {
          processBarcode(barcodeBuffer);
        }
        barcodeBuffer = '';
      }, 100);
    }
    e.preventDefault();
  });

  function processBarcode(code) {
    const p = products.find(x => x.barcode === code);
    if (!p) { alert('Product with barcode '+code+' not found'); return; }
    handleProductSelection(p);
    searchInput.value = '';
    searchResults.style.display = 'none';
  }

  // ---------- HOLD / RETRIEVE ----------
  function calcTotals() {
    let subtotal=0, discountTotal=0, taxTotal=0;
    cart.forEach(it => {
      const line = it.price * it.quantity;
      const disc = calcDiscountAmount(it);
      const tax = (line - disc) * (it.tax_rate/100);
      subtotal += line; discountTotal += disc; taxTotal += tax;
    });
    let grand = subtotal - discountTotal + taxTotal;
    let overallDisc = 0;
    const overallInput = document.getElementById('overallDiscount');
    if (overallInput) {
      const val = parseFloat(overallInput.value) || 0;
      if (val > 0) {
        overallDisc = val;
        if (overallDisc > grand) overallDisc = grand;
        grand -= overallDisc;
      }
    }
    return {subtotal, discountTotal, taxTotal, grand, overallDiscount: overallDisc};
  }
  window.calcTotals = calcTotals;

  byId('holdSale').addEventListener('click', () => {
    if (!cart.length) return alert('Add items first');
    const t = calcTotals();
    byId('holdTotalAmount').value = fmt(t.grand);
    byId('holdReference').value = '';
    new bootstrap.Modal(byId('holdSaleModal')).show();
  });

  byId('confirmHoldSale').addEventListener('click', () => {
    const t = calcTotals();
    const ref = byId('holdReference').value.trim();
    heldSales.push({
      id: 'H'+Date.now(),
      timestamp: Date.now(),
      reference: ref,
      cart: cart.map(x => ({...x})),
      subtotal: t.subtotal, discount: t.discountTotal, tax: t.taxTotal, total: t.grand
    });
    localStorage.setItem('heldSales', JSON.stringify(heldSales));
    // === CHANGE: don't reassign cart ===
    cart.length = 0;
    updateCartDisplay();
    bootstrap.Modal.getInstance(byId('holdSaleModal')).hide();
    alert('Sale held' + (ref ? ` (${ref})` : ''));
  });

  byId('retrieveHeldBtn')?.addEventListener('click', (e) => {
    e.preventDefault();
    if (!heldSales.length) { alert('No held sales'); return; }
    const list = byId('heldSalesList');
    list.innerHTML = heldSales.map((s, idx) => {
      const date = new Date(s.timestamp).toLocaleString();
      const count = s.cart.reduce((sum, it)=>sum + Number(it.quantity||0), 0);
      return `<tr>
        <td>${date}</td><td>${s.reference || 'No reference'}</td>
        <td>${count} items</td><td>${fmt(s.total)}</td>
        <td>
          <button class="btn btn-sm btn-primary retrieve" data-i="${idx}">Retrieve</button>
          <button class="btn btn-sm btn-danger delete" data-i="${idx}">Delete</button>
        </td>
      </tr>`;
    }).join('');
    list.querySelectorAll('.retrieve').forEach(btn => btn.addEventListener('click', () => {
      const i = Number(btn.dataset.i);
      if (cart.length && !confirm('Replace current cart with held sale?')) return;
      // === CHANGE: keep same array reference ===
      cart.length = 0;
      heldSales[i].cart.forEach(x => cart.push({...x}));
      updateCartDisplay();
      heldSales.splice(i,1);
      localStorage.setItem('heldSales', JSON.stringify(heldSales));
      bootstrap.Modal.getInstance(byId('retrieveHeldModal')).hide();
      alert('Held sale loaded');
    }));
    list.querySelectorAll('.delete').forEach(btn => btn.addEventListener('click', () => {
      const i = Number(btn.dataset.i);
      if (!confirm('Delete this held sale?')) return;
      heldSales.splice(i,1);
      localStorage.setItem('heldSales', JSON.stringify(heldSales));
      btn.closest('tr').remove();
      if (!heldSales.length) list.innerHTML = '<tr><td colspan="5" class="text-center">No held sales</td></tr>';
    }));
    new bootstrap.Modal(byId('retrieveHeldModal')).show();
  });

  // ---------- PAYMENTS ----------

  // ---- Walk-in rules (helper) ----
  const customerSelect = byId('customer_id');
  const isWalkIn = () => !customerSelect || !customerSelect.value;
  const WALKIN_ALLOWED = ['cash','card','upi','bank_transfer'];
  const WALKIN_DISALLOWED = ['credit','cheque'];

  function setPaymentMethodOptions(selectEl, allowedValues) {
    if (!selectEl) return;
    const all = [
      {value:'cash',          text:'Cash'},
      {value:'credit',        text:'Credit'},
      {value:'card',          text:'Card'},
      {value:'upi',           text:'UPI'},
      {value:'bank_transfer', text:'Bank Transfer'},
      {value:'cheque',        text:'Cheque'},
    ];
    selectEl.innerHTML = '';
    // Clone allowed values so we can safely modify
    let avail = Array.isArray(allowedValues) ? allowedValues.slice() : [];
    // If there are no bank accounts configured, remove bank_transfer from the available list
    if (!Array.isArray(bankAccounts) || bankAccounts.length === 0) {
      const idx = avail.indexOf('bank_transfer');
      if (idx !== -1) avail.splice(idx, 1);
    }
    all.filter(opt => avail.includes(opt.value))
       .forEach(opt => {
         const o = document.createElement('option');
         o.value = opt.value;
         o.textContent = opt.text;
         selectEl.appendChild(o);
       });
  }

  byId('multiplePayment').addEventListener('click', () => {
    if (!cart.length) return alert('Add items first');
    isMultiplePayment = true; showPaymentModal();
  });
  byId('completeSale').addEventListener('click', () => {
    if (!cart.length) return alert('Add items first');
    isMultiplePayment = false; showPaymentModal();
  });

  const payAllBtn = byId('payAll');
  if (payAllBtn) {
    payAllBtn.addEventListener('click', () => {
      if (!cart.length) return alert('Add items first');
      const t = calcTotals();
      byId('amountReceived').value = t.grand.toFixed(2);
      byId('changeAmount').value = fmt(0);
      isMultiplePayment = false; showPaymentModal();
    });
  }

  function showPaymentModal() {
    const t = calcTotals();
    creditApplied = 0;
    let due = t.grand;
    const creditRow = document.getElementById('creditInfo');
    const creditAmtInput = document.getElementById('creditAppliedAmount');
    if (exchangeCredit > 0 && due > 0) {
      creditApplied = Math.min(exchangeCredit, due);
      due = parseFloat((due - creditApplied).toFixed(2));
      if (creditRow && creditAmtInput) {
        creditRow.style.display = '';
        creditAmtInput.value = fmt(creditApplied);
      }
    } else {
      if (creditRow) creditRow.style.display = 'none';
    }
    byId('paymentGrandTotal').value = fmt(due);
    paymentMethods = [];
    byId('amountReceived').value = '';
    byId('changeAmount').value = '';
    const cl = document.getElementById('changeLabel');
    if (cl) cl.textContent = 'Change/Due';

    // reset card breakdown blocks
    byId('singleCardCommissionWrap').style.display = 'none';
    byId('multiCardSummaryWrap').style.display = 'none';

    // Limit single-payment dropdown according to Walk-in rule
    if (isWalkIn()) {
      setPaymentMethodOptions(byId('paymentMethod'), WALKIN_ALLOWED);
    } else {
      setPaymentMethodOptions(byId('paymentMethod'), ['cash','credit','card','upi','bank_transfer','cheque']);
    }

    if (isMultiplePayment) {
      byId('singlePaymentSection').style.display = 'none';
      byId('multiplePaymentSection').style.display = 'block';
      const container = byId('paymentMethodsContainer');
      container.innerHTML = '';
      addPaymentMethodRow();
      updateMultiplePaymentTotals();
    } else {
      byId('singlePaymentSection').style.display = 'block';
      byId('multiplePaymentSection').style.display = 'none';
      setTimeout(() => {
        byId('amountReceived').focus();
      }, 250);
    }
    new bootstrap.Modal(byId('paymentModal')).show();
  }

  function addPaymentMethodRow() {
    const id = 'pm_' + Date.now();
    const row = document.createElement('div');
    row.className = 'payment-method-row';
    row.id = id;
    row.innerHTML = `
      <div class="row g-2 mb-2 align-items-center">
        <div class="col-5">
          <select class="form-select payment-method"></select>
        </div>
        <div class="col-5"><input type="number" class="form-control payment-amount" placeholder="Amount" min="0" step="0.01"></div>
        <div class="col-2 text-end"><button class="btn btn-sm btn-danger remove"><i class="bi bi-trash"></i></button></div>
      </div>
      <div class="cheque-details row g-2 mb-2" style="display:none;">
        <div class="col-3"><input type="text" class="form-control cheque-number" placeholder="Cheque No"></div>
        <div class="col-3"><input type="text" class="form-control cheque-bank" placeholder="Bank Name"></div>
        <div class="col-3"><input type="text" class="form-control cheque-branch" placeholder="Branch"></div>
        <div class="col-3"><input type="date" class="form-control cheque-date" placeholder="Deposit Date"></div>
        <!-- NEW transfer date input in multiple payments -->
        <div class="col-3"><input type="date" class="form-control cheque-transfer-date" placeholder="Transfer Date"></div>
      </div>
      <div class="bank-transfer-details row g-2 mb-2" style="display:none;">
        <div class="col-4"><select class="form-select bank-account"></select></div>
        <div class="col-8"><div class="form-text text-danger bank-no-accounts" style="display:none;">No bank accounts available</div></div>
      </div>`;
    const wrap = byId('paymentMethodsContainer');
    wrap.appendChild(row);

    // Populate methods based on Walk-in rule
    const methodSelect = row.querySelector('.payment-method');
    if (isWalkIn()) {
      setPaymentMethodOptions(methodSelect, WALKIN_ALLOWED);
    } else {
      setPaymentMethodOptions(methodSelect, ['cash','credit','card','upi','bank_transfer','cheque']);
    }

    // Populate bank account dropdown for this row
    const bankSelectEl = row.querySelector('.bank-account');
    const bankMsgEl   = row.querySelector('.bank-no-accounts');
    const hasBanksRow = populateBankSelect(bankSelectEl);
    if (!hasBanksRow) {
      if (bankSelectEl) bankSelectEl.style.display = 'none';
      if (bankMsgEl) bankMsgEl.style.display = 'block';
    } else {
      if (bankSelectEl) bankSelectEl.style.display = '';
      if (bankMsgEl) bankMsgEl.style.display = 'none';
    }

    row.querySelector('.remove').addEventListener('click', () => { row.remove(); updateMultiplePaymentTotals(); });
    row.querySelector('.payment-amount').addEventListener('input', updateMultiplePaymentTotals);
    methodSelect.addEventListener('change', () => {
      const chequeDiv = row.querySelector('.cheque-details');
      const bankDiv   = row.querySelector('.bank-transfer-details');
      if (methodSelect.value === 'cheque') {
        // show cheque inputs
        chequeDiv.style.display = 'flex';
        // default deposit and transfer dates for cheque
        const today = new Date().toISOString().slice(0,10);
        const depDateInput = row.querySelector('.cheque-date');
        if (depDateInput && !depDateInput.value) depDateInput.value = today;
        const trfInput = row.querySelector('.cheque-transfer-date');
        if (trfInput && !trfInput.value) trfInput.value = today;
        // hide bank transfer inputs
        if (bankDiv) bankDiv.style.display = 'none';
      } else if (methodSelect.value === 'bank_transfer') {
        // show bank transfer select
        if (chequeDiv) chequeDiv.style.display = 'none';
        if (bankDiv) bankDiv.style.display = 'flex';
      } else {
        // hide both extra details for other methods
        if (chequeDiv) chequeDiv.style.display = 'none';
        if (bankDiv) bankDiv.style.display = 'none';
      }
      updateMultiplePaymentTotals();
    });
  }
  byId('addPaymentMethod').addEventListener('click', addPaymentMethodRow);

  function updateMultiplePaymentTotals() {
  const rows = Array.from(document.querySelectorAll('#paymentMethodsContainer .payment-method-row'));
  let paid = 0;
  let cardTotal = 0;

  rows.forEach(row => {
    const amt = parseFloat(row.querySelector('.payment-amount').value) || 0;
    const method = row.querySelector('.payment-method').value;
    paid += amt;
    if (method === 'card') cardTotal += amt;
  });

  // Robust parse of "Rs. 330.00" → 330.00
  const totalText = byId('paymentGrandTotal').value || '';
  const m = totalText.match(/([0-9]+(?:\.[0-9]+)?)/);
  const total = m ? parseFloat(m[1]) : 0;

  const roundedPaid  = Math.round(paid * 100) / 100;
  const roundedTotal = Math.round(total * 100) / 100;

  byId('multipleTotalPaid').textContent = fmt(roundedPaid);

  const diff = Math.round((roundedTotal - roundedPaid) * 100) / 100;
  const labelElem = byId('multipleBalanceLabel');

  if (diff > 0) {
    labelElem.textContent = 'Due:';
    byId('multipleBalance').textContent = fmt(diff);
  } else {
    labelElem.textContent = 'Change:';
    byId('multipleBalance').textContent = fmt(Math.abs(diff));
  }

  // Card commission live summary
  if (cardTotal > 0) {
    const commission = Math.round(cardTotal * <?= json_encode(0.025) ?> * 100) / 100;
    const bankNet    = Math.round((cardTotal - commission) * 100) / 100;
    byId('multiCardSummaryWrap').style.display = 'block';
    byId('multiCardTotal').textContent = fmt(cardTotal);
    byId('multiCardCommission').textContent = fmt(commission);
    byId('multiCardBankNet').textContent = fmt(bankNet);
  } else {
    byId('multiCardSummaryWrap').style.display = 'none';
  }
}


  byId('amountReceived').addEventListener('input', function(){
    updateSinglePaymentDisplay();
  });

  function updateSinglePaymentDisplay() {
  const got = parseFloat(byId('amountReceived').value) || 0;
  const totalMatch = byId('paymentGrandTotal').value.match(/([0-9]+(?:\.[0-9]+)?)/);
  const total = totalMatch ? parseFloat(totalMatch[0]) : 0;
  const method = byId('paymentMethod').value;
  const labelEl = byId('changeLabel');
  const chequeDiv = document.getElementById('chequeDetailsSingle');

  // Bank transfer details container
  const bankDiv = document.getElementById('bankTransferDetailsSingle');

  // show/hide cheque fields
  chequeDiv.style.display = (method === 'cheque') ? 'block' : 'none';

  // show/hide bank transfer fields
  if (bankDiv) {
    bankDiv.style.display = (method === 'bank_transfer') ? 'block' : 'none';
  }

  // default deposit and transfer dates when method is cheque
  if (method === 'cheque') {
    const today = new Date().toISOString().slice(0,10);
    // if (!byId('chequeDepositDate').value)  byId('chequeDepositDate').value  = today;
    if (!byId('chequeTransferDate').value) byId('chequeTransferDate').value = today;
  }

  // hide card commission block for non-card
  if (method === 'card' && got > 0) {
    const c = parseFloat((got * <?= json_encode(0.025) ?>).toFixed(2));
    const bankNet = parseFloat((got - c).toFixed(2));
    byId('singleCardCommissionWrap').style.display = 'block';
    byId('singleCardCommission').textContent = fmt(c);
    byId('singleCardBankNet').textContent = fmt(bankNet);
  } else {
    byId('singleCardCommissionWrap').style.display = 'none';
  }

  // CREDIT: always display DUE (no change)
  if (method === 'credit') {
    labelEl.textContent = 'Due';
    const due = Math.max(total - got, 0);
    byId('changeAmount').value = fmt(due);
    return;
  }

  // existing logic for cash / others
  const diff = got - total;
  if (method === 'cash') {
    labelEl.textContent = (diff >= 0) ? 'Change' : 'Due';
    byId('changeAmount').value = fmt(Math.abs(diff));
  } else {
    labelEl.textContent = (diff >= 0) ? 'Change' : 'Due';
    byId('changeAmount').value = fmt(Math.max(-diff, 0)); // no change for non-cash
  }
}

  byId('paymentMethod').addEventListener('change', function() {
     if (this.value === 'credit') byId('amountReceived').value = '0.00';
    updateSinglePaymentDisplay();
  });

  byId('finalizePayment').addEventListener('click', function () {
  const total = parseFloat(byId('paymentGrandTotal').value.replace(/[^\d.]/g, '')) || 0;

  // We'll first collect raw rows exactly as the user entered them
  const rawPayments = [];
  let valid = true;

  if (isMultiplePayment) {
    document.querySelectorAll('#paymentMethodsContainer .payment-method-row').forEach(row => {
      const method = row.querySelector('.payment-method').value;
      const amt = parseFloat(row.querySelector('.payment-amount').value) || 0;

      // Basic row validation:
      // - method must be chosen
      // - positive amount is required for non-credit rows
      if (!method || (method !== 'credit' && amt <= 0)) valid = false;

      // Payment-specific details
      let cheque_number = null, bank_name = null, bank_branch = null, deposit_date = null, transfer_date = null;
      if (method === 'cheque') {
        cheque_number = row.querySelector('.cheque-number').value.trim() || null;
        bank_name     = row.querySelector('.cheque-bank').value.trim()   || null;
        bank_branch   = row.querySelector('.cheque-branch').value.trim() || null;
        const depDateVal = row.querySelector('.cheque-date').value;
        deposit_date  = depDateVal ? depDateVal : null;
        const trfDateVal = row.querySelector('.cheque-transfer-date')?.value;
        transfer_date = trfDateVal ? trfDateVal : null;
      } else if (method === 'bank_transfer') {
        // Bank transfer: capture selected bank; no cheque number or branch
        const bankSelect = row.querySelector('.bank-account');
        const val = bankSelect ? bankSelect.value.trim() : '';
        if (val) {
          bank_name = val;
        } else {
          valid = false;
        }
      }

      // Card commission (row-level)
      let commission_rate = null, commission_amount = null, bank_amount = null;
      if (method === 'card') {
        commission_rate   = <?= json_encode(0.025) ?>;
        commission_amount = parseFloat((amt * <?= json_encode(0.025) ?>).toFixed(2));
        bank_amount       = parseFloat((amt - commission_amount).toFixed(2));
      }

      rawPayments.push({
        method,
        amount: amt,
        cheque_number, bank_name, bank_branch, deposit_date, transfer_date,
        commission_rate, commission_amount, bank_amount
      });
    });

    if (!valid) return alert('Complete all payment method rows');
  } else {
    const method = byId('paymentMethod').value;
    const amt    = parseFloat(byId('amountReceived').value) || 0;

    // Validation for single entry:
    if (method === 'credit') {
      if (amt < 0) return alert('Amount cannot be negative.');
      // amount can be 0 for credit (we treat the remainder as due)
    } else {
      if (amt <= 0) return alert('Enter amount received.');
      if (method === 'cash' && amt < total && !confirm('Received is less than total. Mark as partial?')) return;
    }

    // Payment-specific fields
    let cheque_number = null, bank_name = null, bank_branch = null, deposit_date = null, transfer_date = null;
    if (method === 'cheque') {
      cheque_number = byId('chequeNumber').value.trim()      || null;
      bank_name     = byId('chequeBankName').value.trim()    || null;
      bank_branch   = byId('chequeBankBranch').value.trim()  || null;
      const depDateVal = byId('chequeDepositDate').value;
      deposit_date  = depDateVal ? depDateVal : null;
      const trfDateVal = byId('chequeTransferDate').value;
      transfer_date = trfDateVal ? trfDateVal : null;
    } else if (method === 'bank_transfer') {
      // Bank transfer: get selected bank; no bank branch or cheque number
      const bankSel = document.getElementById('bankTransferAccount');
      const val = bankSel ? bankSel.value.trim() : '';
      if (!val) {
        alert('Please select a bank account for bank transfer.');
        return;
      }
      bank_name = val;
    }

    // Card commission (single)
    let commission_rate = null, commission_amount = null, bank_amount = null;
    if (method === 'card') {
      commission_rate   = <?= json_encode(0.025) ?>;
      commission_amount = parseFloat((amt * <?= json_encode(0.025) ?>).toFixed(2));
      bank_amount       = parseFloat((amt - commission_amount).toFixed(2));
    }

    rawPayments.push({
      method,
      amount: amt,
      cheque_number, bank_name, bank_branch, deposit_date, transfer_date,
      commission_rate, commission_amount, bank_amount
    });
  }

  // Walk-in final guard: block Credit & Cheque for walk-ins
  if (isWalkIn()) {
    const invalid = rawPayments.some(pm => WALKIN_DISALLOWED.includes(pm.method));
    if (invalid) {
      alert('For Walk-in customers, only Cash, Card, UPI, or Bank Transfer are allowed (no Credit/Cheque).');
      return;
    }
  }

  // Keep only real money movements in `payments` (exclude 'credit')
  const actualPayments = rawPayments.filter(pm => pm.method !== 'credit');

  // Update global paymentMethods for invoice display.  Use only actual payments (no credit rows).
  paymentMethods = actualPayments.slice();
  const paidNonCredit  = actualPayments.reduce((s, p) => s + (parseFloat(p.amount) || 0), 0);

  // If underpaid, confirm partial (uses only non-credit money)
  if (paidNonCredit < total && !confirm('Total received is less than Grand Total. Mark as partial?')) return;

  // Whatever remains becomes the receivable (Debtor)
  const credit_amount = Math.max(total - paidNonCredit, 0);

  // Gather invoice-level discount (unchanged)
  let overallDiscountValue = 0;
  const overallInputSend = document.getElementById('overallDiscount');
  if (overallInputSend) {
    const val = parseFloat(overallInputSend.value) || 0;
    overallDiscountValue = val < 0 ? 0 : val;
  }

  // Send ONLY non-credit rows in `payments`, plus `credit_amount` separately
  fetch('ajax_save_sale.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      cart: cart,
      payments: actualPayments,                          // <- no 'credit' rows here
      credit_amount: credit_amount,                      // <- remaining due goes here
      customer_id: document.getElementById('customer_id').value || null,
      worker_number: document.getElementById('worker_number') ? document.getElementById('worker_number').value : null,
      overall_discount: overallDiscountValue
    })
  })
  .then(response => response.json())
  .then(data => {
    if (data && data.status === 'success') {
      if (data.sale_id) {
        lastSaleId = data.sale_id;
      }
      if (creditApplied > 0) {
        exchangeCredit = Math.max(exchangeCredit - creditApplied, 0);
        localStorage.setItem('exchangeCredit', exchangeCredit.toFixed(2));
        creditApplied = 0;
        if (typeof updateExchangeDisplay === 'function') updateExchangeDisplay();
      }
      showInvoice();
    } else {
      const msg = (data && data.message) ? data.message : 'Unknown error saving sale';
      alert('Error saving sale: ' + msg);
    }
  })
  .catch(err => {
    alert('Failed to communicate with server: ' + err);
  });
});


  function showInvoice() {
    const tbody = byId('invoice-items');
    tbody.innerHTML = '';
    let subtotal=0, discountTotal=0, taxTotal=0;
    cart.forEach(it => {
      const line = it.price * it.quantity;
      const disc = calcDiscountAmount(it);
      const tax = (line - disc) * (it.tax_rate/100);
      const total = line - disc + tax;
      subtotal += line; discountTotal += disc; taxTotal += tax;
      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td>${it.product_name}${it.batch_number && it.batch_number!=='N/A' ? ' (Batch: '+it.batch_number+')' : ''}</td>
        <td class="text-center">${it.quantity}</td>
        <td class="text-end">${fmt(it.price)}</td>
        <td class="text-end">${fmt(disc)}</td>
        <td class="text-end">${fmt(total)}</td>`;
      tbody.appendChild(tr);
    });
    const grand = subtotal - discountTotal + taxTotal;
    let overallVal = 0;
    const overallInput = document.getElementById('overallDiscount');
    if (overallInput) {
      overallVal = parseFloat(overallInput.value) || 0;
      if (overallVal < 0) overallVal = 0;
      if (overallVal > grand) overallVal = grand;
    }
    const netGrand = grand - overallVal;
    const totalPaid = paymentMethods.reduce((s,p)=>s+(p.amount||0),0);
    byId('invoice-subtotal').textContent = fmt(subtotal);
    byId('invoice-discount').textContent = fmt(discountTotal);
    const iod = document.getElementById('invoice-overall-discount');
    if (iod) iod.textContent = fmt(overallVal);
    byId('invoice-tax').textContent = fmt(taxTotal);
    byId('invoice-grandtotal').textContent = fmt(netGrand);
    byId('invoice-paid').textContent = fmt(totalPaid);
    const diffInvoice = parseFloat((totalPaid - netGrand).toFixed(2));
    byId('invoice-change').textContent = diffInvoice >= 0 ? fmt(diffInvoice) : (fmt(Math.abs(diffInvoice)) + ' Due');
    const custSel = byId('customer_id');
    byId('invoice-customer').textContent = custSel.value ? custSel.options[custSel.selectedIndex].text : 'Walk-in Customer';

    if (paymentMethods.length === 1) {
      const pm = paymentMethods[0];
      let label = pm.method.charAt(0).toUpperCase() + pm.method.slice(1);
      if (pm.method === 'cheque') {
        const details = [];
        if (pm.cheque_number) details.push('No: ' + pm.cheque_number);
        if (pm.bank_name) details.push('Bank: ' + pm.bank_name);
        if (pm.bank_branch) details.push('Branch: ' + pm.bank_branch);
        if (pm.deposit_date) details.push('Deposit: ' + pm.deposit_date);
        if (details.length) label += ' (' + details.join(', ') + ')';
      }
      // Append card commission details if applicable
      if (pm.method === 'card' && pm.commission_amount != null && pm.bank_amount != null) {
        label += ` — Comm: ${fmt(pm.commission_amount)}, Bank: ${fmt(pm.bank_amount)}`;
      }
      byId('invoice-payment-method').textContent = label;
      byId('invoice-payment-breakdown').style.display = 'none';
    } else {
      byId('invoice-payment-method').textContent = 'Multiple Methods';
      byId('invoice-payment-methods').innerHTML = paymentMethods.map(pm => {
        let label = pm.method.charAt(0).toUpperCase() + pm.method.slice(1);
        if (pm.method === 'cheque') {
          const details = [];
          if (pm.cheque_number) details.push('No: ' + pm.cheque_number);
          if (pm.bank_name) details.push('Bank: ' + pm.bank_name);
          if (pm.bank_branch) details.push('Branch: ' + pm.bank_branch);
          if (pm.deposit_date) details.push('Deposit: ' + pm.deposit_date);
          if (details.length) label += ' (' + details.join(', ') + ')';
        }
        if (pm.method === 'card' && pm.commission_amount != null && pm.bank_amount != null) {
          label += ` — Comm: ${fmt(pm.commission_amount)}, Bank: ${fmt(pm.bank_amount)}`;
        }
        return `<tr><td>${label}</td><td class="text-end">${fmt(pm.amount)}</td></tr>`;
      }).join('');
      byId('invoice-payment-breakdown').style.display = 'block';
    }

    byId('invoice-date').textContent = new Date().toLocaleString();
    new bootstrap.Modal(byId('invoiceModal')).show();

    const thermalLink = document.getElementById('thermalPrintBtn');
    if (thermalLink) {
      if (lastSaleId) {
        thermalLink.href = 'print_invoice.php?id=' + lastSaleId;
        thermalLink.style.display = 'inline-block';
      } else {
        thermalLink.href = '#';
        thermalLink.style.display = 'none';
      }
    }
    // === CHANGE: keep same cart reference ===
    cart.length = 0;
    updateCartDisplay();
  }

  byId('printInvoice').addEventListener('click', () => window.print());
  byId('newSale').addEventListener('click', () => location.reload());

  // Filters
  byId('categoryFilter').addEventListener('change', renderProducts);
  byId('brandFilter').addEventListener('change', renderProducts);

  // Init
  renderProducts();
  updateCartDisplay();

  // When customer selection changes, fetch their outstanding due and credit limit
  (function() {
    const customerSelect2 = document.getElementById('customer_id');
    const dueStatusEl = document.getElementById('customerDueStatus');
    if (customerSelect2 && dueStatusEl) {
      async function updateDueStatus() {
        const cid = customerSelect2.value;
        if (!cid) {
          dueStatusEl.textContent = '';
          dueStatusEl.className = '';
          return;
        }
        try {
          const resp = await fetch('ajax_get_customer_due.php?customer_id=' + encodeURIComponent(cid));
          const data = await resp.json();
          if (data && typeof data.due !== 'undefined') {
            const due = parseFloat(data.due);
            const limit = data.due_limit !== null ? parseFloat(data.due_limit) : null;
            let text = 'Current Due: Rs. ' + due.toFixed(2);
            if (limit !== null) {
              text += ' (Limit: Rs. ' + limit.toFixed(2) + ')';
            }
            dueStatusEl.textContent = text;
            if (limit !== null && due > limit) {
              dueStatusEl.className = 'text-danger fw-bold';
            } else {
              dueStatusEl.className = 'text-success fw-bold';
            }
          } else {
            dueStatusEl.textContent = '';
            dueStatusEl.className = '';
          }
        } catch (err) {
          console.error('Failed to fetch customer due info', err);
          dueStatusEl.textContent = '';
          dueStatusEl.className = '';
        }
      }
      customerSelect2.addEventListener('change', updateDueStatus);
      updateDueStatus();
    }
  })();

  // Exchange credit display
  (function() {
    function updateExchangeDisplay() {
      const credit = parseFloat(localStorage.getItem('exchangeCredit') || '0');
      exchangeCredit = credit;
      const row = document.getElementById('exchangeCreditRow');
      const amt = document.getElementById('exchangeCreditAmount');
      if (row && amt) {
        if (credit > 0) {
          row.style.display = '';
          amt.textContent = fmt(credit);
        } else {
          row.style.display = 'none';
        }
      }
    }
    updateExchangeDisplay();
    window.updateExchangeDisplay = updateExchangeDisplay;
  })();

  // Qty modal confirm
  const qtyConfirmBtn = document.getElementById('qtyConfirm');
  if (qtyConfirmBtn) {
    qtyConfirmBtn.addEventListener('click', function() {
      const qtyInput = document.getElementById('qtyInput');
      let qty = parseInt(qtyInput.value, 10) || 1;
      if (!pendingAddItem) return;
      const maxStock = Number(pendingAddItem.stock_quantity) || 0;
      if (qty < 1) qty = 1;
      if (qty > maxStock) {
        document.getElementById('qtyError').style.display = 'block';
        return;
      }
      addToCart(pendingAddItem, qty);
      const modalInstance = bootstrap.Modal.getInstance(document.getElementById('qtyModal'));
      if (modalInstance) modalInstance.hide();
      pendingAddItem = null;
    });
  }
});
</script>

<!-- Invoice-level discount handler -->
<script>
document.addEventListener('DOMContentLoaded', function() {
  var overallInputElem = document.getElementById('overallDiscount');
  if (overallInputElem) {
    overallInputElem.addEventListener('input', function() {
      var val = parseFloat(overallInputElem.value);
      if (!isNaN(val) && val < 0) {
        overallInputElem.value = 0;
      }
      if (typeof updateCartDisplay === 'function') {
        updateCartDisplay();
      }
    });
  }
});
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<!-- Return + New Bill Merge JS (uses calcTotals from window) -->
<script>
function rmFmt(n) { return 'Rs. ' + Number(n || 0).toFixed(2); }
function rmGetCartTotal() {
  const el = document.getElementById('grandTotal');
  if (el) {
    const raw = el.textContent || '';
    const num = parseFloat(String(raw).replace(/[^\d.-]/g, ''));
    return isNaN(num) ? 0 : num;
  }
  return 0;
}
var rmState = { return_id: null, return_total: 0, return_items: [], new_total: 0, balance: 0 };

function rmSync() {
  if (typeof calcTotals === 'function') {
    try {
      const totals = calcTotals();
      if (totals && typeof totals.grand !== 'undefined') {
        rmState.new_total = Number(totals.grand) || 0;
      } else { rmState.new_total = 0; }
    } catch (e) { rmState.new_total = 0; }
  } else { rmState.new_total = 0; }
  document.getElementById('rm_new_total').value = rmFmt(rmState.new_total);
  document.getElementById('rm_new_total_lbl').textContent = rmFmt(rmState.new_total);
  document.getElementById('rm_return_total_lbl').textContent = rmFmt(rmState.return_total);
  document.getElementById('rm_return_total_badge').textContent = rmFmt(rmState.return_total);
  rmState.balance = parseFloat((rmState.new_total - rmState.return_total).toFixed(2));
  document.getElementById('rm_balance_lbl').textContent = rmFmt(Math.abs(rmState.balance));
  document.getElementById('rm_balance_hint').textContent = (rmState.balance >= 0)
    ? 'Customer must pay this balance.'
    : 'Refund this amount to the customer.';
}

document.getElementById('rm_load').addEventListener('click', function () {
  const rid = document.getElementById('rm_return_id').value.trim();
  const fb = document.getElementById('rm_feedback');
  fb.classList.add('d-none'); fb.textContent = '';
  if (!rid) { fb.textContent = 'Enter a Return Bill ID.'; fb.classList.remove('d-none'); return; }
  fetch('api/return_summary.php?return_id=' + encodeURIComponent(rid))
    .then(res => res.json())
    .then(js => {
      if (!js.ok) { fb.textContent = js.message || 'Return not found or already settled.'; fb.classList.remove('d-none'); return; }
      rmState.return_id = js.data.return_id;
      rmState.return_total = parseFloat(js.data.return_total) || 0;
      rmState.return_items = js.data.items || [];
      const tbody = document.getElementById('rm_return_rows');
      tbody.innerHTML = '';
      if (rmState.return_items.length === 0) {
        const tr = document.createElement('tr');
        tr.innerHTML = '<td colspan="4" class="text-muted">No items recorded in this return.</td>';
        tbody.appendChild(tr);
      } else {
        rmState.return_items.forEach(it => {
          const tr = document.createElement('tr');
          tr.innerHTML = '<td>' + it.name + (it.batch_no ? ' <span class="text-muted">[' + it.batch_no + ']</span>' : '') + '</td>' +
            '<td class="text-center">' + it.qty + '</td>' +
            '<td class="text-end">' + rmFmt(it.rate) + '</td>' +
            '<td class="text-end">' + rmFmt(it.subtotal) + '</td>';
          tbody.appendChild(tr);
        });
      }
      rmSync();
    })
    .catch(() => { fb.textContent = 'Network/Server error loading return.'; fb.classList.remove('d-none'); });
});

document.getElementById('btnReturnMerge').addEventListener('click', function () { rmSync(); });

document.getElementById('rm_finalize').addEventListener('click', async function () {
  const fb  = document.getElementById('rm_feedback');
  const btn = document.getElementById('rm_finalize');

  fb.classList.add('d-none');
  fb.textContent = '';

  const pin = (document.getElementById('rm_pin').value || '').trim();
  if (!rmState.return_id) { fb.textContent = 'Load a valid Return Bill first.'; fb.classList.remove('d-none'); return; }
  if (!/^\d{4}$/.test(pin)) { fb.textContent = 'Enter a valid 4-digit code.'; fb.classList.remove('d-none'); return; }

  // ensure global cart
  if (!Array.isArray(window.cart) || window.cart.length === 0) {
    fb.textContent = 'Add items to the new bill before finalizing.';
    fb.classList.remove('d-none');
    return;
  }

  // recompute totals
  let grandNow = 0;
  try {
    if (typeof calcTotals === 'function') {
      const t = calcTotals();
      grandNow = Number(t?.grand || 0);
    }
  } catch (_) {
    grandNow = Number(rmState?.new_total || 0) || 0;
  }

  // pack items
  const items = window.cart.map(it => ({
    product_id:     Number(it.product_id) || 0,
    batch_id:       Number(it.batch_id)   || 0,
    qty:            Number(it.quantity)   || 0,
    price:          Number(it.price)      || 0,
    discount_type:  it.discount_type ?? null,
    discount_value: Number(it.discount_value || 0),
    tax_rate:       Number(it.tax_rate || 0)
  }));

  const customer_id   = (document.getElementById('customer_id')?.value || '') || null;
  const worker_number = (document.getElementById('worker_number')?.value || '') || null;

  const payload = {
    pin: pin,
    return_id: rmState.return_id,
    new_sale: {
      items: items,
      grand_total: grandNow,
      customer_id: customer_id ? Number(customer_id) : null,
      worker_number: worker_number ? Number(worker_number) : null
    }
  };

  btn.disabled = true;
  btn.innerText = 'Finalizing…';

  try {
    const res = await fetch('api/complete_with_return.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });
    const js = await res.json();

    if (!js || js.ok !== true) {
      fb.textContent = (js && js.message) ? js.message : 'Could not finalize merge.';
      fb.classList.remove('d-none');
      btn.disabled = false;
      btn.innerText = 'Finalize & Print';
      return;
    }

    if (js.print_url) { window.open(js.print_url, '_blank'); }

    // clear cart (keep same reference)
    window.cart.length = 0;
    const cartBody = document.getElementById('cartItems');
    if (cartBody) cartBody.innerHTML = '<tr><td colspan="7" class="text-center py-3">No items added</td></tr>';
    (document.getElementById('subtotal')       || {}).textContent = 'Rs. 0.00';
    (document.getElementById('discountAmount') || {}).textContent = 'Rs. 0.00';
    (document.getElementById('grandTotal')     || {}).textContent = 'Rs. 0.00';
    (document.getElementById('totalQty')       || {}).textContent = '0';
    (document.getElementById('cartCount')      || {}).textContent = '0';

    const modal = bootstrap.Modal.getInstance(document.getElementById('returnMergeModal'));
    if (modal) modal.hide();

  } catch (err) {
    fb.textContent = 'Network/Server error finalizing merge.';
    fb.classList.remove('d-none');
  } finally {
    btn.disabled = false;
    btn.innerText = 'Finalize & Print';
  }
});
</script>
</body></html>
