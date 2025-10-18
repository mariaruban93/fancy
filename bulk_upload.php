<?php
/**
 * bulk_upload.php
 * Upload CSV to create products + stock in one pass.
 * CSV headers (case-insensitive):
 * name,size,barcode,cost_pin,category,brand,unit,cost_price,selling_price,quantity,batch_no,branch,expiry_date
 */
require_once 'includes/header.php';
if (function_exists('checkRole')) { checkRole(['admin','manager']); }

$msg = "";

// Fetch all suppliers for optional purchase creation
$suppliers = $pdo->query("SELECT id, name FROM suppliers ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_FILES['csv']) && is_uploaded_file($_FILES['csv']['tmp_name'])) {
  // Determine whether this upload should be treated as opening stock
  $isOpening = isset($_POST['opening_stock']) && $_POST['opening_stock'] === '1';
  // Get selected supplier id (if not opening stock). Cast to int to prevent SQL injection.
  $supplierId = isset($_POST['supplier_id']) ? (int)$_POST['supplier_id'] : 0;

  $f = fopen($_FILES['csv']['tmp_name'], 'r');
  $headers = fgetcsv($f);
  
  // Clean and validate headers before flipping
  $cleanHeaders = array_map(function($header) {
    $header = trim($header);
    return !empty($header) ? $header : null;
  }, $headers);
  
  // Remove any null/empty headers and flip only valid ones
  $validHeaders = array_filter($cleanHeaders, function($header) {
    return $header !== null;
  });
  
  $map = array_change_key_case(array_flip($validHeaders), CASE_LOWER);
  
  $req = ['name','barcode','category','brand','unit','cost_price','selling_price','quantity','batch_no','branch'];
  foreach($req as $r){ 
    if(!isset($map[$r])) { 
      $msg = "Missing column: $r"; 
      break; 
    } 
  }
  
  $created=0; $updated=0;
  // We'll track purchase details per branch when not opening stock
  $purchaseData = [];
  if(!$msg){
    while(($row = fgetcsv($f)) !== false){
      $g = function($key) use($map,$row){ 
        $i = $map[strtolower($key)] ?? null; 
        return $i !== null && isset($row[$i]) ? trim($row[$i]) : null; 
      };
      
      $name = $g('name');
      // Optional size column; not required but used if present
      $size = $g('size');
      $barcode = $g('barcode');
      // Read cost_pin column (for per-batch cost identification).  We no longer store cost_pin on products.
      $cost_pin = $g('cost_pin');
      $category = $g('category');
      $brand = $g('brand');
      $unit = $g('unit');
      $cost_price = (float)$g('cost_price');
      $selling_price = (float)$g('selling_price');
      $qty = (int)$g('quantity');
      $batch_no = $g('batch_no');
      $branchName = $g('branch');
      $expiry = $g('expiry_date') ?: null;

      // Find IDs by name (create category/brand/unit if missing)
      $pdo->prepare("INSERT IGNORE INTO categories(name) VALUES(?)")->execute([$category]);
      $cat_id = $pdo->query("SELECT id FROM categories WHERE name=".$pdo->quote($category))->fetchColumn();

      $pdo->prepare("INSERT IGNORE INTO brands(name) VALUES(?)")->execute([$brand]);
      $brand_id = $pdo->query("SELECT id FROM brands WHERE name=".$pdo->quote($brand))->fetchColumn();

      $pdo->prepare("INSERT IGNORE INTO units(name) VALUES(?)")->execute([$unit]);
      $unit_id = $pdo->query("SELECT id FROM units WHERE name=".$pdo->quote($unit))->fetchColumn();

      $branch_id = $pdo->query("SELECT id FROM branches WHERE name=".$pdo->quote($branchName))->fetchColumn();
      if(!$branch_id){ $msg = "Unknown branch '$branchName' for product '$name'"; break; }

      // Upsert product by barcode (fallback by name)
      $product_id = $pdo->query("SELECT id FROM products WHERE barcode=".$pdo->quote($barcode))->fetchColumn();
      if(!$product_id){
        $product_id = $pdo->query("SELECT id FROM products WHERE name=".$pdo->quote($name))->fetchColumn();
      }
      if($product_id){
        // Update existing product fields except cost_pin (cost_pin moved to batches)
        $stmt = $pdo->prepare("UPDATE products SET name=?, size=COALESCE(?,size), category_id=?, brand_id=?, unit_id=?, cost_price=?, selling_price=? WHERE id=?");
        $stmt->execute([$name,$size,$cat_id,$brand_id,$unit_id,$cost_price,$selling_price,$product_id]);
        $updated++;
      }else{
        // Insert new product (no cost_pin) and optional size
        $stmt = $pdo->prepare("INSERT INTO products (name, size, barcode, category_id, brand_id, unit_id, cost_price, selling_price) VALUES (?,?,?,?,?,?,?,?)");
        $stmt->execute([$name,$size,$barcode,$cat_id,$brand_id,$unit_id,$cost_price,$selling_price]);
        $product_id = (int)$pdo->lastInsertId();
        $created++;
      }

      // Stock batch: merge by (product_id, branch_id, batch_no)
      $stmt = $pdo->prepare("SELECT id, quantity FROM stock_batches WHERE product_id=? AND branch_id=? AND batch_no=?");
      $stmt->execute([$product_id,$branch_id,$batch_no]);
      $ex = $stmt->fetch(PDO::FETCH_ASSOC);
      if($ex){
        $newQty = (int)$ex['quantity'] + $qty;
        // Update stock batch including cost_pin
        $pdo->prepare("UPDATE stock_batches SET quantity=?, cost_price=?, cost_pin=?, selling_price=?, expiry_date=? WHERE id=?")
            ->execute([$newQty, $cost_price, $cost_pin, $selling_price, $expiry, $ex['id']]);
      }else{
        // Insert new stock batch with cost_pin
        $pdo->prepare("INSERT INTO stock_batches (product_id, branch_id, batch_no, quantity, cost_price, cost_pin, selling_price, expiry_date, created_at) VALUES (?,?,?,?,?,?,?,?,NOW())")
            ->execute([$product_id, $branch_id, $batch_no, $qty, $cost_price, $cost_pin, $selling_price, $expiry]);
      }

      // If not opening stock, accumulate purchase details for this branch
      if (!$isOpening) {
        $lineTotal = $cost_price * $qty;
        if (!isset($purchaseData[$branch_id])) {
            $purchaseData[$branch_id] = [
                'items' => [],
                'total' => 0
            ];
        }
        $purchaseData[$branch_id]['items'][] = [
            'product_id'   => $product_id,
            'quantity'     => $qty,
            'cost_price'   => $cost_price,
            'cost_pin'     => $cost_pin,
            'selling_price'=> $selling_price,
            'batch_no'     => $batch_no,
            'expiry'       => $expiry
        ];
        $purchaseData[$branch_id]['total'] += $lineTotal;
      }
    }
    fclose($f);
    if(!$msg){
      // After processing all rows, create purchase records or journal entries
      if ($isOpening) {
        // For opening stock, create journal entry per branch summarizing total cost
        foreach ($purchaseData as $bid => $pinfo) {
            $totalCost = $pinfo['total'];
            if ($totalCost <= 0) continue;
            // Insert journal entry of type 'stock'
            $stmt = $pdo->prepare("INSERT INTO journal_entries (entry_date, branch_id, type, description, amount, created_by) VALUES (NOW(), ?, 'stock', 'Opening stock via bulk upload', ?, ?)");
            $stmt->execute([$bid, $totalCost, $user['id']]);
        }
        $msg = "Upload complete. Products created: $created, updated: $updated. Stock quantities updated as opening stock.";
      } else {
        // Create purchases per branch
        foreach ($purchaseData as $bid => $pinfo) {
            $totalCost = $pinfo['total'];
            if ($totalCost <= 0) continue;
            // Generate invoice number
            $invoice = 'BULK-' . date('YmdHis') . '-' . $bid;
            $pdo->prepare("INSERT INTO purchases (supplier_id, branch_id, purchase_date, purchased_by) VALUES (?,?,?,?)")
                ->execute([$supplierId, $bid, date('Y-m-d H:i:s'), $invoice, $totalCost]);
            $purchaseId = $pdo->lastInsertId();
            // Insert items
            foreach ($pinfo['items'] as $it) {
                $pdo->prepare("INSERT INTO purchase_items (purchase_id, product_id, quantity, cost_price, cost_pin, selling_price, batch_no, expiry_date) VALUES (?,?,?,?,?,?,?,?)")
                    ->execute([$purchaseId, $it['product_id'], $it['quantity'], $it['cost_price'], $it['cost_pin'], $it['selling_price'], $it['batch_no'], $it['expiry']]);
            }
        }
        $msg = "Upload complete. Products created: $created, updated: $updated. Purchases recorded.";
      }
    }
  }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Bulk Upload (Products + Stock)</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<?php include 'includes/nav.php'; ?>
<div class="container my-4">
  <h3>Bulk Upload (Products + Stock)</h3>
  <?php if($msg): ?><div class="alert alert-info"><?php echo htmlspecialchars($msg); ?></div><?php endif; ?>
  <div class="card">
    <div class="card-body">
      <form method="post" enctype="multipart/form-data" class="row g-3">
        <div class="col-md-4">
          <label class="form-label">Select CSV File</label>
          <input type="file" name="csv" class="form-control" accept=".csv" required>
        </div>
        <div class="col-md-3">
          <label class="form-label">Supplier (for purchases)</label>
          <select name="supplier_id" class="form-select">
            <option value="0">-- None / Unknown --</option>
            <?php foreach ($suppliers as $sup): ?>
              <option value="<?php echo $sup['id']; ?>"><?php echo htmlspecialchars($sup['name']); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3 d-flex align-items-end">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" value="1" id="openingStock" name="opening_stock">
            <label class="form-check-label" for="openingStock">
              Mark as Opening Stock
            </label>
          </div>
        </div>
        <div class="col-md-2 d-grid align-items-end">
          <button class="btn btn-primary">Upload</button>
        </div>
      </form>
      <div class="mt-3">
        <strong>CSV headers:</strong>
        <code>name, size, barcode, cost_pin, category, brand, unit, cost_price, selling_price, quantity, batch_no, branch, expiry_date</code>
        <p class="mt-2"><small>Select a supplier if the upload represents a purchase. If "Mark as Opening Stock" is checked, the quantities will be added to stock and logged in Journal Entries without creating a purchase.</small></p>
      </div>
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>