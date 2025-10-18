<?php
/**
 * purchase.php — Add & Edit Purchases (with live search + stock adjustments)
 *
 * Features
 * - Add Opening Stock (direct-to-inventory + journal entry) — unchanged
 * - Add Normal Purchase (creates purchase + items + updates batches)
 * - Recent Purchases (branch-scoped) list with Edit action
 * - Edit Purchase: reverses old movements, re-applies edited rows atomically
 *
 * Safety
 * - All DB writes wrapped in transactions where needed
 * - Unique placeholders (prevents HY093)
 */

require_once 'includes/header.php';
checkRole(['admin','manager','inventory_officer']);

$message = '';
$is_admin = ($user['role_name'] === 'admin');
$current_branch_id = (int)($user['branch_id'] ?? 0);

/* ------------------------------ Data for dropdowns/search ------------------------------ */

// Products for live search
$products = $pdo->query("SELECT id, name, COALESCE(barcode, '') AS barcode FROM products ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Suppliers
$suppliers = [];
try {
    // Check if suppliers table has branch_id column
    $supBranchCheck = $pdo->prepare("SHOW COLUMNS FROM suppliers LIKE 'branch_id'");
    $supBranchCheck->execute();
    $hasSupBranch = (bool)$supBranchCheck->fetch();
    if ($hasSupBranch) {
        $stmtSup = $pdo->prepare("SELECT id, name FROM suppliers WHERE branch_id = ? ORDER BY name ASC");
        $stmtSup->execute([$current_branch_id]);
        $suppliers = $stmtSup->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $suppliers = $pdo->query("SELECT id, name FROM suppliers ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    $suppliers = [];
}

// Branch selector: admin sees all, others fixed
if ($is_admin) {
  $branches = $pdo->query("SELECT id, name FROM branches ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
} else {
  $branches = [['id'=>$current_branch_id, 'name'=>($user['branch_name'] ?? 'My Branch')]];
}

/* ------------------------------ Helpers ------------------------------ */

function rs($n){ return 'Rs. '.number_format((float)$n,2); }

function fetch_purchase(PDO $pdo, int $purchase_id) {
  $st = $pdo->prepare("SELECT p.*, s.name AS supplier_name, b.name AS branch_name
                       FROM purchases p
                       LEFT JOIN suppliers s ON s.id=p.supplier_id
                       LEFT JOIN branches  b ON b.id=p.branch_id
                       WHERE p.id=:id");
  $st->execute([':id'=>$purchase_id]);
  return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
function fetch_purchase_items(PDO $pdo, int $purchase_id) {
  $st = $pdo->prepare("SELECT id, product_id, batch_no, quantity, cost_price, cost_pin, selling_price, expiry_date, stock_place
                       FROM purchase_items WHERE purchase_id=:id ORDER BY id");
  $st->execute([':id'=>$purchase_id]);
  return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Apply stock increase for a set of items (insert/update stock_batches).
 * $items: rows with keys [product_id, batch_no, qty, cost_price, cost_pin, selling_price, expiry_date, stock_place]
 */
function apply_items_to_stock(PDO $pdo, int $branch_id, array $items): void {
  foreach ($items as $it) {
    $pid = (int)$it['product_id']; if ($pid<=0) continue;
    $qty = (int)$it['qty'];        if ($qty<=0) continue;

    $batchNo    = trim($it['batch_no'] ?? '');
    $costPrice  = (float)($it['cost_price'] ?? 0);
    $costPin    = isset($it['cost_pin']) ? trim($it['cost_pin']) : null;
    $sellPrice  = (float)($it['selling_price'] ?? 0);
    $expiry     = trim($it['expiry_date'] ?? '');
    $stockPlace = trim($it['stock_place'] ?? '');
    $stockPlaceParam = ($stockPlace === '') ? null : $stockPlace;

    // find batch
    $chk = $pdo->prepare("SELECT id FROM stock_batches WHERE product_id=:p AND branch_id=:b AND batch_no=:bn LIMIT 1");
    $chk->execute([':p'=>$pid, ':b'=>$branch_id, ':bn'=>$batchNo]);
    $ex = $chk->fetch(PDO::FETCH_ASSOC);

    if ($ex) {
      // Update (if stock_place provided, override; else keep old)
      $upd = $pdo->prepare("UPDATE stock_batches
                            SET quantity = quantity + :q,
                                cost_price=:cp, cost_pin=:cpin, selling_price=:sp,
                                expiry_date=:exd,
                                stock_place = COALESCE(:place, stock_place)
                            WHERE id=:id");
      $upd->execute([
        ':q'=>$qty, ':cp'=>$costPrice, ':cpin'=>$costPin, ':sp'=>$sellPrice,
        ':exd'=>($expiry?:null), ':place'=>$stockPlaceParam, ':id'=>$ex['id']
      ]);
    } else {
      // Insert
      $ins = $pdo->prepare("INSERT INTO stock_batches
        (product_id, branch_id, batch_no, quantity, cost_price, cost_pin, selling_price, expiry_date, stock_place, created_at)
        VALUES (:p,:b,:bn,:q,:cp,:cpin,:sp,:exd,:place,NOW())");
      $ins->execute([
        ':p'=>$pid, ':b'=>$branch_id, ':bn'=>$batchNo, ':q'=>$qty, ':cp'=>$costPrice,
        ':cpin'=>$costPin, ':sp'=>$sellPrice, ':exd'=>($expiry?:null), ':place'=>$stockPlaceParam
      ]);
    }
  }
}

/** Reverse previously applied purchase items from stock (subtract quantities) */
function reverse_items_from_stock(PDO $pdo, int $branch_id, array $items): void {
  foreach ($items as $it) {
    $pid = (int)$it['product_id']; if ($pid<=0) continue;
    $qty = (int)$it['quantity'];   if ($qty<=0) continue;
    $batchNo = trim($it['batch_no'] ?? '');

    $chk = $pdo->prepare("SELECT id FROM stock_batches WHERE product_id=:p AND branch_id=:b AND batch_no=:bn LIMIT 1");
    $chk->execute([':p'=>$pid, ':b'=>$branch_id, ':bn'=>$batchNo]);
    $ex = $chk->fetch(PDO::FETCH_ASSOC);
    if ($ex) {
      $upd = $pdo->prepare("UPDATE stock_batches SET quantity = GREATEST(quantity - :q, 0) WHERE id=:id");
      $upd->execute([':q'=>$qty, ':id'=>$ex['id']]);
    }
  }
}

/* ------------------------------ Request routing (Add / Edit) ------------------------------ */

$is_edit_mode = false;
$edit_purchase = null;
$edit_items = [];

// Enter edit mode if ?edit_id=...
if (isset($_GET['edit_id']) && ctype_digit($_GET['edit_id'])) {
  $edit_id = (int)$_GET['edit_id'];
  $edit_purchase = fetch_purchase($pdo, $edit_id);
  if ($edit_purchase) {
    // permission: non-admin must only edit their branch
    if (!$is_admin && (int)$edit_purchase['branch_id'] !== $current_branch_id) {
      $message = 'You cannot edit a purchase from another branch.';
    } else {
      $is_edit_mode = true;
      $edit_items = fetch_purchase_items($pdo, $edit_id);
    }
  } else {
    $message = 'Purchase not found.';
  }
}

/* ------------------------------ Save: Add (purchase) / Opening Stock ------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['purchase']) && !$is_edit_mode) {
  $branch_id   = (int)($_POST['branch_id'] ?? 0);
  $supplier_id = isset($_POST['supplier_id']) && $_POST['supplier_id'] !== '' ? (int)$_POST['supplier_id'] : null;
  $is_opening  = !empty($_POST['is_opening']);

  $pids  = $_POST['product_id'] ?? [];
  $qtys  = $_POST['quantity'] ?? [];
  $bnos  = $_POST['batch_no'] ?? [];
  $cps   = $_POST['cost_price'] ?? [];
  $pins  = $_POST['cost_pin'] ?? [];
  $sps   = $_POST['selling_price'] ?? [];
  $exps  = $_POST['expiry_date'] ?? [];
  $places= $_POST['stock_place'] ?? [];

  // any valid item?
  $hasValidItem = false;
  foreach ($pids as $i=>$pid) {
    $pid=(int)$pid; $q=(int)($qtys[$i]??0);
    if ($pid>0 && $q>0){ $hasValidItem=true; break; }
  }

  if ($branch_id>0 && $hasValidItem) {
    if ($is_opening) {
      // OPENING: direct stock + journal
      $pdo->beginTransaction();
      try {
        $total_cost = 0.0;
        $items_to_apply = [];
        foreach ($pids as $i=>$pid) {
          $pid=(int)$pid; $q=(int)($qtys[$i]??0); if ($pid<=0||$q<=0) continue;
          $cp=(float)($cps[$i]??0); $sp=(float)($sps[$i]??0);
          $items_to_apply[] = [
            'product_id'=>$pid,
            'batch_no'=>trim($bnos[$i]??''),
            'qty'=>$q,
            'cost_price'=>$cp,
            'cost_pin'=>isset($pins[$i])?trim($pins[$i]):null,
            'selling_price'=>$sp,
            'expiry_date'=>trim($exps[$i]??''),
            'stock_place'=>trim($places[$i]??'')
          ];
          $total_cost += $q*$cp;
    }
        apply_items_to_stock($pdo, $branch_id, $items_to_apply);

        if ($total_cost>0) {
          $pdo->prepare("CREATE TABLE IF NOT EXISTS journal_entries (
              id INT AUTO_INCREMENT PRIMARY KEY,
              branch_id INT NOT NULL,
              entry_date DATE NOT NULL,
              account_type ENUM('stock','cash','supplier','customer','other','bank','wallet') NOT NULL,
              amount DECIMAL(12,2) NOT NULL,
              description VARCHAR(255) DEFAULT NULL,
              created_by INT NOT NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4")->execute();

          $stmtJe = $pdo->prepare("INSERT INTO journal_entries (branch_id, entry_date, account_type, amount, description, created_by)
                                   VALUES (:b,:d,'stock',:a,:desc,:u)");
          $stmtJe->execute([
            ':b'=>$branch_id, ':d'=>date('Y-m-d'),
            ':a'=>$total_cost, ':desc'=>'Opening stock recorded from purchase page', ':u'=>$user['id']
          ]);
        }
        $pdo->commit();
        $message = 'Opening stock recorded and inventory updated!';
      } catch(Throwable $e){
        $pdo->rollBack();
        $message = 'Failed to record opening stock: '.$e->getMessage();
      }
    } else {
      // NORMAL PURCHASE
      $pdo->beginTransaction();
      try {
        $st = $pdo->prepare("INSERT INTO purchases (branch_id, supplier_id, purchase_date, purchased_by)
                             VALUES (:b,:s,NOW(),:u)");
        $st->execute([':b'=>$branch_id, ':s'=>$supplier_id, ':u'=>$user['id']]);
        $purchase_id = (int)$pdo->lastInsertId();

        $items_to_apply = [];
        foreach ($pids as $i=>$pid) {
          $pid=(int)$pid; $q=(int)($qtys[$i]??0); if ($pid<=0||$q<=0) continue;
          $bn = trim($bnos[$i]??'');
          $cp = (float)($cps[$i]??0);
          $pin= isset($pins[$i])?trim($pins[$i]):null;
          $sp = (float)($sps[$i]??0);
          $ex = trim($exps[$i]??'');
          $pl = trim($places[$i]??'');

          $ins = $pdo->prepare("INSERT INTO purchase_items
            (purchase_id, product_id, batch_no, quantity, cost_price, cost_pin, selling_price, expiry_date, stock_place)
            VALUES (:p,:pr,:bn,:q,:cp,:pin,:sp,:ex,:pl)");
          $ins->execute([
            ':p'=>$purchase_id, ':pr'=>$pid, ':bn'=>$bn, ':q'=>$q, ':cp'=>$cp, ':pin'=>$pin, ':sp'=>$sp,
            ':ex'=>($ex?:null), ':pl'=>($pl?:null)
          ]);

          $items_to_apply[] = [
            'product_id'=>$pid,'batch_no'=>$bn,'qty'=>$q,
            'cost_price'=>$cp,'cost_pin'=>$pin,'selling_price'=>$sp,
            'expiry_date'=>$ex,'stock_place'=>$pl
          ];
        }

        apply_items_to_stock($pdo, $branch_id, $items_to_apply);

        $pdo->commit();
        $message = 'Purchase recorded and stock updated!';
      } catch(Throwable $e){
        $pdo->rollBack();
        $message = 'Failed to save purchase: '.$e->getMessage();
      }
    }
  } else {
    $message = 'Please fill all required fields.';
  }
}

/* ------------------------------ Save: EDIT existing purchase ------------------------------ */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save_edit']) && isset($_POST['edit_purchase_id'])) {
  $purchase_id = (int)$_POST['edit_purchase_id'];
  $purchase = fetch_purchase($pdo, $purchase_id);
  if (!$purchase) {
    $message = 'Purchase not found or already removed.';
  } else {
    if (!$is_admin && (int)$purchase['branch_id'] !== $current_branch_id) {
      $message = 'You cannot edit a purchase from another branch.';
    } else {
      // new header values
      $branch_id   = (int)($_POST['branch_id'] ?? $purchase['branch_id']);
      $supplier_id = isset($_POST['supplier_id']) && $_POST['supplier_id']!=='' ? (int)$_POST['supplier_id'] : null;

      $pids  = $_POST['product_id'] ?? [];
      $qtys  = $_POST['quantity'] ?? [];
      $bnos  = $_POST['batch_no'] ?? [];
      $cps   = $_POST['cost_price'] ?? [];
      $pins  = $_POST['cost_pin'] ?? [];
      $sps   = $_POST['selling_price'] ?? [];
      $exps  = $_POST['expiry_date'] ?? [];
      $places= $_POST['stock_place'] ?? [];

      // build items array from POST
      $new_items = [];
      foreach ($pids as $i=>$pid) {
        $pid=(int)$pid; $q=(int)($qtys[$i]??0); if ($pid<=0||$q<=0) continue;
        $new_items[] = [
          'product_id'=>$pid,
          'batch_no'=>trim($bnos[$i]??''),
          'qty'=>$q,
          'cost_price'=>(float)($cps[$i]??0),
          'cost_pin'=>isset($pins[$i])?trim($pins[$i]):null,
          'selling_price'=>(float)($sps[$i]??0),
          'expiry_date'=>trim($exps[$i]??''),
          'stock_place'=>trim($places[$i]??'')
        ];
      }

      if (empty($new_items)) {
        $message = 'At least one item with positive quantity is required.';
      } else {
        $pdo->beginTransaction();
        try {
          // 1) reverse existing items from stock
          $old_items = fetch_purchase_items($pdo, $purchase_id);
          reverse_items_from_stock($pdo, (int)$purchase['branch_id'], $old_items);

          // 2) update header (supplier/branch)
          $uph = $pdo->prepare("UPDATE purchases SET branch_id=:b, supplier_id=:s WHERE id=:id");
          $uph->execute([':b'=>$branch_id, ':s'=>$supplier_id, ':id'=>$purchase_id]);

          // 3) delete old items and insert new ones
          $pdo->prepare("DELETE FROM purchase_items WHERE purchase_id=:id")->execute([':id'=>$purchase_id]);

          foreach ($new_items as $ni) {
            $ins = $pdo->prepare("INSERT INTO purchase_items
              (purchase_id, product_id, batch_no, quantity, cost_price, cost_pin, selling_price, expiry_date, stock_place)
              VALUES (:p,:pr,:bn,:q,:cp,:pin,:sp,:ex,:pl)");
            $ins->execute([
              ':p'=>$purchase_id,
              ':pr'=>$ni['product_id'],
              ':bn'=>$ni['batch_no'],
              ':q'=>$ni['qty'],
              ':cp'=>$ni['cost_price'],
              ':pin'=>$ni['cost_pin'],
              ':sp'=>$ni['selling_price'],
              ':ex'=>($ni['expiry_date']?:null),
              ':pl'=>($ni['stock_place']?:null),
            ]);
          }

          // 4) apply new items to stock (note: if branch changed, apply to new branch)
          apply_items_to_stock($pdo, $branch_id, $new_items);

          $pdo->commit();
          $message = 'Purchase updated and stock adjusted!';
          // refresh edit state
          $edit_purchase = fetch_purchase($pdo, $purchase_id);
          $edit_items = fetch_purchase_items($pdo, $purchase_id);
          $is_edit_mode = true;
        } catch(Throwable $e){
          $pdo->rollBack();
          $message = 'Failed to update purchase: '.$e->getMessage();
          $is_edit_mode = true;
        }
      }
    }
  }
}

/* ------------------------------ Recent purchases (list below) ------------------------------ */
$filter_branch_id = $is_admin ? (int)($_GET['list_branch_id'] ?? ($branches[0]['id'] ?? 0)) : $current_branch_id;
$rp = $pdo->prepare("
  SELECT p.id, p.purchase_date, COALESCE(s.name,'(No Supplier)') AS supplier_name, b.name AS branch_name,
         COALESCE(SUM(pi.quantity * pi.cost_price),0) AS total_cost,
         COUNT(pi.id) AS item_count
  FROM purchases p
  LEFT JOIN suppliers s ON s.id=p.supplier_id
  JOIN branches b ON b.id=p.branch_id
  LEFT JOIN purchase_items pi ON pi.purchase_id=p.id
  WHERE p.branch_id = :b
  GROUP BY p.id, p.purchase_date, supplier_name, branch_name
  ORDER BY p.purchase_date DESC, p.id DESC
  LIMIT 50
");
$rp->execute([':b'=>$filter_branch_id]);
$recent_purchases = $rp->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Purchase Stock - POS System</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    /* Custom styles for the live search on purchase page */
    #purchaseSearchResults .search-item { cursor: pointer; background-color:#fff; }
    #purchaseSearchResults .search-item:hover { background-color:#f5f5f5; }
    body{background:#f6f7fb;font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,'Helvetica Neue',Arial;}
    .search-results .search-item{cursor:pointer}
    .badge-ghost{background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;padding:6px 10px;color:#374151}
    .card-title small{font-weight:normal}
  </style>
</head>
<body>
<?php include 'includes/nav.php'; ?>
<div class="container my-4">

  <div class="d-flex align-items-center gap-2 mb-3">
    <h1 class="mb-0"><?php echo $is_edit_mode ? 'Edit Purchase' : 'Purchase Stock'; ?></h1>
    <?php if ($is_edit_mode): ?>
      <span class="badge-ghost">#<?php echo (int)$edit_purchase['id']; ?> | <?php echo htmlspecialchars($edit_purchase['branch_name']); ?></span>
      <span class="badge-ghost"><?php echo htmlspecialchars(date('d-M-Y H:i', strtotime($edit_purchase['purchase_date']))); ?></span>
    <?php endif; ?>
  </div>

  <?php if ($message): ?>
    <div class="alert alert-info"><?php echo htmlspecialchars($message); ?></div>
  <?php endif; ?>

  <div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
      <span class="card-title mb-0"><?php echo $is_edit_mode ? 'Update Purchase' : 'Add Stock'; ?>
        <?php if ($is_edit_mode): ?>
          <small class="text-muted ms-2">Adjust items below and save — stock will be updated safely.</small>
        <?php endif; ?>
      </span>
      <?php if ($is_edit_mode): ?>
        <a href="purchase.php" class="btn btn-sm btn-secondary">Exit Edit</a>
      <?php endif; ?>
    </div>
    <div class="card-body">
      <form method="post" id="purchaseForm">
        <?php if ($is_edit_mode): ?>
          <input type="hidden" name="edit_purchase_id" value="<?php echo (int)$edit_purchase['id']; ?>">
        <?php endif; ?>

        <!-- Existing batch information for selected product will appear here -->
        <div id="batchInfo" class="mb-3" style="display:none;"></div>

        <!-- Basic details: branch and supplier -->
        <div class="row g-3">
          <?php if ($is_admin): ?>
            <div class="col-md-3">
              <label class="form-label">Branch</label>
              <select name="branch_id" class="form-select" required <?php echo $is_edit_mode ? '' : ''; ?>>
                <option value="">Select Branch</option>
                <?php foreach ($branches as $br): ?>
                  <option value="<?php echo (int)$br['id']; ?>" <?php
                    $selBranch = $is_edit_mode ? (int)$edit_purchase['branch_id'] : ($branches[0]['id'] ?? 0);
                    echo ((int)$br['id']===$selBranch)?'selected':'';
                  ?>><?php echo htmlspecialchars($br['name']); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          <?php else: ?>
            <input type="hidden" name="branch_id" value="<?php echo $is_edit_mode ? (int)$edit_purchase['branch_id'] : (int)$current_branch_id; ?>">
          <?php endif; ?>
          <div class="col-md-3">
            <label class="form-label">Supplier</label>
            <select name="supplier_id" class="form-select" required>
              <option value="">Select Supplier</option>
              <?php foreach ($suppliers as $sup): ?>
                <option value="<?php echo (int)$sup['id']; ?>" <?php
                  $selSup = $is_edit_mode ? (int)$edit_purchase['supplier_id'] : 0;
                  echo ((int)$sup['id']===$selSup)?'selected':'';
                ?>><?php echo htmlspecialchars($sup['name']); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <!-- Dynamic purchase items table -->
        <div class="table-responsive mt-4">
          <table class="table table-bordered align-middle">
            <thead class="table-light">
              <tr>
                <th style="min-width:200px;">Product</th>
                <th style="width:80px;">Qty</th>
                <th style="width:120px;">Batch No</th>
                <th style="width:120px;">Cost Price</th>
                <th style="width:120px;">Cost Pin</th>
                <th style="width:120px;">Selling Price</th>
                <th style="width:140px;">Expiry Date</th>
                <th style="width:160px;">Stock Place</th>
                <th style="width:80px;">Action</th>
              </tr>
            </thead>
            <tbody id="purchaseRows"></tbody>
          </table>
          <button type="button" id="addRow" class="btn btn-secondary btn-sm mt-2">Add Item</button>
        </div>

        <div class="mt-3">
          <?php if (!$is_edit_mode): ?>
            <!-- Opening stock checkbox only in add mode -->
            <div class="form-check mb-2">
              <input class="form-check-input" type="checkbox" id="isOpening" name="is_opening" value="1">
              <label class="form-check-label" for="isOpening">
                Mark as Opening Stock (do not create purchase record)
              </label>
            </div>
            <button type="submit" name="purchase" class="btn btn-primary">Add Stock</button>
          <?php else: ?>
            <button type="submit" name="save_edit" class="btn btn-primary">Save Changes</button>
          <?php endif; ?>
        </div>
      </form>
    </div>
  </div>

  <!-- Recent Purchases -->
  <div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
      <span class="card-title mb-0">Recent Purchases</span>
      <form class="d-flex align-items-center gap-2" method="get" action="purchase.php">
        <?php if ($is_admin): ?>
          <select name="list_branch_id" class="form-select form-select-sm" onchange="this.form.submit()">
            <?php foreach ($branches as $br): ?>
              <option value="<?php echo (int)$br['id']; ?>" <?php echo ((int)$br['id']===$filter_branch_id)?'selected':''; ?>>
                <?php echo htmlspecialchars($br['name']); ?>
              </option>
            <?php endforeach; ?>
          </select>
        <?php endif; ?>
        <?php if ($is_edit_mode): ?>
          <input type="hidden" name="edit_id" value="<?php echo (int)$edit_purchase['id']; ?>">
        <?php endif; ?>
      </form>
    </div>
    <div class="card-body">
      <div class="table-responsive">
        <table class="table table-striped table-hover align-middle">
          <thead class="table-light">
            <tr>
              <th>ID</th>
              <th>Date</th>
              <th>Supplier</th>
              <th>Branch</th>
              <th class="text-end">Items</th>
              <th class="text-end">Total Cost</th>
              <th style="width:120px">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($recent_purchases)): ?>
              <tr><td colspan="7" class="text-center text-muted">No purchases yet.</td></tr>
            <?php else: foreach ($recent_purchases as $r): ?>
              <tr>
                <td><?php echo (int)$r['id']; ?></td>
                <td><?php echo htmlspecialchars(date('d-M-Y H:i', strtotime($r['purchase_date']))); ?></td>
                <td><?php echo htmlspecialchars($r['supplier_name']); ?></td>
                <td><?php echo htmlspecialchars($r['branch_name']); ?></td>
                <td class="text-end"><?php echo (int)$r['item_count']; ?></td>
                <td class="text-end"><?php echo rs($r['total_cost']); ?></td>
                <td>
                  <a class="btn btn-sm btn-warning" href="purchase.php?edit_id=<?php echo (int)$r['id']; ?>">Edit</a>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ====== Live search & dynamic rows ======
const purchaseProducts = <?php echo json_encode($products, JSON_UNESCAPED_UNICODE); ?>;
let rowCounter = 0;
let lastSelectedRowIndex = null;
let lastSelectedProductId = null;

// Prefill rows if we're in EDIT MODE:
const isEdit = <?php echo $is_edit_mode ? 'true' : 'false'; ?>;
const editItems = <?php echo $is_edit_mode ? json_encode($edit_items, JSON_UNESCAPED_UNICODE) : '[]'; ?>;

function addPurchaseRow(prefill=null){
  const tbody = document.getElementById('purchaseRows');
  const rowIndex = rowCounter++;
  const tr = document.createElement('tr');
  tr.setAttribute('data-row-index', rowIndex);

  const v = (k, d='') => prefill && prefill[k]!=null ? prefill[k] : d;

  tr.innerHTML = `
    <td>
      <div class="search-container position-relative">
        <input type="text" class="form-control product-search" id="productSearch_${rowIndex}" placeholder="Search product" autocomplete="off" value="${prefill? (prefill.product_name||''): ''}">
        <div class="search-results" id="searchResults_${rowIndex}" style="position:absolute;top:100%;left:0;right:0;background:#fff;border:1px solid #ddd;border-top:none;border-radius:0 0 5px 5px;max-height:250px;overflow-y:auto;z-index:1000;box-shadow:0 4px 10px rgba(0,0,0,.1);display:none"></div>
      </div>
      <input type="hidden" name="product_id[]" id="productId_${rowIndex}" required value="${v('product_id','')}">
    </td>
    <td><input type="number" name="quantity[]" class="form-control" min="1" required value="${v('quantity', v('qty',''))}"></td>
    <td><input type="text" name="batch_no[]" class="form-control" required value="${v('batch_no','')}"></td>
    <td><input type="number" step="0.01" name="cost_price[]" class="form-control" required value="${v('cost_price','')}"></td>
    <td><input type="text" name="cost_pin[]" class="form-control" value="${v('cost_pin','')}"></td>
    <td><input type="number" step="0.01" name="selling_price[]" class="form-control" required value="${v('selling_price','')}"></td>
    <td><input type="date" name="expiry_date[]" class="form-control" value="${v('expiry_date','')}"></td>
    <td><input type="text" name="stock_place[]" class="form-control" placeholder="Rack/Shelf/Bin" value="${v('stock_place','')}"></td>
    <td class="text-center"><button type="button" class="btn btn-sm btn-danger remove-row">Remove</button></td>
  `;
  tbody.appendChild(tr);
  attachSearchEvents(rowIndex, prefill ? prefill.product_id : null);
  attachBatchAutoFill(rowIndex);
}

function attachSearchEvents(index, presetProductId=null){
  const searchInput = document.getElementById('productSearch_'+index);
  const resultsDiv  = document.getElementById('searchResults_'+index);
  const hiddenId    = document.getElementById('productId_'+index);

  // If edit preset product, set its name
  if (presetProductId){
    const prod = purchaseProducts.find(x=> x.id == presetProductId);
    if (prod){ searchInput.value = prod.name; hiddenId.value = prod.id; }
  }

  searchInput.addEventListener('input', function(){
    const q = this.value.trim().toLowerCase();
    if(q.length<1){ resultsDiv.style.display='none'; hiddenId.value=''; return; }
    const matches = purchaseProducts.filter(p=>{
      const nm = (p.name||'').toLowerCase().includes(q);
      const cd = String(p.id).toLowerCase().includes(q);
      const bc = (p.barcode||'').toLowerCase().includes(q);
      return nm||cd||bc;
    });
    if(!matches.length){
      resultsDiv.innerHTML = '<div class="search-item p-2">No products found</div>';
      resultsDiv.style.display='block';
      return;
    }
    resultsDiv.innerHTML = matches.map(p=>`
      <div class="search-item p-2 border-bottom" data-product-id="${p.id}">
        <div><strong>${p.name}</strong> <small class="text-muted">(ID: ${p.id})</small><br><small class="text-muted">Barcode: ${p.barcode || ''}</small></div>
      </div>
    `).join('');
    resultsDiv.style.display='block';
    Array.from(resultsDiv.querySelectorAll('.search-item')).forEach(it=>{
      it.addEventListener('click', ()=>{
        const pid = parseInt(it.getAttribute('data-product-id'));
        const prod = purchaseProducts.find(x=>x.id===pid);
        if(prod){
          searchInput.value = prod.name;
          hiddenId.value = prod.id;
          resultsDiv.style.display='none';
          lastSelectedRowIndex = index;
          lastSelectedProductId = prod.id;
          loadExistingBatches(index, prod.id);
        }
      });
    });
  });
}

// hide all dropdowns when clicking elsewhere
document.addEventListener('click', e=>{
  if (!e.target.closest('.search-container')) {
    document.querySelectorAll('.search-results').forEach(div=> div.style.display='none');
  }
});

// fetch & show existing batches box for last clicked product
function loadExistingBatches(rowIndex, productId){
  const container = document.getElementById('batchInfo');
  let branchId = null;
  const branchSelect = document.querySelector('select[name="branch_id"]');
  if (branchSelect) branchId = branchSelect.value;
  else {
    const hb = document.querySelector('input[name="branch_id"]'); branchId = hb? hb.value : null;
  }
  if(!branchId || !productId){
    container.style.display='none'; container.innerHTML=''; return;
  }
  fetch('ajax_get_product_batches.php?product_id='+productId+'&branch_id='+branchId)
  .then(r=>r.json())
  .then(data=>{
    if(!data || !Array.isArray(data.batches) || data.batches.length===0){
      container.innerHTML = '<div class="alert alert-info">No existing batches for this product in the selected branch.</div>';
      container.style.display = 'block';
      return;
    }
    let html = '<div class="card"><div class="card-header"><strong>Existing Batches</strong></div><div class="card-body p-2"><div class="table-responsive"><table class="table table-sm table-bordered mb-0"><thead><tr><th>Batch No</th><th>Quantity</th><th>Cost Price</th><th>Selling Price</th><th>Expiry Date</th><th>Stock Place</th></tr></thead><tbody>';
    data.batches.forEach(b=>{
      html += `<tr><td>${b.batch_no || 'N/A'}</td><td>${b.quantity}</td><td>Rs. ${Number(b.cost_price).toFixed(2)}</td><td>Rs. ${Number(b.selling_price).toFixed(2)}</td><td>${b.expiry_date || 'N/A'}</td><td>${b.stock_place || ''}</td></tr>`;
    });
    html += '</tbody></table></div></div></div>';
    container.innerHTML = html;
    container.style.display = 'block';
  })
  .catch(err=> console.error('Failed to load existing batches', err));
}

document.addEventListener('DOMContentLoaded', ()=>{
  const tbody = document.getElementById('purchaseRows');

  // initialize rows
  if (isEdit && editItems.length){
    editItems.forEach(it=>{
      // map server fields to prefill keys expected by addPurchaseRow
      addPurchaseRow({
        product_id: it.product_id,
        product_name: '', // will be set by preset handler
        quantity: it.quantity,
        batch_no: it.batch_no,
        cost_price: it.cost_price,
        cost_pin: it.cost_pin,
        selling_price: it.selling_price,
        expiry_date: it.expiry_date || '',
        stock_place: it.stock_place || ''
      });
    });
  } else {
    addPurchaseRow();
  }

  document.getElementById('addRow').addEventListener('click', ()=> addPurchaseRow());

  tbody.addEventListener('click', e=>{
    if (e.target.classList.contains('remove-row')) {
      const tr = e.target.closest('tr');
      if (tr) {
        const idx = parseInt(tr.getAttribute('data-row-index'));
        if (idx === lastSelectedRowIndex) {
          lastSelectedRowIndex = null; lastSelectedProductId = null;
          const container = document.getElementById('batchInfo');
          container.style.display='none'; container.innerHTML='';
        }
        tr.remove();
      }
    }
  });

  const branchSelect = document.querySelector('select[name="branch_id"]');
  if (branchSelect) {
    branchSelect.addEventListener('change', ()=>{
      if (lastSelectedProductId) loadExistingBatches(lastSelectedRowIndex, lastSelectedProductId);
      else { const c=document.getElementById('batchInfo'); c.style.display='none'; c.innerHTML=''; }
    });
  }
});
</script>
<script>
function getSelectedBranchId(){
  const sel = document.querySelector('select[name="branch_id"]');
  if (sel) return sel.value || null;
  const hid = document.querySelector('input[name="branch_id"]');
  return hid ? (hid.value || null) : null;
}

function attachBatchAutoFill(rowIndex){
  const row = document.querySelector('tr[data-row-index="'+rowIndex+'"]');
  if (!row) return;

  const productIdEl   = row.querySelector('#productId_'+rowIndex);
  const batchInput    = row.querySelector('input[name="batch_no[]"]');
  const qtyInput      = row.querySelector('input[name="quantity[]"]');
  const costInput     = row.querySelector('input[name="cost_price[]"]');
  const pinInput      = row.querySelector('input[name="cost_pin[]"]');
  const sellInput     = row.querySelector('input[name="selling_price[]"]');
  const expiryInput   = row.querySelector('input[name="expiry_date[]"]');
  const placeInput    = row.querySelector('input[name="stock_place[]"]');

  async function tryLookup(){
    const pid = productIdEl ? parseInt(productIdEl.value || '0') : 0;
    const bn  = (batchInput.value || '').trim();
    const bid = getSelectedBranchId();

    if (!pid || !bn || !bid) return;

    try {
      const res = await fetch(`ajax_lookup_batch.php?product_id=${pid}&branch_id=${bid}&batch_no=${encodeURIComponent(bn)}`);
      const data = await res.json();

      if (data && data.ok && data.data) {
        const d = data.data;

        // Only replace Qty if it is empty or 0 (so we don't override user input)
        if (!qtyInput.value || Number(qtyInput.value) === 0) {
          qtyInput.value = d.quantity ?? '';
        }
        costInput.value   = (d.cost_price    != null) ? Number(d.cost_price).toFixed(2) : '';
        sellInput.value   = (d.selling_price != null) ? Number(d.selling_price).toFixed(2) : '';
        pinInput.value    = (d.cost_pin      != null) ? d.cost_pin : '';
        expiryInput.value = d.expiry_date    || '';
        placeInput.value  = d.stock_place    || '';

        // Optional: brief highlight
        [qtyInput,costInput,pinInput,sellInput,expiryInput,placeInput].forEach(el=>{
          el.classList.add('is-valid');
          setTimeout(()=>el.classList.remove('is-valid'), 800);
        });
      }
    } catch(e){ console.error('Batch lookup failed', e); }
  }

  // Trigger on blur/change of Batch No
  batchInput.addEventListener('change', tryLookup);
  batchInput.addEventListener('blur',   tryLookup);
}
</script>

</body>
</html>
