<?php
// Suppliers list page
// Only admin or manager can view suppliers list
require_once 'includes/header.php';

// Restrict access to admin and manager roles
checkRole(['admin', 'manager']);

// Determine the current branch ID up front.  This value is used when
// adding suppliers below.  In the original implementation the
// $currentBranchId variable was assigned after being referenced in
// the insert statements which triggered an "undefined variable"
// notice when creating a supplier.  Defining it here ensures the
// variable always exists when needed.
$currentBranchId = isset($user['branch_id']) ? (int)$user['branch_id'] : 0;

$message = '';
// Handle add new supplier
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_supplier'])) {
    $name    = trim($_POST['name'] ?? '');
    $contact = trim($_POST['contact'] ?? '');
    $phone   = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    // Optional opening balance for suppliers.  Positive values mean the business owes the supplier on day one.
    $opening_balance = isset($_POST['opening_balance']) && $_POST['opening_balance'] !== '' ? (float)$_POST['opening_balance'] : null;
    if ($name) {
        try {
            // Determine if opening_balance column exists
            $hasOpening = false;
            try {
                $colCheck = $pdo->prepare("SHOW COLUMNS FROM suppliers LIKE 'opening_balance'");
                $colCheck->execute();
                $hasOpening = (bool)$colCheck->fetch();
            } catch (Throwable $e) {
                $hasOpening = false;
            }
            // Determine if branch_id column exists on suppliers table
            $hasBranchCol = false;
            try {
                $bc = $pdo->prepare("SHOW COLUMNS FROM suppliers LIKE 'branch_id'");
                $bc->execute();
                $hasBranchCol = (bool)$bc->fetch();
            } catch (Throwable $e) {
                $hasBranchCol = false;
            }
            if ($hasOpening && $hasBranchCol) {
                $stmtAdd = $pdo->prepare("INSERT INTO suppliers (name, contact, phone, address, opening_balance, branch_id) VALUES (?, ?, ?, ?, ?, ?)");
                $stmtAdd->execute([$name, $contact, $phone, $address, $opening_balance, $currentBranchId]);
            } elseif ($hasOpening) {
                $stmtAdd = $pdo->prepare("INSERT INTO suppliers (name, contact, phone, address, opening_balance) VALUES (?, ?, ?, ?, ?)");
                $stmtAdd->execute([$name, $contact, $phone, $address, $opening_balance]);
            } elseif ($hasBranchCol) {
                $stmtAdd = $pdo->prepare("INSERT INTO suppliers (name, contact, phone, address, branch_id) VALUES (?, ?, ?, ?, ?)");
                $stmtAdd->execute([$name, $contact, $phone, $address, $currentBranchId]);
            } else {
                $stmtAdd = $pdo->prepare("INSERT INTO suppliers (name, contact, phone, address) VALUES (?, ?, ?, ?)");
                $stmtAdd->execute([$name, $contact, $phone, $address]);
            }
            $message = 'Supplier added successfully!';
        } catch (Throwable $e) {
            $message = 'Error adding supplier: ' . $e->getMessage();
        }
    } else {
        $message = 'Supplier name is required.';
    }
}

// Ensure branch_id column exists on suppliers table so we can assign suppliers per branch
try {
    $supBranchCheck = $pdo->prepare("SHOW COLUMNS FROM suppliers LIKE 'branch_id'");
    $supBranchCheck->execute();
    $hasBranchCol = (bool)$supBranchCheck->fetch();
    if (!$hasBranchCol) {
        $pdo->exec("ALTER TABLE suppliers ADD COLUMN branch_id INT DEFAULT NULL");
    }
} catch (Throwable $e) {
    // ignore errors if column cannot be added
}

// Current user's branch
$currentBranchId = isset($user['branch_id']) ? (int)$user['branch_id'] : 0;

