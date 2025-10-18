<?php
/**
 * ajax_search_products.php
 *
 * Returns a JSON array of products matching a search query.  Expects
 * a `q` parameter with the search term.  Returns up to 20 products
 * containing id, name, and barcode.  Used by live search fields in
 * barcode_print.php and write_off.php.
 */
require_once 'config/db.php';
header('Content-Type: application/json');

$term = trim($_GET['q'] ?? '');
if ($term === '') {
    echo json_encode([]);
    exit;
}
// Use wildcard search on name and barcode
$query = "SELECT id, name, barcode FROM products WHERE name LIKE ? OR barcode LIKE ? ORDER BY name LIMIT 20";
$like = '%' . $term . '%';
$stmt = $pdo->prepare($query);
$stmt->execute([$like, $like]);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($results);
exit;