<?php
// API endpoint: return_summary.php
// Returns summary of a sales return for merging with a new sale.
// Expects GET parameter 'return_id' which may include non-numeric characters (e.g., R-123).

require_once '../includes/header.php';

// Only allow admin, manager, or cashier roles to access
checkRole(['admin','manager','cashier']);

header('Content-Type: application/json');

// Extract return ID
$ridRaw = isset($_GET['return_id']) ? trim($_GET['return_id']) : '';
if ($ridRaw === '') {
    echo json_encode(['ok' => false, 'message' => 'Missing return_id']);
    exit;
}

// Normalise: extract numeric part
$ridNum = preg_replace('/\D+/', '', $ridRaw);
if ($ridNum === '') {
    echo json_encode(['ok' => false, 'message' => 'Invalid Return ID']);
    exit;
}
$returnId = (int)$ridNum;

try {
    // Fetch return header
    $stmt = $pdo->prepare("SELECT id, sale_id, total_refund, return_type FROM sale_returns WHERE id = ?");
    $stmt->execute([$returnId]);
    $return = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$return) {
        echo json_encode(['ok' => false, 'message' => 'Return not found']);
        exit;
    }
    // If settled_with_sale_id column exists, check if this return is settled
    $settled = false;
    try {
        $checkCol = $pdo->query("SHOW COLUMNS FROM sale_returns LIKE 'settled_with_sale_id'")->fetch();
        if ($checkCol) {
            $st = $pdo->prepare("SELECT settled_with_sale_id FROM sale_returns WHERE id=?");
            $st->execute([$returnId]);
            $val = $st->fetchColumn();
            if ($val !== null && $val !== '' && $val != 0) {
                $settled = true;
            }
        }
    } catch (Throwable $e) {
        // ignore if column does not exist
    }
    if ($settled) {
    echo json_encode(['ok' => false, 'message' => 'This return bill has already been merged into a new bill.']);
        exit;
    }
    // Fetch return items
    $sqlItems = "SELECT sri.quantity AS qty, sri.unit_price AS rate, (sri.quantity * sri.unit_price) AS subtotal,
                        p.name AS name, sb.batch_no AS batch_no
                 FROM sale_return_items sri
                 JOIN products p ON p.id = sri.product_id
                 LEFT JOIN stock_batches sb ON sb.id = sri.batch_id
                 WHERE sri.sale_return_id = ?
                 ORDER BY sri.id";
    $stItems = $pdo->prepare($sqlItems);
    $stItems->execute([$returnId]);
    $items = $stItems->fetchAll(PDO::FETCH_ASSOC);
    // Response
    echo json_encode([
        'ok' => true,
        'data' => [
            'return_id' => (int)$return['id'],
            'sale_id' => (int)$return['sale_id'],
            'return_total' => (float)$return['total_refund'],
            'return_type' => $return['return_type'],
            'items' => array_map(function ($row) {
                return [
                    'name' => $row['name'],
                    'batch_no' => $row['batch_no'],
                    'qty' => (float)$row['qty'],
                    'rate' => (float)$row['rate'],
                    'subtotal' => (float)$row['subtotal'],
                ];
            }, $items)
        ]
    ]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'message' => 'Server error']);
}