// Fetch suppliers only for the current branch, along with branch name
$suppliers = [];
try {
    $stmt = $pdo->prepare("SELECT s.*, b.name AS branch_name FROM suppliers s LEFT JOIN branches b ON s.branch_id = b.id WHERE s.branch_id = ? ORDER BY s.name");
    $stmt->execute([$currentBranchId]);
    $suppliers = $stmt->fetchAll();
} catch (Throwable $e) {
    $suppliers = [];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Suppliers - POS System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- DataTables CSS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
</head>
<body>
<?php include 'includes/nav.php'; ?>
<div class="container">
    <h1 class="mb-4">Suppliers</h1>
    <?php if ($message): ?>
        <div class="alert alert-info"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <!-- Add Supplier Form -->
    <div class="card mb-4">
        <div class="card-header">Add New Supplier</div>
        <div class="card-body">
            <form method="post">
                <div class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label">Supplier Name</label>
                        <input type="text" name="name" class="form-control" placeholder="Name" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Contact Person</label>
                        <input type="text" name="contact" class="form-control" placeholder="Contact Person">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Phone</label>
                        <input type="text" name="phone" class="form-control" placeholder="Phone">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Address</label>
                        <input type="text" name="address" class="form-control" placeholder="Address">
                    </div>
                    <?php
                      // Show opening balance field only if the column exists on the suppliers table
                      $supHasOpening = false;
                      try {
                        $stCol = $pdo->prepare("SHOW COLUMNS FROM suppliers LIKE 'opening_balance'");
                        $stCol->execute();
                        $supHasOpening = (bool)$stCol->fetch();
                      } catch (Throwable $e) { $supHasOpening = false; }
                      if ($supHasOpening):
                    ?>
                    <div class="col-md-3">
                        <label class="form-label">Opening Balance (Rs.)</label>
                        <input type="number" step="0.01" name="opening_balance" class="form-control" placeholder="0.00">
                        <div class="form-text">Initial amount owed to supplier.</div>
                    </div>
                    <?php endif; ?>
                    <div class="col-md-2 mt-3">
                        <button type="submit" name="add_supplier" class="btn btn-primary w-100">Add</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    <div class="table-responsive">
        <table id="suppliersTable" class="table table-bordered table-striped">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Name</th>
                    <th>Contact</th>
                    <th>Phone</th>
                    <th>Address</th>
                    <?php
                      // Determine if suppliers table has opening_balance column
                      $supHasOpening = false;
                      try {
                        $stCol = $pdo->prepare("SHOW COLUMNS FROM suppliers LIKE 'opening_balance'");
                        $stCol->execute();
                        $supHasOpening = (bool)$stCol->fetch();
                      } catch (Throwable $e) { $supHasOpening = false; }
                      if ($supHasOpening):
                    ?>
                    <th>Opening Bal</th>
                    <?php endif; ?>
                    <th>Branch</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($suppliers as $sup): ?>
                <tr>
                    <td><?php echo $sup['id']; ?></td>
                    <td><?php echo htmlspecialchars($sup['name']); ?></td>
                    <td><?php echo htmlspecialchars($sup['contact']); ?></td>
                    <td><?php echo htmlspecialchars($sup['phone']); ?></td>
                    <td><?php echo htmlspecialchars($sup['address']); ?></td>
                    <?php if ($supHasOpening): ?>
                    <td class="text-end"><?php echo number_format((float)($sup['opening_balance'] ?? 0), 2); ?></td>
                    <?php endif; ?>
                    <td><?php echo htmlspecialchars($sup['branch_name'] ?? ''); ?></td>
                <td>
                        <a href="supplier_view.php?id=<?php echo (int)$sup['id']; ?>" class="btn btn-sm btn-primary">View</a>
                        <?php if (!empty($user['role_name']) && $user['role_name'] === 'admin'): ?>
                            <form method="post" action="delete_supplier.php" class="d-inline-block" onsubmit="return confirm('Are you sure you want to delete this supplier? This cannot be undone.');">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="id" value="<?php echo (int)$sup['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<!-- jQuery and DataTables JS -->
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script>
  $(document).ready(function() {
    $('#suppliersTable').DataTable();
  });
</script>
</body>
</html>