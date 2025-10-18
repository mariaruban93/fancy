<?php
/**
 * stock_report.php
 * Dashboard-style stock summary + detailed batch list.
 * Uses cost value for totals at the top.
 * Adds inline Edit (modal) to update stock_batches safely.
 */

require_once 'includes/header.php';
if (function_exists('checkRole')) { checkRole(['admin','manager']); }

$message = '';
$message_type = 'info';

/* ------------------ Handle Update (POST) ------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_batch'])) {
  if (function_exists('csrf_check')) { csrf_check(); }

  $batch_id    = (int)($_POST['batch_id'] ?? 0);
  $quantity    = max(0, (int)($_POST['quantity'] ?? 0));
  $cost_price  = max(0, (float)($_POST['cost_price'] ?? 0));
  $sell_price  = max(0, (float)($_POST['selling_price'] ?? 0));
  $stock_place = trim($_POST['stock_place'] ?? '');
  $exp_raw     = trim($_POST['expiry_date'] ?? '');
  $expiry_date = ($exp_raw === '') ? null : $exp_raw;

  try {
    if ($batch_id <= 0) { throw new Exception('Invalid batch.'); }

    $params = [
      ':q'  => $quantity,
      ':c'  => $cost_price,
      ':s'  => $sell_price,
      ':e'  => $expiry_date,
      ':sp' => $stock_place,
      ':id' => $batch_id,
    ];

    // Managers can only update within their branch
    $sql = "UPDATE stock_batches
            SET quantity=:q, cost_price=:c, selling_price=:s, expiry_date=:e, stock_place=:sp
            WHERE id=:id";

    if (($user['role_name'] ?? '') !== 'admin') {
      $sql .= " AND branch_id=:b";
      $params[':b'] = (int)($user['branch_id'] ?? 0);
    }

    $st = $pdo->prepare($sql);
    $st->execute($params);

    if ($st->rowCount() === 0) {
      throw new Exception('No rows updated (permission/branch mismatch or no change).');
    }

    $message = 'Stock batch updated successfully.';
    $message_type = 'success';
  } catch (Throwable $e) {
    $message = 'Update failed: ' . $e->getMessage();
    $message_type = 'danger';
  }
}

/* ------------------ Filters / Branch scope ------------------ */
$branch_id = isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : 0;
if (($user['role_name'] ?? '') !== 'admin') { $branch_id = (int)($user['branch_id'] ?? 0); }

