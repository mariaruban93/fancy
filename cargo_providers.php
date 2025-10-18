<?php
/**
 * cargo_providers.php
 * - Create / Edit / Activate / Deactivate cargo service providers
 * - Works with the `cargo_providers` table you already have
 *
 * Table expected:
 *   cargo_providers(
 *     id INT AI PK,
 *     name VARCHAR(150) NOT NULL,
 *     phone VARCHAR(50) NULL,
 *     address VARCHAR(255) NULL,
 *     is_active TINYINT(1) DEFAULT 1,
 *     created_by INT NOT NULL,
 *     created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
 *   )
 */

require_once 'includes/header.php';
checkRole(['admin','manager']);

$flash = null;
function set_flash($type,$msg){ $_SESSION['flash']=['type'=>$type,'msg'=>$msg]; }
if (isset($_SESSION['flash'])) { $flash = $_SESSION['flash']; unset($_SESSION['flash']); }

$me = (int)($user['id'] ?? 0);

// Ensure table exists (safe if it already exists)
$pdo->exec("
CREATE TABLE IF NOT EXISTS cargo_providers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  phone VARCHAR(50) DEFAULT NULL,
  address VARCHAR(255) DEFAULT NULL,
  opening_balance DECIMAL(12,2) NOT NULL DEFAULT 0,
  is_active TINYINT(1) DEFAULT 1,
  created_by INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB
");

// If opening_balance column did not exist prior, attempt to add it. MySQL 8 supports IF NOT EXISTS but fallback via check.
try {
  $chk = $pdo->query("SHOW COLUMNS FROM cargo_providers LIKE 'opening_balance'");
  if (!$chk || !$chk->fetch()) {
    $pdo->exec("ALTER TABLE cargo_providers ADD COLUMN opening_balance DECIMAL(12,2) NOT NULL DEFAULT 0");
  }
} catch (Throwable $e) {
  // Ignore failures; column may already exist or user lacks privilege
}

// Handle actions
try {
  // Create new
  if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='create') {
    $name    = trim($_POST['name'] ?? '');
    $phone   = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $opening_balance = isset($_POST['opening_balance']) ? floatval($_POST['opening_balance']) : 0.0;
    if ($name === '') {
      set_flash('danger','Provider name is required.');
      header('Location: cargo_providers.php'); exit;
    }
    $stmt = $pdo->prepare("INSERT INTO cargo_providers (name, phone, address, opening_balance, created_by) VALUES (?,?,?,?,?)");
    $stmt->execute([
      $name,
      $phone !== '' ? $phone : null,
      $address !== '' ? $address : null,
      $opening_balance,
      $me
    ]);
    set_flash('success','Provider created.');
    header('Location: cargo_providers.php'); exit;
  }

  // Update existing
  if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='update') {
    $id      = (int)($_POST['id'] ?? 0);
    $name    = trim($_POST['name'] ?? '');
    $phone   = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $opening_balance = isset($_POST['opening_balance']) ? floatval($_POST['opening_balance']) : 0.0;
    if ($id<=0 || $name==='') {
      set_flash('danger','Invalid data for update.');
      header('Location: cargo_providers.php'); exit;
    }
    $stmt = $pdo->prepare("UPDATE cargo_providers SET name=?, phone=?, address=?, opening_balance=? WHERE id=?");
    $stmt->execute([
      $name,
      $phone !== '' ? $phone : null,
      $address !== '' ? $address : null,
      $opening_balance,
      $id
    ]);
    set_flash('success','Provider updated.');
    header('Location: cargo_providers.php'); exit;
  }

  // Toggle active
  if (($_GET['action'] ?? '') === 'toggle' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $stmt = $pdo->prepare("UPDATE cargo_providers SET is_active = 1 - is_active WHERE id = ?");
    $stmt->execute([$id]);
    set_flash('success','Provider status updated.');
    header('Location: cargo_providers.php'); exit;
  }

} catch (Throwable $e) {
  set_flash('danger','Error: '.htmlspecialchars($e->getMessage()));
  header('Location: cargo_providers.php'); exit;
}

// Fetch list
$search = trim($_GET['q'] ?? '');
$onlyActive = isset($_GET['only_active']) ? (int)$_GET['only_active'] : 0;

