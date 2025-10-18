<?php
/**
 * ajax_get_product_batches.php
 * Returns JSON list of existing stock batches for a given product and branch.
 * URL parameters: product_id, branch_id
 */
declare(strict_types=1);

require_once 'includes/header.php';

// Restrict access: allow admin, manager, or inventory officer to view batch info
checkRole(['admin', 'manager', 'inventory_officer']);

header('Content-Type: application/json; charset=utf-8');

// Validate parameters
$product_id = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
$branch_id  = isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : 0;
if ($product_id <= 0 || $branch_id <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Missing product_id or branch_id']);
    exit;
}

/**
 * Check if a column exists on a table in the current database.
 */
function column_exists(PDO $pdo, string $table, string $column): bool {
    $q = $pdo->prepare("
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :t
          AND COLUMN_NAME = :c
        LIMIT 1
    ");
    $q->execute([':t' => $table, ':c' => $column]);
    return (bool)$q->fetchColumn();
}

try {
    // Include stock_place if the column exists; otherwise return NULL alias to keep response shape stable
    $hasStockPlace = column_exists($pdo, 'stock_batches', 'stock_place');
    $stockPlaceExpr = $hasStockPlace ? 'sb.stock_place' : 'NULL AS stock_place';

    // Fetch all stock batches for this product and branch.
    $sql = "
        SELECT
            sb.id,
            sb.batch_no,
            sb.quantity,
            sb.cost_price,
            sb.selling_price,
            sb.expiry_date,
            {$stockPlaceExpr},
            p.name AS product_name
        FROM stock_batches sb
        JOIN products p ON sb.product_id = p.id
        WHERE sb.product_id = ? AND sb.branch_id = ?
        ORDER BY sb.created_at ASC, sb.id ASC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$product_id, $branch_id]);
    $batches = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Cast numeric fields to numbers so the front-end doesn’t need to parse strings
    foreach ($batches as &$b) {
        $b['quantity']      = isset($b['quantity'])      ? (float)$b['quantity']      : 0.0;
        $b['cost_price']    = isset($b['cost_price'])    ? (float)$b['cost_price']    : 0.0;
        $b['selling_price'] = isset($b['selling_price']) ? (float)$b['selling_price'] : 0.0;
        // expiry_date may be NULL or 'YYYY-MM-DD'; leave as-is
        // stock_place may be NULL; leave as-is
    }
    unset($b);

    echo json_encode(['status' => 'success', 'batches' => $batches]);
    exit;
} catch (Throwable $e) {
    echo json_encode(['status' => 'error', 'message' => 'Failed to fetch batches: ' . $e->getMessage()]);
    exit;
}
