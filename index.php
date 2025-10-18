<?php
// Login page for the multi-branch POS & Inventory system
session_start();
require_once 'config/db.php';

$errors = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    // Basic validation
    if ($username && $password) {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $userRow = $stmt->fetch();
        // Verify password using password hashing
        if ($userRow && password_verify($password, $userRow['password'])) {
            // Store user ID in session and redirect to dashboard
            $_SESSION['user_id'] = $userRow['id'];
            header('Location: dashboard.php');
            exit;
        } else {
            $errors = 'Invalid username or password';
        }
    } else {
        $errors = 'Please enter username and password';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - POS System</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light"><br><br><br>
<div class="container mt-5">
    <div class="row justify-content-center">
        <div class="col-md-4">
            <div class="text-center mb-4">
                <!-- Circle Logo -->
                <img src="logo.png" alt="Logo" class="rounded-circle shadow" width="100" height="100" style="object-fit: cover; border: 3px solid #fff; margin-top:-50px;">
            </div>
            <div class="card shadow-sm">
                <div class="card-header">
                    <div class="text-center mb-4">
                        <h3>Inventory System</h3>
                        <p class="text-muted">Sign in to your account</p>
                    </div>
                </div>
                <div class="card-body">
                    <?php if ($errors): ?>
                        <div class="alert alert-danger"><?php echo htmlspecialchars($errors); ?></div>
                    <?php endif; ?>
                    <form method="post">
                        <div class="mb-3">
                            <label for="username" class="form-label">Username</label>
                            <input type="text" name="username" id="username" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label for="password" class="form-label">Password</label>
                            <input type="password" name="password" id="password" class="form-control" required>
                        </div>
                        <button type="submit" class="btn btn-primary w-100">Login</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
<!-- Bootstrap JS Bundle -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>