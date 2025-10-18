<?php
// ajax_lookup_batch.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/includes/header.php';
checkRole(['admin','manager','inventory_officer']);

try {
    $product_id = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
    $branch_id  = isset($_GET['branch_id'])  ? (int)$_GET['branch_id']  : 0;
    $batch_no   = isset($_GET['batch_no'])   ? trim((string)$_GET['batch_no']) : '';

    if ($product_id <= 0 || $branch_id <= 0 || $batch_no === '') {
        echo json_encode(['ok' => false, 'message' => 'Missing product/branch/batch']); exit;
    }

    // 1) Try stock_batches (same branch)
    $q = $pdo->prepare("
        SELECT quantity, cost_price, cost_pin, selling_price, expiry_date, stock_place
        FROM stock_batches
        WHERE product_id = :p AND branch_id = :b AND batch_no = :bn
        LIMIT 1
    ");
    $q->execute([':p'=>$product_id, ':b'=>$branch_id, ':bn'=>$batch_no]);
    $row = $q->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        echo json_encode([
            'ok' => true,
            'source' => 'stock',
            'data' => [
                'quantity'      => (float)$row['quantity'],
                'cost_price'    => (float)$row['cost_price'],
                'cost_pin'      => $row['cost_pin'] ?? null,
                'selling_price' => (float)$row['selling_price'],
                'expiry_date'   => $row['expiry_date'] ?: null,  // YYYY-MM-DD
                'stock_place'   => $row['stock_place'] ?? null,   // e.g. Rack/Shelf/Bin
            ]
        ]);
        exit;
    }

    // 2) Fallback: latest purchase_items for this product+batch (any branch)
    $q2 = $pdo->prepare("
        SELECT pi.quantity, pi.cost_price, pi.cost_pin, pi.selling_price, pi.expiry_date, pi.stock_place
        FROM purchase_items pi
        WHERE pi.product_id = :p AND pi.batch_no = :bn
        ORDER BY pi.id DESC
        LIMIT 1
    ");
    $q2->execute([':p'=>$product_id, ':bn'=>$batch_no]);
    $row2 = $q2->fetch(PDO::FETCH_ASSOC);

    if ($row2) {
        echo json_encode([
            'ok' => true,
            'source' => 'purchase',
            'data' => [
                'quantity'      => (float)$row2['quantity'],
                'cost_price'    => (float)$row2['cost_price'],
                'cost_pin'      => $row2['cost_pin'] ?? null,
                'selling_price' => (float)$row2['selling_price'],
                'expiry_date'   => $row2['expiry_date'] ?: null,
                'stock_place'   => $row2['stock_place'] ?? null,
            ]
        ]);
        exit;
    }

    echo json_encode(['ok' => false, 'message' => 'Batch not found']);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
