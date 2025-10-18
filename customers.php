<?php
require_once 'includes/header.php';
// Only admin, manager, or cashier can access this page
checkRole(['admin', 'manager', 'cashier']);

$message = '';

// Determine current user's branch id. If user is not defined, default to 0.
$currentBranchId = isset($user['branch_id']) ? (int)$user['branch_id'] : 0;

// Fetch branches for the dropdown
$branches = [];
try {
    $stmtBranches = $pdo->prepare("SELECT id, name FROM branches ORDER BY name ASC");
    $stmtBranches->execute();
    $branches = $stmtBranches->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $branches = [];
}

// Add new customer
if (isset($_POST['add_customer'])) {
    $name            = trim($_POST['name'] ?? '');
    $phone           = trim($_POST['phone'] ?? '');
    $address         = trim($_POST['address'] ?? '');
    $city            = trim($_POST['city'] ?? '');
    $state           = trim($_POST['state'] ?? '');
    $route           = trim($_POST['route'] ?? '');
    $due_limit       = (isset($_POST['due_limit']) && $_POST['due_limit'] !== '') ? (float)$_POST['due_limit'] : null;
    $opening_balance = (isset($_POST['opening_balance']) && $_POST['opening_balance'] !== '') ? (float)$_POST['opening_balance'] : null;

    if ($name) {
        // Determine which branch_id to use:
        if ($user['role_name'] === 'admin') {
            // Admin: use posted branch ID if provided; fallback to current branch
            $branchId = (isset($_POST['branch_id']) && $_POST['branch_id'] !== '') ? (int)$_POST['branch_id'] : $currentBranchId;
        } else {
            // Non-admin: always use user's branch
            $branchId = $currentBranchId;
        }

        // Check if opening_balance column exists
        $hasOpening = false;
        try {
            $colCheck = $pdo->prepare("SHOW COLUMNS FROM customers LIKE 'opening_balance'");
            $colCheck->execute();
            $hasOpening = (bool)$colCheck->fetch();
        } catch (Throwable $e) {
            $hasOpening = false;
        }
        // Check if branch_id column exists
        $hasBranch = false;
        try {
            $colCheck2 = $pdo->prepare("SHOW COLUMNS FROM customers LIKE 'branch_id'");
            $colCheck2->execute();
            $hasBranch = (bool)$colCheck2->fetch();
        } catch (Throwable $e) {
            $hasBranch = false;
        }

        // Insert new customer
        if ($hasOpening && $hasBranch) {
            $stmt = $pdo->prepare("INSERT INTO customers (name, phone, address, city, state, route, due_limit, opening_balance, branch_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$name, $phone, $address, $city, $state, $route, $due_limit, $opening_balance, $branchId]);
        } elseif ($hasOpening) {
            $stmt = $pdo->prepare("INSERT INTO customers (name, phone, address, city, state, route, due_limit, opening_balance) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$name, $phone, $address, $city, $state, $route, $due_limit, $opening_balance]);
        } elseif ($hasBranch) {
            $stmt = $pdo->prepare("INSERT INTO customers (name, phone, address, city, state, route, due_limit, branch_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$name, $phone, $address, $city, $state, $route, $due_limit, $branchId]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO customers (name, phone, address, city, state, route, due_limit) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$name, $phone, $address, $city, $state, $route, $due_limit]);
        }
        $message = 'Customer added successfully!';
    } else {
        $message = 'Customer name is required';
    }
}

// Update customer
if (isset($_POST['update_customer'])) {
    $id              = (int)($_POST['id'] ?? 0);
    $name            = trim($_POST['name'] ?? '');
    $phone           = trim($_POST['phone'] ?? '');
    $address         = trim($_POST['address'] ?? '');
    $city            = trim($_POST['city'] ?? '');
    $state           = trim($_POST['state'] ?? '');
    $route           = trim($_POST['route'] ?? '');
    $due_limit       = (isset($_POST['due_limit']) && $_POST['due_limit'] !== '') ? (float)$_POST['due_limit'] : null;
    $opening_balance = (isset($_POST['opening_balance']) && $_POST['opening_balance'] !== '') ? (float)$_POST['opening_balance'] : null;

    if ($id && $name) {
        // Determine columns
        $hasOpening = false;
        try {
            $colCheck = $pdo->prepare("SHOW COLUMNS FROM customers LIKE 'opening_balance'");
            $colCheck->execute();
            $hasOpening = (bool)$colCheck->fetch();
        } catch (Throwable $e) {
            $hasOpening = false;
        }
        $hasBranchColUpdate = false;
        try {
            $colCheck3 = $pdo->prepare("SHOW COLUMNS FROM customers LIKE 'branch_id'");
            $colCheck3->execute();
            $hasBranchColUpdate = (bool)$colCheck3->fetch();
        } catch (Throwable $e) {
            $hasBranchColUpdate = false;
        }

        // Update logic (branch_id unchanged)
        if ($hasOpening) {
            if ($opening_balance !== null) {
                if ($hasBranchColUpdate) {
                    $stmt = $pdo->prepare("UPDATE customers SET name = ?, phone = ?, address = ?, city = ?, state = ?, route = ?, due_limit = ?, opening_balance = ? WHERE id = ? AND branch_id = ?");
                    $stmt->execute([$name, $phone, $address, $city, $state, $route, $due_limit, $opening_balance, $id, $currentBranchId]);
                } else {
                    $stmt = $pdo->prepare("UPDATE customers SET name = ?, phone = ?, address = ?, city = ?, state = ?, route = ?, due_limit = ?, opening_balance = ? WHERE id = ?");
                    $stmt->execute([$name, $phone, $address, $city, $state, $route, $due_limit, $opening_balance, $id]);
                }
            } else {
                if ($hasBranchColUpdate) {
                    $stmt = $pdo->prepare("UPDATE customers SET name = ?, phone = ?, address = ?, city = ?, state = ?, route = ?, due_limit = ? WHERE id = ? AND branch_id = ?");
                    $stmt->execute([$name, $phone, $address, $city, $state, $route, $due_limit, $id, $currentBranchId]);
                } else {
                    $stmt = $pdo->prepare("UPDATE customers SET name = ?, phone = ?, address = ?, city = ?, state = ?, route = ?, due_limit = ? WHERE id = ?");
                    $stmt->execute([$name, $phone, $address, $city, $state, $route, $due_limit, $id]);
                }
            }
        } else {
            if ($hasBranchColUpdate) {
                $stmt = $pdo->prepare("UPDATE customers SET name = ?, phone = ?, address = ?, city = ?, state = ?, route = ?, due_limit = ? WHERE id = ? AND branch_id = ?");
                $stmt->execute([$name, $phone, $address, $city, $state, $route, $due_limit, $id, $currentBranchId]);
            } else {
                $stmt = $pdo->prepare("UPDATE customers SET name = ?, phone = ?, address = ?, city = ?, state = ?, route = ?, due_limit = ? WHERE id = ?");
                $stmt->execute([$name, $phone, $address, $city, $state, $route, $due_limit, $id]);
            }
        }

        $message = 'Customer updated successfully!';
    } else {
        $message = 'Customer name is required';
    }
}

// Ensure branch_id column exists or add it
try {
    $branchColCheck = $pdo->prepare("SHOW COLUMNS FROM customers LIKE 'branch_id'");
    $branchColCheck->execute();
    $hasBranchCol = (bool)$branchColCheck->fetch();
    if (!$hasBranchCol) {
        $pdo->exec("ALTER TABLE customers ADD COLUMN branch_id INT DEFAULT NULL");
    }
} catch (Throwable $e) {
    // Ignore if cannot add column
}

// Refresh currentBranchId
$currentBranchId = isset($user['branch_id']) ? (int)$user['branch_id'] : 0;

// Fetch customers for current branch
$customers = [];
try {
    $stmt = $pdo->prepare("SELECT c.*, b.name AS branch_name FROM customers c LEFT JOIN branches b ON c.branch_id = b.id WHERE c.branch_id = ? ORDER BY c.id DESC");
    $stmt->execute([$currentBranchId]);
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $customers = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customers - POS System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
</head>
<body>
<?php include 'includes/nav.php'; ?>
<div class="container">
    <h1 class="mb-4">Customers</h1>
    <?php if ($message): ?>
        <div class="alert alert-info"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <!-- Add Customer Form -->
    <div class="card mb-4">
        <div class="card-header">Add New Customer</div>
        <div class="card-body">
            <form method="post">
                <div class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label">Customer Name</label>
                        <input type="text" name="name" class="form-control" placeholder="Name" required>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Phone</label>
                        <input type="text" name="phone" class="form-control" placeholder="Phone">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Address</label>
                        <input type="text" name="address" class="form-control" placeholder="Address">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">City</label>
                        <input type="text" name="city" class="form-control" placeholder="City">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">State</label>
                        <input type="text" name="state" class="form-control" placeholder="State">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Route</label>
                        <input type="text" name="route" class="form-control" placeholder="Route">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Due Limit (Rs.)</label>
                        <input type="number" step="0.01" name="due_limit" class="form-control" placeholder="0.00">
                    </div>
                    <?php
                      // Display opening_balance field if the column exists
                      $custHasOpening = false;
                      try {
                          $cstCheck = $pdo->prepare("SHOW COLUMNS FROM customers LIKE 'opening_balance'");
                          $cstCheck->execute();
                          $custHasOpening = (bool)$cstCheck->fetch();
                      } catch (Throwable $e) {
                          $custHasOpening = false;
                      }
                      if ($custHasOpening):
                    ?>
                    <div class="col-md-2">
                        <label class="form-label">Opening Balance (Rs.)</label>
                        <input type="number" step="0.01" name="opening_balance" class="form-control" placeholder="0.00">
                        <div class="form-text">Initial amount owed by customer.</div>
                    </div>
                    <?php endif; ?>
                    <?php
                      // Always display the branch dropdown if column exists
                      try {
                          $colCheckBranch = $pdo->prepare("SHOW COLUMNS FROM customers LIKE 'branch_id'");
                          $colCheckBranch->execute();
                          $branchColExists = (bool)$colCheckBranch->fetch();
                      } catch (Throwable $e) {
                          $branchColExists = false;
                      }
                      if ($branchColExists):
                    ?>
                    <div class="col-md-3">
                        <label class="form-label">Branch</label>
                        <select name="branch_id" class="form-select"
                            <?php echo ($user['role_name'] === 'admin' ? '' : 'disabled'); ?>>
                            <?php foreach ($branches as $b): ?>
                                <option value="<?php echo $b['id']; ?>"
                                    <?php
                                        // Preselect for non-admin
                                        echo ($user['role_name'] !== 'admin' && $b['id'] == $currentBranchId) ? 'selected' : '';
                                    ?>>
                                    <?php echo htmlspecialchars($b['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    <div class="col-md-2">
                        <button type="submit" name="add_customer" class="btn btn-primary w-100">Add</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Customers List -->
    <table id="customersTable" class="table table-bordered table-striped">
        <thead>
        <tr>
            <th>ID</th>
            <th>Name</th>
            <th>Phone</th>
            <th>Address</th>
            <th>City</th>
            <th>State</th>
            <th>Route</th>
            <th>Branch</th>
            <th>Action</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($customers as $c): ?>
            <tr>
                <td><?php echo $c['id']; ?></td>
                <td><?php echo htmlspecialchars($c['name']); ?></td>
                <td><?php echo htmlspecialchars($c['phone']); ?></td>
                <td><?php echo htmlspecialchars($c['address']); ?></td>
                <td><?php echo htmlspecialchars($c['city']); ?></td>
                <td><?php echo htmlspecialchars($c['state']); ?></td>
                <td><?php echo htmlspecialchars($c['route']); ?></td>
                <td><?php echo htmlspecialchars($c['branch_name'] ?? ''); ?></td>
                <td>
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-toggle="modal" data-bs-target="#editCustomerModal<?php echo $c['id']; ?>">Edit</button>
                    <?php if ($user && $user['role_name'] === 'admin'): ?>
                    <form method="post" action="delete_customer.php" onsubmit="return confirm('Are you sure you want to delete this customer?');" class="d-inline ms-1">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="id" value="<?php echo (int)$c['id']; ?>">
                        <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>

            <!-- Edit Customer Modal -->
            <div class="modal fade" id="editCustomerModal<?php echo $c['id']; ?>" tabindex="-1" aria-labelledby="editCustomerLabel<?php echo $c['id']; ?>" aria-hidden="true">
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="editCustomerLabel<?php echo $c['id']; ?>">Edit Customer</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <form method="post">
                                <input type="hidden" name="id" value="<?php echo $c['id']; ?>">
                                <div class="row g-3">
                                    <div class="col-md-3">
                                        <label class="form-label">Name</label>
                                        <input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($c['name']); ?>" required>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Phone</label>
                                        <input type="text" name="phone" class="form-control" value="<?php echo htmlspecialchars($c['phone']); ?>">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Address</label>
                                        <input type="text" name="address" class="form-control" value="<?php echo htmlspecialchars($c['address']); ?>">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">City</label>
                                        <input type="text" name="city" class="form-control" value="<?php echo htmlspecialchars($c['city']); ?>">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">State</label>
                                        <input type="text" name="state" class="form-control" value="<?php echo htmlspecialchars($c['state']); ?>">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Route</label>
                                        <input type="text" name="route" class="form-control" value="<?php echo htmlspecialchars($c['route']); ?>">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Due Limit (Rs.)</label>
                                        <input type="number" step="0.01" name="due_limit" class="form-control" value="<?php echo htmlspecialchars($c['due_limit'] ?? ''); ?>">
                                    </div>
                                    <?php
                                      // Display opening balance if column exists
                                      $cHasOpening = false;
                                      try {
                                          $stCol = $pdo->prepare("SHOW COLUMNS FROM customers LIKE 'opening_balance'");
                                          $stCol->execute();
                                          $cHasOpening = (bool)$stCol->fetch();
                                      } catch (Throwable $e) {
                                          $cHasOpening = false;
                                      }
                                      if ($cHasOpening):
                                    ?>
                                    <div class="col-md-2">
                                        <label class="form-label">Opening Balance (Rs.)</label>
                                        <input type="number" step="0.01" name="opening_balance" class="form-control" value="<?php echo htmlspecialchars($c['opening_balance'] ?? '0.00'); ?>">
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <div class="mt-3">
                                    <button type="submit" name="update_customer" class="btn btn-primary">Update</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Bootstrap and DataTables JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script>
  $(document).ready(function() {
    $('#customersTable').DataTable();
  });
</script>
</body>
</html>
