<?php
require_once 'includes/header.php';
// Only admin can access this page
checkRole(['admin']);

$message = '';

// Fetch roles and branches for dropdowns
$roles = $pdo->query("SELECT * FROM roles")->fetchAll();
$branches = $pdo->query("SELECT * FROM branches")->fetchAll();

// Add user
if (isset($_POST['add_user'])) {
    $username = trim($_POST['username'] ?? '');
    $full_name = trim($_POST['full_name'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $role_id = (int)($_POST['role_id'] ?? 0);
    $branch_id = (int)($_POST['branch_id'] ?? 0);
    if ($username && $password && $role_id) {
        // Check if username exists
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
        $stmt->execute([$username]);
        if ($stmt->fetchColumn() > 0) {
            $message = 'Username already exists';
        } else {
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (username, full_name, password, role_id, branch_id) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$username, $full_name, $hashed, $role_id, $branch_id ?: null]);
            $message = 'User added successfully!';
        }
    } else {
        $message = 'Please fill in required fields';
    }
}

// Update user
if (isset($_POST['update_user'])) {
    $id = (int)$_POST['id'];
    $full_name = trim($_POST['full_name']);
    $role_id = (int)$_POST['role_id'];
    $branch_id = (int)($_POST['branch_id'] ?? 0);
    $password = trim($_POST['password']);
    if ($id) {
        if ($password) {
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET full_name = ?, role_id = ?, branch_id = ?, password = ? WHERE id = ?");
            $stmt->execute([$full_name, $role_id, $branch_id ?: null, $hashed, $id]);
        } else {
            $stmt = $pdo->prepare("UPDATE users SET full_name = ?, role_id = ?, branch_id = ? WHERE id = ?");
            $stmt->execute([$full_name, $role_id, $branch_id ?: null, $id]);
        }
        $message = 'User updated successfully!';
    }
}

// Fetch all users with role and branch names
$usersStmt = $pdo->query("SELECT u.*, r.name AS role_name, b.name AS branch_name FROM users u
    LEFT JOIN roles r ON u.role_id = r.id
    LEFT JOIN branches b ON u.branch_id = b.id
    ORDER BY u.id DESC");
$usersList = $usersStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Users - POS System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<?php include 'includes/nav.php'; ?>
<div class="container">
    <h1 class="mb-4">Users</h1>
    <?php if ($message): ?>
        <div class="alert alert-info"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <!-- Add User Form -->
    <div class="card mb-4">
        <div class="card-header">Add New User</div>
        <div class="card-body">
            <form method="post">
                <div class="row g-3">
                    <div class="col-md-3">
                        <input type="text" name="username" class="form-control" placeholder="Username" required>
                    </div>
                    <div class="col-md-3">
                        <input type="text" name="full_name" class="form-control" placeholder="Full Name">
                    </div>
                    <div class="col-md-3">
                        <input type="password" name="password" class="form-control" placeholder="Password" required>
                    </div>
                    <div class="col-md-2">
                        <select name="role_id" class="form-select" required>
                            <option value="">Role</option>
                            <?php foreach ($roles as $role): ?>
                                <option value="<?php echo $role['id']; ?>"><?php echo htmlspecialchars($role['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-1">
                        <select name="branch_id" class="form-select">
                            <option value="">Branch</option>
                            <?php foreach ($branches as $branch): ?>
                                <option value="<?php echo $branch['id']; ?>"><?php echo htmlspecialchars($branch['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-12 text-end">
                        <button type="submit" name="add_user" class="btn btn-primary">Add User</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Users List -->
    <table class="table table-bordered table-striped">
        <thead>
        <tr>
            <th>ID</th>
            <th>Username</th>
            <th>Full Name</th>
            <th>Role</th>
            <th>Branch</th>
            <th>Action</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($usersList as $u): ?>
            <tr>
                <td><?php echo $u['id']; ?></td>
                <td><?php echo htmlspecialchars($u['username']); ?></td>
                <td><?php echo htmlspecialchars($u['full_name']); ?></td>
                <td><?php echo htmlspecialchars($u['role_name']); ?></td>
                <td><?php echo htmlspecialchars($u['branch_name']); ?></td>
                <td>
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-toggle="modal" data-bs-target="#editUserModal<?php echo $u['id']; ?>">Edit</button>
                    <?php if (!empty($user['role_name']) && $user['role_name'] === 'admin'): ?>
                        <form method="post" action="delete_user.php" class="d-inline-block" onsubmit="return confirm('Are you sure you want to delete this user? This cannot be undone.');">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>">
                            <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>

            <!-- Edit User Modal -->
            <div class="modal fade" id="editUserModal<?php echo $u['id']; ?>" tabindex="-1" aria-labelledby="editUserLabel<?php echo $u['id']; ?>" aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="editUserLabel<?php echo $u['id']; ?>">Edit User</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <form method="post">
                                <input type="hidden" name="id" value="<?php echo $u['id']; ?>">
                                <div class="mb-3">
                                    <label class="form-label">Full Name</label>
                                    <input type="text" name="full_name" class="form-control" value="<?php echo htmlspecialchars($u['full_name']); ?>" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Role</label>
                                    <select name="role_id" class="form-select" required>
                                        <?php foreach ($roles as $role): ?>
                                            <option value="<?php echo $role['id']; ?>" <?php echo $u['role_id'] == $role['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($role['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Branch (optional)</label>
                                    <select name="branch_id" class="form-select">
                                        <option value="">None</option>
                                        <?php foreach ($branches as $branch): ?>
                                            <option value="<?php echo $branch['id']; ?>" <?php echo $u['branch_id'] == $branch['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($branch['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">New Password (leave blank to keep current)</label>
                                    <input type="password" name="password" class="form-control">
                                </div>
                                <button type="submit" name="update_user" class="btn btn-primary">Update User</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>