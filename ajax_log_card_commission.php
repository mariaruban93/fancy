<?php
require_once 'includes/header.php';
checkRole(['admin','manager','cashier']);

header('Content-Type: application/json');

try {
    $raw = file_get_contents('php://input');
    $js  = json_decode($raw, true);

    if (!is_array($js)) throw new Exception('Invalid payload');

    $sale_id          = (int)($js['sale_id'] ?? 0);
    $branch_id        = (int)($js['branch_id'] ?? 0);
    $gross_amount     = (float)($js['gross_amount'] ?? 0);
    $commission_rate  = (float)($js['commission_rate'] ?? 2.5);
    $commission_amount= (float)($js['commission_amount'] ?? 0);

    if ($sale_id <= 0 || $gross_amount <= 0 || $commission_amount <= 0) {
        throw new Exception('Missing or invalid values');
    }

    $sql = "INSERT INTO card_commissions
            (sale_id, branch_id, gross_amount, commission_rate, commission_amount, created_at)
            VALUES (:sale_id, :branch_id, :gross_amount, :commission_rate, :commission_amount, NOW())";
    $st = $pdo->prepare($sql);
    $st->execute([
        ':sale_id'          => $sale_id,
        ':branch_id'        => $branch_id,
        ':gross_amount'     => $gross_amount,
        ':commission_rate'  => $commission_rate,
        ':commission_amount'=> $commission_amount,
    ]);

    echo json_encode(['ok'=>true]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok'=>false, 'message'=>$e->getMessage()]);
}