$sql = "SELECT id, name, phone, address, opening_balance, is_active, created_at FROM cargo_providers WHERE 1=1";
$params = [];
if ($search !== '') {
  $sql .= " AND (name LIKE :q OR phone LIKE :q OR address LIKE :q)";
  $params[':q'] = '%'.$search.'%';
}
if ($onlyActive === 1) {
  $sql .= " AND is_active = 1";
}
$sql .= " ORDER BY is_active DESC, name ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Cargo Providers</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body{background:#f6f7fb}
    .page-head{display:flex;align-items:center;justify-content:space-between;margin:18px 0}
    .badge-ghost{background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;padding:6px 10px;color:#374151}
    .table td, .table th{vertical-align:middle}
  </style>
</head>
<body>
<?php include 'includes/nav.php'; ?>

<div class="container my-3">
  <div class="page-head">
    <div>
      <h3 class="mb-0">Cargo Service Providers</h3>
      <div class="text-muted small">Create and manage providers used in cargo transfers & payments.</div>
    </div>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalCreate">New Provider</button>
  </div>

  <?php if ($flash): ?>
    <div class="alert alert-<?= htmlspecialchars($flash['type']) ?>"><?= htmlspecialchars($flash['msg']) ?></div>
  <?php endif; ?>

  <div class="card mb-3">
    <div class="card-body">
      <form class="row g-2">
        <div class="col-md-4">
          <label class="form-label">Search</label>
          <input type="text" class="form-control" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Name / Phone / Address">
        </div>
        <div class="col-md-2">
          <label class="form-label">Active Only</label>
          <select name="only_active" class="form-select">
            <option value="0"<?= $onlyActive===0?' selected':''; ?>>No</option>
            <option value="1"<?= $onlyActive===1?' selected':''; ?>>Yes</option>
          </select>
        </div>
        <div class="col-md-3 d-flex align-items-end">
          <button class="btn btn-secondary me-2">Filter</button>
          <a href="cargo_providers.php" class="btn btn-outline-secondary">Reset</a>
        </div>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="card-body table-responsive">
      <table class="table table-sm table-striped align-middle">
        <thead>
          <tr>
            <th style="width:60px">#</th>
            <th>Name</th>
            <th>Phone</th>
            <th>Address</th>
            <th style="width:120px">Status</th>
            <th style="width:220px" class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$rows): ?>
            <tr><td colspan="6" class="text-center text-muted">No providers found.</td></tr>
          <?php else: foreach ($rows as $r): ?>
            <tr>
              <td><?= (int)$r['id'] ?></td>
              <td><?= htmlspecialchars($r['name']) ?></td>
              <td><?= htmlspecialchars($r['phone'] ?: '-') ?></td>
              <td><?= htmlspecialchars($r['address'] ?: '-') ?></td>
              <td>
                <?php if ((int)$r['is_active'] === 1): ?>
                  <span class="badge bg-success">Active</span>
                <?php else: ?>
                  <span class="badge bg-secondary">Inactive</span>
                <?php endif; ?>
              </td>
              <td class="text-center">
                  <button
                  class="btn btn-sm btn-outline-primary me-1"
                  data-bs-toggle="modal"
                  data-bs-target="#modalEdit"
                  data-id="<?= (int)$r['id'] ?>"
                  data-name="<?= htmlspecialchars($r['name'], ENT_QUOTES) ?>"
                  data-phone="<?= htmlspecialchars($r['phone'] ?? '', ENT_QUOTES) ?>"
                  data-address="<?= htmlspecialchars($r['address'] ?? '', ENT_QUOTES) ?>"
                  data-opening="<?= number_format((float)$r['opening_balance'],2,'.','') ?>"
                >Edit</button>

                <?php if ((int)$r['is_active'] === 1): ?>
                  <a class="btn btn-sm btn-outline-warning"
                     href="cargo_providers.php?action=toggle&id=<?= (int)$r['id'] ?>"
                     onclick="return confirm('Deactivate this provider?')">Deactivate</a>
                <?php else: ?>
                  <a class="btn btn-sm btn-outline-success"
                     href="cargo_providers.php?action=toggle&id=<?= (int)$r['id'] ?>"
                     onclick="return confirm('Activate this provider?')">Activate</a>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Create Modal -->
<div class="modal fade" id="modalCreate" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" method="post">
      <input type="hidden" name="action" value="create">
      <div class="modal-header">
        <h5 class="modal-title">New Cargo Provider</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="mb-2">
          <label class="form-label">Name <span class="text-danger">*</span></label>
          <input type="text" name="name" class="form-control" required maxlength="150">
        </div>
        <div class="mb-2">
          <label class="form-label">Phone</label>
          <input type="text" name="phone" class="form-control" maxlength="50">
        </div>
        <div class="mb-2">
          <label class="form-label">Address</label>
          <input type="text" name="address" class="form-control" maxlength="255">
        </div>

        <div class="mb-2">
          <label class="form-label">Opening Balance</label>
          <input type="number" step="0.01" min="0" name="opening_balance" class="form-control">
        </div>
        <div class="text-muted small">New providers are active by default.</div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-primary">Save Provider</button>
      </div>
    </form>
  </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="modalEdit" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" method="post">
      <input type="hidden" name="action" value="update">
      <input type="hidden" name="id" id="edit_id">
      <div class="modal-header">
        <h5 class="modal-title">Edit Provider</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="mb-2">
          <label class="form-label">Name <span class="text-danger">*</span></label>
          <input type="text" name="name" id="edit_name" class="form-control" required maxlength="150">
        </div>
        <div class="mb-2">
          <label class="form-label">Phone</label>
          <input type="text" name="phone" id="edit_phone" class="form-control" maxlength="50">
        </div>
        <div class="mb-2">
          <label class="form-label">Address</label>
          <input type="text" name="address" id="edit_address" class="form-control" maxlength="255">
        </div>

        <div class="mb-2">
          <label class="form-label">Opening Balance</label>
          <input type="number" step="0.01" min="0" name="opening_balance" id="edit_opening" class="form-control">
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-primary">Update</button>
      </div>
    </form>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const editModal = document.getElementById('modalEdit');
if (editModal) {
  editModal.addEventListener('show.bs.modal', function (event) {
    const btn = event.relatedTarget;
    if (!btn) return;
    document.getElementById('edit_id').value = btn.getAttribute('data-id');
    document.getElementById('edit_name').value = btn.getAttribute('data-name') || '';
    document.getElementById('edit_phone').value = btn.getAttribute('data-phone') || '';
    document.getElementById('edit_address').value = btn.getAttribute('data-address') || '';
    const openVal = btn.getAttribute('data-opening');
    document.getElementById('edit_opening').value = openVal ? openVal : '0';
  });
}
</script>
</body>
</html>
