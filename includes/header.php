<?php
/**
 * Common header file
 * Starts the session, includes the database, and fetches the logged-in user
 */
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Include database connection
require_once __DIR__ . '/../config/db.php';

// Include common functions such as logActivity
require_once __DIR__ . '/functions.php';

// Redirect to login page if the user is not authenticated (except on login page)
if (!isset($_SESSION['user_id']) && basename($_SERVER['PHP_SELF']) != 'login.php') {
    header('Location: login.php');
    exit;
}

// Fetch logged-in user data (role and branch information)
$user = null;
if (isset($_SESSION['user_id'])) {
    $stmt = $pdo->prepare(
        "SELECT u.*, r.name AS role_name, b.name AS branch_name FROM users u " .
        "LEFT JOIN roles r ON u.role_id = r.id " .
        "LEFT JOIN branches b ON u.branch_id = b.id " .
        "WHERE u.id = ?"
    );
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
}

/**
 * Helper function to restrict page access based on roles
 *
 * @param array $roles List of allowed role names
 */
function checkRole(array $roles = [])
{
    global $user;
    if (!$user || !in_array($user['role_name'], $roles)) {
        // Redirect to dashboard if user does not have the required role
        header('Location: dashboard.php');
        exit;
    }
}

// -----------------------------------------------------------------------------
// CSRF protection helpers
//
// To defend against cross-site request forgery, we generate a per-session token
// and include it in all POST forms.  When processing a form, call
// csrf_check() to verify the token.  If invalid, the request will abort.
//
// Note: ensure session_start has been called prior to invoking these helpers.

// Initialize a CSRF token once per session
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

/**
 * Output a hidden input with the current CSRF token.  Use this helper in
 * forms that perform state-changing actions (POST requests).
 */
function csrf_field()
{
    $token = htmlspecialchars($_SESSION['csrf'] ?? '', ENT_QUOTES, 'UTF-8');
    echo '<input type="hidden" name="csrf" value="' . $token . '">';
}

/**
 * Validate the CSRF token from POST data.  If the token is missing or
 * incorrect, abort with HTTP 400.  Use this at the top of POST handlers.
 */
function csrf_check()
{
    $posted = $_POST['csrf'] ?? '';
    $expected = $_SESSION['csrf'] ?? '';
    if (!$expected || !hash_equals($expected, $posted)) {
        http_response_code(400);
        exit('Invalid CSRF token');
    }
}

?>