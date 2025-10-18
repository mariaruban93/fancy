<?php
// api/complete_with_return.php
declare(strict_types=1);
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/header.php'; // $pdo, $user, checkRole()
checkRole(['admin','manager','cashier']);

/* ---------- Helpers ---------- */

// String-style invoice (for VARCHAR columns)
function generate_invoice_no(): string {
    $d = date('Ymd');
    try { return "INV-$d-" . strtoupper(bin2hex(random_bytes(4))); } // 8 hex
    catch (Throwable $e) { return "INV-$d-" . strtoupper(substr(hash('sha256', microtime()), 0, 8)); }
}

// Find an invoice-like column if present
function find_invoice_col(PDO $pdo, string $table = 'sales'): ?string {
    $candidates = ['invoice_no','invoice_number','invoice','receipt_no','bill_no','sale_no','sale_code','reference','ref_no'];
    $q = $pdo->prepare("
        SELECT COLUMN_NAME
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t
    ");
    $q->execute([':t' => $table]);
    $cols = $q->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $have = array_flip($cols);
    foreach ($candidates as $c) if (isset($have[$c])) return $c;
    return null;
}

// Column exists?
function has_col(PDO $pdo, string $table, string $col): bool {
    $q = $pdo->prepare("
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME  = ?
          AND COLUMN_NAME = ?
        LIMIT 1
    ");
    $q->execute([$table, $col]);
    return (bool)$q->fetchColumn();
}

// Get DATA_TYPE of a column (e.g. int, varchar, decimal,…)
function get_col_datatype(PDO $pdo, string $table, string $col): ?string {
    $q = $pdo->prepare("
        SELECT DATA_TYPE
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
        LIMIT 1
    ");
    $q->execute([$table, $col]);
    $t = $q->fetchColumn();
    return $t ? strtolower((string)$t) : null;
}

// Table exists?
function table_exists(PDO $pdo, string $table): bool {
    $q = $pdo->prepare("
        SELECT 1
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
        LIMIT 1
    ");
    $q->execute([$table]);
    return (bool)$q->fetchColumn();
}

// Allocate next INTEGER invoice per branch, inside the current transaction
function allocate_branch_invoice_no(PDO $pdo, int $branchId, string $invoiceCol = 'invoice_no'): int {
    // Prefer a branch_counters table if available
    if (table_exists($pdo, 'branch_counters') && has_col($pdo, 'branch_counters', 'next_invoice')) {
        // Lock row
        $q = $pdo->prepare("SELECT next_invoice FROM branch_counters WHERE branch_id = ? FOR UPDATE");
        $q->execute([$branchId]);
        $next = $q->fetchColumn();

        if ($next === false || $next === null) {
            // Start at 1, and set the next to 2
            $pdo->prepare("INSERT INTO branch_counters (branch_id, next_invoice) VALUES (?, 2)")
                ->execute([$branchId]);
            return 1;
        } else {
            $current = (int)$next;
            $pdo->prepare("UPDATE branch_counters SET next_invoice = next_invoice + 1 WHERE branch_id = ?")
                ->execute([$branchId]);
            return max(1, $current);
        }
    }

    // Fallback: compute from sales table (locks via FOR UPDATE)
    $sql = "SELECT COALESCE(MAX(`$invoiceCol`), 0) + 1 AS next_no
            FROM sales
            WHERE branch_id = ?
            FOR UPDATE";
    $q = $pdo->prepare($sql);
    $q->execute([$branchId]);
    $next = (int)$q->fetchColumn();
    return max(1, $next);
}

/* ---------- Main ---------- */
try {
    $in = json_decode(file_get_contents('php://input'), true);
    if (!is_array($in)) throw new Exception('Invalid JSON');

    $pin        = trim((string)($in['pin'] ?? ''));
    $returnId   = (int)($in['return_id'] ?? 0);
    $newSale    = (array)($in['new_sale'] ?? []);
    $items      = (array)($newSale['items'] ?? []);
    $grandTotal = (float)($newSale['grand_total'] ?? 0);

    if (!preg_match('/^\d{4}$/', $pin)) throw new Exception('Invalid 4-digit code.');
    if ($returnId <= 0) throw new Exception('Return ID missing.');
    if (empty($items)) throw new Exception('No items in new bill.');

    // Resolve branch (admin can choose; others use own)
    $branch_id = 0;
    if (($user['role_name'] ?? '') === 'admin') {
        $branch_id = (int)($_SESSION['sale_branch_id'] ?? ($user['branch_id'] ?? 0));
    } else {
        $branch_id = (int)($user['branch_id'] ?? 0);
    }
    if ($branch_id <= 0) throw new Exception('Branch not resolved.');

    $customer_id   = isset($newSale['customer_id']) ? (int)$newSale['customer_id'] : null;
    $worker_number = isset($newSale['worker_number']) ? (string)$newSale['worker_number'] : null;

    // Lock & validate return
    $s = $pdo->prepare("
        SELECT id, return_type, total_refund, settled_with_sale_id, branch_id
        FROM sale_returns
        WHERE id = ?
        FOR UPDATE
    ");
    $s->execute([$returnId]);
    $ret = $s->fetch(PDO::FETCH_ASSOC);
    if (!$ret) throw new Exception('Return not found.');
    if ((int)$ret['branch_id'] !== $branch_id) throw new Exception('Return belongs to a different branch.');
    if ((int)$ret['settled_with_sale_id'] > 0) throw new Exception('This return is already settled.');
    if (($ret['return_type'] ?? '') !== 'exchange') throw new Exception('Only exchange returns can be merged.');
    $return_total = (float)$ret['total_refund'];

    // Column availability
    $hasTotalAmount    = has_col($pdo, 'sales', 'total_amount');
    $hasAmountReceived = has_col($pdo, 'sales', 'amount_received');
    $hasChangeGiven    = has_col($pdo, 'sales', 'change_given');
    $hasOverallDisc    = has_col($pdo, 'sales', 'overall_discount');
    $invoice_col       = find_invoice_col($pdo, 'sales');

    $pdo->beginTransaction();

    // Decide invoice value if we have an invoice column
    $invoiceValue = null;
    $invoiceIsNumeric = false;
    if ($invoice_col !== null) {
        $dt = get_col_datatype($pdo, 'sales', $invoice_col);
        $invoiceIsNumeric = in_array($dt ?? '', ['int','bigint','mediumint','smallint','tinyint','decimal','numeric','float','double'], true);
        if ($invoiceIsNumeric) {
            // Allocate next integer per branch (prevents 5-0 duplicates)
            $invoiceValue = allocate_branch_invoice_no($pdo, $branch_id, $invoice_col);
        } else {
            // Use textual generator
            $invoiceValue = generate_invoice_no();
        }
    }

    // Build INSERT for sales header
    $cols  = ['branch_id','sale_date','sold_by','customer_id','worker_number'];
    $marks = ['?','NOW()','?','?','?'];
    $vals  = [$branch_id, (int)($user['id'] ?? 0), $customer_id, $worker_number];

    if ($invoice_col !== null) {            // add invoice reference if the column exists
        $cols[]  = "`$invoice_col`";
        $marks[] = '?';
        $vals[]  = $invoiceValue;
    }
    if ($hasTotalAmount)    { $cols[]='total_amount';     $marks[]='0'; }
    if ($hasAmountReceived) { $cols[]='amount_received';  $marks[]='0'; }
    if ($hasChangeGiven)    { $cols[]='change_given';     $marks[]='0'; }
    if ($hasOverallDisc)    { $cols[]='overall_discount'; $marks[]='0'; }

    $insSql = "INSERT INTO sales (".implode(',', $cols).") VALUES (".implode(',', $marks).")";
    $insHdr = $pdo->prepare($insSql);
    $insHdr->execute($vals);
    $sale_id = (int)$pdo->lastInsertId();

    // Insert items & reduce stock
    $insItem = $pdo->prepare("
        INSERT INTO sale_items (sale_id, product_id, batch_id, quantity, selling_price, discount_type, discount_value, tax_rate)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $updStock = $pdo->prepare("UPDATE stock_batches SET quantity = quantity - ? WHERE id = ? AND branch_id = ?");

    foreach ($items as $it) {
        $product_id = (int)($it['product_id'] ?? 0);
        $batch_id   = (int)($it['batch_id'] ?? 0);
        $qty        = (int)($it['qty'] ?? 0);
        $price      = (float)($it['price'] ?? 0.0);
        $disc_type  = $it['discount_type'] ?? null;
        $disc_val   = isset($it['discount_value']) ? (float)$it['discount_value'] : 0.0;
        $tax_rate   = isset($it['tax_rate']) ? (float)$it['tax_rate'] : 0.0;

        if ($product_id <= 0 || $batch_id <= 0 || $qty <= 0) throw new Exception('Invalid item payload.');

        // Guard stock
        $cur = $pdo->prepare("SELECT quantity FROM stock_batches WHERE id=? AND branch_id=? FOR UPDATE");
        $cur->execute([$batch_id, $branch_id]);
        $sb = $cur->fetch(PDO::FETCH_ASSOC);
        if (!$sb) throw new Exception("Batch {$batch_id} not found in branch.");
        if ((float)$sb['quantity'] < $qty) throw new Exception("Insufficient stock for batch {$batch_id}.");

        $insItem->execute([$sale_id, $product_id, $batch_id, $qty, $price, $disc_type, $disc_val, $tax_rate]);
        $updStock->execute([$qty, $batch_id, $branch_id]);
        if ($updStock->rowCount() !== 1) throw new Exception('Failed to update stock.');
    }

    // Link return -> sale
    $pdo->prepare("UPDATE sale_returns SET settled_with_sale_id = ? WHERE id = ?")
        ->execute([$sale_id, $returnId]);

    // Persist grand total if column exists
    if ($hasTotalAmount) {
        $pdo->prepare("UPDATE sales SET total_amount = ? WHERE id = ?")
            ->execute([$grandTotal, $sale_id]);
    }

    // Balancing payment: +ve collect; -ve refund
    $balance = round($grandTotal - $return_total, 2);
    $pdo->prepare("
        INSERT INTO sale_payments (sale_id, method, amount, cheque_number, bank_name, bank_branch, deposit_date, created_at)
        VALUES (?, 'cash', ?, NULL, NULL, NULL, NULL, NOW())
    ")->execute([$sale_id, $balance]);

    // Update tender/change if such columns exist
    $setParts = [];
    $params   = [':id' => $sale_id];
    if ($hasAmountReceived) { $params[':ar'] = ($balance >= 0) ? $balance : 0.0; $setParts[] = "amount_received = :ar"; }
    if ($hasChangeGiven)    { $params[':cg'] = ($balance < 0) ? abs($balance) : 0.0; $setParts[] = "change_given = :cg"; }
    if (!empty($setParts)) {
        $upd = $pdo->prepare("UPDATE sales SET ".implode(', ', $setParts)." WHERE id = :id");
        $upd->execute($params);
    }

    // Activity log (best-effort)
    try {
        $al = $pdo->prepare("INSERT INTO activity_logs (user_id, action, description, branch_id) VALUES (?, 'Sale', ?, ?)");
        $al->execute([(int)($user['id'] ?? 0), 'Created sale #'.$sale_id.' merged with return #'.$returnId, $branch_id]);
    } catch (Throwable $e) { /* ignore */ }

    $pdo->commit();

    echo json_encode([
        'ok'        => true,
        'sale_id'   => $sale_id,
        'print_url' => 'print_invoice.php?id=' . $sale_id . ($returnId ? '&return_id='.(int)$returnId : '')
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
