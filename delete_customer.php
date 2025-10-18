<?php
require_once 'includes/header.php';
// Only admin can delete customers
checkRole(['admin']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}
// Validate CSRF token
csrf_check();

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    exit('Invalid customer ID');
}

$pdo->beginTransaction();
try {
    // Delete the customer record; foreign key constraints will cascade to dependent tables if configured
    $stmt = $pdo->prepare('DELETE FROM customers WHERE id = ?');
    $stmt->execute([$id]);
    $pdo->commit();
    header('Location: customers.php?msg=deleted');
    exit;
} catch (Throwable $e) {
    $pdo->rollBack();
    http_response_code(500);
    exit('Failed to delete customer: ' . $e->getMessage());
}