$branches = $pdo->query("SELECT id, name FROM branches ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

/* ------------------ Load rows ------------------ */
$sql = "SELECT b.name AS branch_name,
               p.name AS product_name,
               COALESCE(c.name,'') AS category_name,
               COALESCE(br.name,'') AS brand_name,
               COALESCE(u.name,'') AS unit_name,
               sb.id AS batch_id, sb.product_id,
               sb.batch_no, sb.quantity, sb.cost_price, sb.selling_price,
               sb.expiry_date, sb.stock_place
        FROM stock_batches sb
        JOIN products p   ON p.id=sb.product_id
        JOIN branches b   ON b.id=sb.branch_id
        LEFT JOIN categories c ON c.id=p.category_id
        LEFT JOIN brands br    ON br.id=p.brand_id
        LEFT JOIN units u      ON u.id=p.unit_id";
$params=[];
if ($branch_id) { $sql .= " WHERE sb.branch_id=?"; $params[]=$branch_id; }
$sql .= " ORDER BY b.name,p.name,sb.batch_no";

$st=$pdo->prepare($sql); $st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

/* ------------------ Totals & category list ------------------ */
$total_qty=0; $total_value=0.0; $categories=[];
foreach($rows as $r){
  $total_qty  += (int)$r['quantity'];
  $total_value += (float)$r['quantity'] * (float)$r['cost_price'];
  if ($r['category_name'] !== '' && !in_array($r['category_name'],$categories, true)) {
    $categories[] = $r['category_name'];
  }
}
sort($categories);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Stock Report</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css">
  <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.bootstrap5.min.css">
</head>
<body>
<?php include 'includes/nav.php'; ?>
<div class="container my-4">
  <h3 class="mb-3">Stock Report</h3>

  <?php if (!empty($message)): ?>
    <div class="alert alert-<?php echo htmlspecialchars($message_type); ?> my-2">
      <?php echo htmlspecialchars($message); ?>
    </div>
  <?php endif; ?>

  <?php if (($user['role_name'] ?? '') === 'admin'): ?>
    <form class="row g-2 align-items-end mb-3" method="get">
      <div class="col-md-6">
        <label class="form-label">Branch</label>
        <select name="branch_id" class="form-select">
          <option value="0">All (admin)</option>
          <?php foreach($branches as $b): ?>
            <option value="<?php echo (int)$b['id']; ?>" <?php echo ($branch_id==$b['id'])?'selected':''; ?>>
              <?php echo htmlspecialchars($b['name']); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-6"><button class="btn btn-primary w-100">Filter</button></div>
    </form>
  <?php else: ?>
    <div class="row g-2 align-items-end mb-3">
      <div class="col-md-6">
        <label class="form-label">Branch</label>
        <input type="text" class="form-control" value="<?php
          foreach($branches as $b){ if($b['id']==$branch_id) { echo htmlspecialchars($b['name']); break; } }
        ?>" readonly>
      </div>
    </div>
  <?php endif; ?>

  <div class="row g-3 mb-3">
    <div class="col-md-6">
      <div class="p-3 border rounded bg-white h-100">
        <div class="text-muted">Total Qty</div>
        <div class="fs-4 fw-bold"><?php echo number_format($total_qty); ?></div>
      </div>
    </div>
    <div class="col-md-6">
      <div class="p-3 border rounded bg-white h-100">
        <div class="text-muted">Total Value (Cost)</div>
        <div class="fs-4 fw-bold">Rs. <?php echo number_format($total_value,2); ?></div>
      </div>
    </div>
  </div>

  <!-- Category filter -->
  <div class="row mb-3">
    <div class="col-md-4">
      <label class="form-label">Filter by Category</label>
      <select id="categoryFilter" class="form-select">
        <option value="">All Categories</option>
        <?php foreach($categories as $cat): ?>
          <option value="<?php echo htmlspecialchars($cat); ?>"><?php echo htmlspecialchars($cat); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>

  <div class="table-responsive">
    <table id="tbl" class="table table-bordered table-striped">
      <thead class="table-light"><tr>
        <th>Branch</th>
        <th>Product</th>
        <th>Category</th>
        <th>Brand</th>
        <th>Unit</th>
        <th>Batch</th>
        <th class="text-end">Qty</th>
        <th class="text-end">Cost</th>
        <th class="text-end">Price</th>
        <th>Expiry</th>
        <th>Stock Place</th>
        <th class="text-end">Value</th>
        <th>Action</th>
      </tr></thead>
      <tbody>
        <?php foreach($rows as $r): $val=(float)$r['quantity']*(float)$r['cost_price']; ?>
        <tr>
          <td><?php echo htmlspecialchars($r['branch_name']); ?></td>
          <td><?php echo htmlspecialchars($r['product_name']); ?></td>
          <td><?php echo htmlspecialchars($r['category_name']); ?></td>
          <td><?php echo htmlspecialchars($r['brand_name']); ?></td>
          <td><?php echo htmlspecialchars($r['unit_name']); ?></td>
          <td><?php echo htmlspecialchars($r['batch_no']); ?></td>
          <td class="text-end"><?php echo number_format((int)$r['quantity']); ?></td>
          <td class="text-end"><?php echo number_format((float)$r['cost_price'],2); ?></td>
          <td class="text-end"><?php echo number_format((float)$r['selling_price'],2); ?></td>
          <td><?php echo htmlspecialchars($r['expiry_date']); ?></td>
          <td><?php echo htmlspecialchars($r['stock_place']); ?></td>
          <td class="text-end"><?php echo number_format($val,2); ?></td>
          <td>
            <button type="button" class="btn btn-sm btn-primary"
                    data-bs-toggle="modal" data-bs-target="#editModal"
                    data-batch-id="<?php echo (int)$r['batch_id']; ?>"
                    data-branch="<?php echo htmlspecialchars($r['branch_name']); ?>"
                    data-product="<?php echo htmlspecialchars($r['product_name']); ?>"
                    data-batchno="<?php echo htmlspecialchars($r['batch_no']); ?>"
                    data-qty="<?php echo (int)$r['quantity']; ?>"
                    data-cost="<?php echo number_format((float)$r['cost_price'],2,'.',''); ?>"
                    data-price="<?php echo number_format((float)$r['selling_price'],2,'.',''); ?>"
                    data-expiry="<?php echo htmlspecialchars($r['expiry_date']); ?>"
                    data-place="<?php echo htmlspecialchars($r['stock_place']); ?>">
              Edit
            </button>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Edit Stock Modal -->
<div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form method="post" class="modal-content">
      <?php if (function_exists('csrf_field')) { csrf_field(); } ?>
      <input type="hidden" name="update_batch" value="1">
      <input type="hidden" name="batch_id" id="em_batch_id">

      <div class="modal-header">
        <h5 class="modal-title">Edit Stock Details</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <div class="modal-body">
        <div class="mb-2">
          <label class="form-label">Branch</label>
          <input type="text" class="form-control" id="em_branch" readonly>
        </div>
        <div class="mb-2">
          <label class="form-label">Product</label>
          <input type="text" class="form-control" id="em_product" readonly>
        </div>
        <div class="mb-2">
          <label class="form-label">Batch No</label>
          <input type="text" class="form-control" id="em_batchno" readonly>
        </div>

        <div class="row g-2">
          <div class="col-6">
            <label class="form-label">Quantity</label>
            <input type="number" name="quantity" id="em_qty" class="form-control" min="0" step="1" required>
          </div>
          <div class="col-6">
            <label class="form-label">Cost Price</label>
            <input type="number" name="cost_price" id="em_cost" class="form-control" min="0" step="0.01" required>
          </div>
        </div>

        <div class="row g-2 mt-0">
          <div class="col-6">
            <label class="form-label">Selling Price</label>
            <input type="number" name="selling_price" id="em_price" class="form-control" min="0" step="0.01" required>
          </div>
          <div class="col-6">
            <label class="form-label">Expiry Date</label>
            <input type="date" name="expiry_date" id="em_expiry" class="form-control">
          </div>
        </div>

        <div class="mt-2">
          <label class="form-label">Stock Place</label>
          <input type="text" name="stock_place" id="em_place" class="form-control" maxlength="255">
        </div>

        <div class="form-text mt-2">
          Tip: Leave <em>Expiry Date</em> empty to clear it.
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary">Save changes</button>
      </div>
    </form>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>

<!-- Buttons Extension -->
<script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.bootstrap5.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>

<script>
  $(document).ready(function(){
    var table = $('#tbl').DataTable({
      dom: 'Bfrtip',
      buttons: [
        {
          extend: 'excelHtml5',
          title: 'Stock_Report',
          text: '📥 Download Excel',
          className: 'btn btn-success',
          exportOptions: {
            // exclude Action column (last column)
            columns: ':not(:last-child)'
          }
        }
      ]
    });

    // Category filter
    $('#categoryFilter').on('change', function(){
      var val = $.fn.dataTable.util.escapeRegex($(this).val());
      table.column(2).search(val ? '^'+val+'$' : '', true, false).draw();
    });
  });

  // Fill the edit modal with row data
  document.addEventListener('show.bs.modal', function (ev) {
    const modal = ev.target;
    if (modal.id !== 'editModal') return;

    const btn = ev.relatedTarget;
    if (!btn) return;

    modal.querySelector('#em_batch_id').value = btn.getAttribute('data-batch-id') || '';
    modal.querySelector('#em_branch').value   = btn.getAttribute('data-branch') || '';
    modal.querySelector('#em_product').value  = btn.getAttribute('data-product') || '';
    modal.querySelector('#em_batchno').value  = btn.getAttribute('data-batchno') || '';

    modal.querySelector('#em_qty').value   = btn.getAttribute('data-qty')   || 0;
    modal.querySelector('#em_cost').value  = btn.getAttribute('data-cost')  || '0.00';
    modal.querySelector('#em_price').value = btn.getAttribute('data-price') || '0.00';

    const expiry = btn.getAttribute('data-expiry') || '';
    modal.querySelector('#em_expiry').value = (expiry && expiry !== '0000-00-00') ? expiry : '';

    modal.querySelector('#em_place').value = btn.getAttribute('data-place') || '';
  });
</script>
</body>
</html>
