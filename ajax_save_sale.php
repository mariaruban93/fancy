<?php
// Endpoint to save a completed sale
// Expects JSON with:
//   cart: [ {product_id, batch_id, quantity, price, discount_type?, discount_value?, tax_rate?}, ... ]
//   payments: [ {method, amount, cheque_number?, bank_name?, bank_branch?, deposit_date?}, ... ]
//   overall_discount?: number
//   customer_id?: number | ""
//   worker_number?: string | ""

// Bootstrap & auth
require_once 'includes/header.php';
checkRole(['admin','manager','cashier']);

header('Content-Type: application/json');

// Only POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method']);
    exit;
}

// Parse payload
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!$data || !isset($data['cart']) || !is_array($data['cart'])) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid payload']);
    exit;
}

// Branch
if ($user['role_name'] === 'admin') {
    $branch_id = isset($_SESSION['sale_branch_id']) ? (int)$_SESSION['sale_branch_id'] : 0;
} else {
    $branch_id = isset($user['branch_id']) ? (int)$user['branch_id'] : 0;
}
if ($branch_id <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'No branch selected for sale']);
    exit;
}

// Cart: normalize
$cart = [];
foreach ($data['cart'] as $item) {
    if (!isset($item['product_id'], $item['batch_id'], $item['quantity'], $item['price'])) continue;
    $cart[] = [
        'product_id'     => (int)$item['product_id'],
        'batch_id'       => (int)$item['batch_id'],       // may be 0 → auto FIFO
        'quantity'       => (int)$item['quantity'],
        'price'          => (float)$item['price'],
        'discount_type'  => $item['discount_type'] ?? null,          // 'percentage' | 'fixed' | null
        'discount_value' => isset($item['discount_value']) ? (float)$item['discount_value'] : 0.0,
        'tax_rate'       => isset($item['tax_rate']) ? (float)$item['tax_rate'] : 0.0,
    ];
}
if (empty($cart)) {
    echo json_encode(['status' => 'error', 'message' => 'Cart is empty']);
    exit;
}

// Overall invoice discount
$overall_discount = 0.0;
if (isset($data['overall_discount']) && is_numeric($data['overall_discount'])) {
    $overall_discount = (float)$data['overall_discount'];
    if ($overall_discount < 0) $overall_discount = 0.0;
}

// Payments
$payments = [];
if (isset($data['payments']) && is_array($data['payments'])) {
    foreach ($data['payments'] as $p) {
        if (!isset($p['method']) || !isset($p['amount'])) continue;
        $method = trim($p['method']);
        $amount = (float)$p['amount'];
        if ($method === '' || $amount < 0) continue;
        $rec = [
            'method'        => $method,
            'amount'        => $amount,
            'cheque_number' => null,
            'bank_name'     => null,
            'bank_branch'   => null,
            'deposit_date'  => null,
            // New: transfer date for cheque or bank transfer payments (can be null)
            'transfer_date' => null,
        ];
        // Capture method-specific details
        if ($method === 'cheque') {
            // Cheque: capture cheque number, bank name and branch
            if (!empty($p['cheque_number'])) $rec['cheque_number'] = trim($p['cheque_number']);
            if (!empty($p['bank_name']))     $rec['bank_name']     = trim($p['bank_name']);
            if (!empty($p['bank_branch']))   $rec['bank_branch']   = trim($p['bank_branch']);
            // keep deposit_date NULL at sale time; it’s set when actually deposited
        } elseif ($method === 'bank_transfer') {
            // Bank transfer: capture the bank name (other fields remain null)
            if (!empty($p['bank_name'])) $rec['bank_name'] = trim($p['bank_name']);
        }
        // Always capture transfer_date if provided (for cheque or other methods)
        if (!empty($p['transfer_date'])) {
            $rec['transfer_date'] = $p['transfer_date'];
        }
        $payments[] = $rec;
    }
}

// Discount guard: line price cannot go below cost
foreach ($cart as $it) {
    $stCost = $pdo->prepare("SELECT cost_price, name FROM products WHERE id = ?");
    $stCost->execute([$it['product_id']]);
    $prod = $stCost->fetch(PDO::FETCH_ASSOC);
    if ($prod) {
        $cost = (float)$prod['cost_price'];
        $net  = (float)$it['price'];
        if (!empty($it['discount_type']) && (float)$it['discount_value'] > 0) {
            if ($it['discount_type'] === 'percentage') {
                $net = $it['price'] * (1 - ($it['discount_value'] / 100.0));
            } else { // fixed per unit
                $net = $it['price'] - (float)$it['discount_value'];
            }
        }
        if ($net + 0.00001 < $cost) {
            echo json_encode([
                'status'  => 'error',
                'message' => 'Discount not allowed: selling price for ' . $prod['name'] . ' falls below cost price'
            ]);
            exit;
        }
    }
}

// Optional references
$customer_id = null;
if (!empty($data['customer_id'])) {
    $customer_id = (int)$data['customer_id'];
    if ($customer_id <= 0) $customer_id = null;
}
$worker_number = null;
if (isset($data['worker_number']) && $data['worker_number'] !== '') {
    $worker_number = trim($data['worker_number']);
    if ($worker_number === '') $worker_number = null;
}

// Helper: commission rate (try branch_settings/settings else fallback)
function get_card_commission_rate(PDO $pdo, int $branchId): float {
    // Try branch_settings table
    try {
        $q = $pdo->prepare("SELECT value FROM branch_settings WHERE branch_id = ? AND `key` = 'card_commission_rate' LIMIT 1");
        $q->execute([$branchId]);
        $val = $q->fetchColumn();
        if ($val !== false && $val !== null && is_numeric($val)) {
            $r = (float)$val;
            if ($r >= 0 && $r <= 100) return $r;
        }
    } catch (Throwable $e) { /* ignore */ }

    // Try global settings
    try {
        $q = $pdo->prepare("SELECT value FROM settings WHERE `key` = 'card_commission_rate' LIMIT 1");
        $q->execute();
        $val = $q->fetchColumn();
        if ($val !== false && $val !== null && is_numeric($val)) {
            $r = (float)$val;
            if ($r >= 0 && $r <= 100) return $r;
        }
    } catch (Throwable $e) { /* ignore */ }

    // Fallback default
    return 2.50;
}

// Transaction
$pdo->beginTransaction();
try {
    $total = 0.0;
    $discountTotal = 0.0;
    $taxTotal = 0.0;

    // Check invoice_no column
    $hasInvoiceColumn = false;
    try {
        $colCheck = $pdo->prepare("SHOW COLUMNS FROM sales LIKE 'invoice_no'");
        $colCheck->execute();
        $hasInvoiceColumn = (bool)$colCheck->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { $hasInvoiceColumn = false; }

    // Generate invoice_no if available
    $invoice_no = null;
    if ($hasInvoiceColumn) {
        try {
            $invStmt = $pdo->prepare("SELECT next_invoice FROM branch_counters WHERE branch_id = ? FOR UPDATE");
            $invStmt->execute([$branch_id]);
            $nextInv = $invStmt->fetchColumn();
            if ($nextInv === false || $nextInv === null) {
                $pdo->prepare("INSERT INTO branch_counters (branch_id, next_invoice) VALUES (?, 2)")
                    ->execute([$branch_id]);
                $invoice_no = 1;
            } else {
                $invoice_no = (int)$nextInv;
                $pdo->prepare("UPDATE branch_counters SET next_invoice = next_invoice + 1 WHERE branch_id = ?")
                    ->execute([$branch_id]);
            }
        } catch (Throwable $e) {
            $invoice_no = null;
            $hasInvoiceColumn = false;
        }
    }

    // Insert sale shell
    if ($hasInvoiceColumn) {
        $stmt = $pdo->prepare(
            "INSERT INTO sales (branch_id, invoice_no, sale_date, sold_by, total_amount, customer_id, worker_number)
             VALUES (?, ?, NOW(), ?, 0, ?, ?)"
        );
        $stmt->execute([$branch_id, $invoice_no, $user['id'], $customer_id, $worker_number]);
    } else {
        $stmt = $pdo->prepare(
            "INSERT INTO sales (branch_id, sale_date, sold_by, total_amount, customer_id, worker_number)
             VALUES (?, NOW(), ?, 0, ?, ?)"
        );
        $stmt->execute([$branch_id, $user['id'], $customer_id, $worker_number]);
    }
    $sale_id = (int)$pdo->lastInsertId();

    // Items — respect user-selected batch (if >0), else FIFO by earliest expiry
    foreach ($cart as $item) {
        $productId = (int)$item['product_id'];
        $qtyNeeded = (int)$item['quantity'];
        $unitPrice = (float)$item['price'];
        $discType  = $item['discount_type'] ?? null;
        $discVal   = isset($item['discount_value']) ? (float)$item['discount_value'] : 0.0;
        $taxRate   = isset($item['tax_rate']) ? (float)$item['tax_rate'] : 0.0;
        $chosenBatchId = isset($item['batch_id']) ? (int)$item['batch_id'] : 0;

        if ($chosenBatchId > 0) {
            // Lock chosen batch
            $st = $pdo->prepare("
                SELECT id, quantity
                FROM stock_batches
                WHERE id = ? AND product_id = ? AND branch_id = ?
                FOR UPDATE
            ");
            $st->execute([$chosenBatchId, $productId, $branch_id]);
            $batch = $st->fetch(PDO::FETCH_ASSOC);

            if (!$batch) {
                echo json_encode(['status'=>'error','message'=>'Selected batch not found for this branch.']);
                $pdo->rollBack(); exit;
            }
            if ((int)$batch['quantity'] < $qtyNeeded) {
                echo json_encode(['status'=>'error','message'=>'Not enough quantity in the selected batch.']);
                $pdo->rollBack(); exit;
            }

            // Record the sale line
            $stmt = $pdo->prepare("
                INSERT INTO sale_items
                (sale_id, product_id, batch_id, quantity, selling_price, discount_type, discount_value, tax_rate)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$sale_id, $productId, $chosenBatchId, $qtyNeeded, $unitPrice, $discType, $discVal, $taxRate]);

            // Deduct from selected batch
            $upd = $pdo->prepare("UPDATE stock_batches SET quantity = quantity - ? WHERE id = ?");
            $upd->execute([$qtyNeeded, $chosenBatchId]);

        } else {
            // FIFO by earliest expiry
            $remaining = $qtyNeeded;

            $batchStmt = $pdo->prepare("
                SELECT id, quantity
                FROM stock_batches
                WHERE product_id = ? AND branch_id = ? AND quantity > 0
                ORDER BY expiry_date IS NULL, expiry_date ASC, id ASC
                FOR UPDATE
            ");
            $batchStmt->execute([$productId, $branch_id]);
            $batches = $batchStmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($batches as $batch) {
                if ($remaining <= 0) break;
                $deduct = min($remaining, (int)$batch['quantity']);

                $stmt = $pdo->prepare("
                    INSERT INTO sale_items
                    (sale_id, product_id, batch_id, quantity, selling_price, discount_type, discount_value, tax_rate)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$sale_id, $productId, (int)$batch['id'], $deduct, $unitPrice, $discType, $discVal, $taxRate]);

                $upd = $pdo->prepare("UPDATE stock_batches SET quantity = quantity - ? WHERE id = ?");
                $upd->execute([$deduct, (int)$batch['id']]);

                $remaining -= $deduct;
            }

            if ($remaining > 0) {
                echo json_encode(['status'=>'error','message'=>'Insufficient stock to fulfill this item.']);
                $pdo->rollBack(); exit;
            }
        }

        // === RESTORED: totals for this original cart line ===
        $lineGross = $unitPrice * $qtyNeeded;

        $lineDisc = 0.0;
        if (!empty($discType) && $discVal > 0) {
            if ($discType === 'percentage') {
                $lineDisc = ($lineGross * $discVal) / 100.0;
            } else { // fixed per unit
                $lineDisc = $discVal * $qtyNeeded;
                if ($lineDisc > $lineGross) $lineDisc = $lineGross;
            }
        }

        $lineTax = ($lineGross - $lineDisc) * ($taxRate / 100.0);
        $lineNet = $lineGross - $lineDisc + $lineTax;

        $total         += $lineNet;
        $discountTotal += $lineDisc;
        $taxTotal      += $lineTax;
        // === END totals block ===
    }

    // Apply invoice-level discount (after lines)
    if ($overall_discount > 0) {
        if ($overall_discount > $total) $overall_discount = $total;
        $total -= $overall_discount;
    }

    // Amount columns & overall column
    $hasAmountCols = false;
    try {
        $colCheckAmt = $pdo->prepare("SHOW COLUMNS FROM sales LIKE 'amount_received'");
        $colCheckAmt->execute();
        $hasAmountCols = (bool)$colCheckAmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { $hasAmountCols = false; }

    $hasOverallCol = false;
    try {
        $colCheckOverall = $pdo->prepare("SHOW COLUMNS FROM sales LIKE 'overall_discount'");
        $colCheckOverall->execute();
        $hasOverallCol = (bool)$colCheckOverall->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { $hasOverallCol = false; }

    // Compute tendered + change for display columns (doesn't affect allocations)
    $totalTendered = 0.0;
    foreach ($payments as $pmAmt) { $totalTendered += (float)($pmAmt['amount'] ?? 0); }
    $changeDue = ($totalTendered > $total) ? round($totalTendered - $total, 2) : 0.0;

    // Persist sale header totals
    if ($hasAmountCols) {
        if ($hasOverallCol) {
            $stmt = $pdo->prepare("UPDATE sales SET total_amount = ?, amount_received = ?, change_given = ?, overall_discount = ? WHERE id = ?");
            $stmt->execute([$total, $totalTendered, $changeDue, $overall_discount, $sale_id]);
        } else {
            $stmt = $pdo->prepare("UPDATE sales SET total_amount = ?, amount_received = ?, change_given = ? WHERE id = ?");
            $stmt->execute([$total, $totalTendered, $changeDue, $sale_id]);
        }
    } else {
        if ($hasOverallCol) {
            $stmt = $pdo->prepare("UPDATE sales SET total_amount = ?, overall_discount = ? WHERE id = ?");
            $stmt->execute([$total, $overall_discount, $sale_id]);
        } else {
            $stmt = $pdo->prepare("UPDATE sales SET total_amount = ? WHERE id = ?");
            $stmt->execute([$total, $sale_id]);
        }
    }

    // Save payments (clamped to outstanding total)
    if (!empty($payments)) {
        $payStmt = $pdo->prepare("
            INSERT INTO sale_payments (sale_id, method, amount, cheque_number, bank_name, bank_branch, deposit_date, transfer_date)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");

        // Prepare helper inserts for commission/expenses/journal
        $ccStmt = $pdo->prepare("
            INSERT INTO card_commissions (sale_id, branch_id, gross_amount, commission_rate, commission_amount)
            VALUES (?, ?, ?, ?, ?)
        ");
        $expStmt = $pdo->prepare("
            INSERT INTO expenses (branch_id, expense_date, description, category, subcategory, payment_method, payee_name, amount, created_by)
            VALUES (?, CURDATE(), ?, 'Bank Charges', 'Card Commission', 'bank', 'Card Processor', ?, ?)
        ");
        $jrBankStmt = $pdo->prepare("
            INSERT INTO journal_entries (branch_id, entry_date, account_type, amount, description, created_by)
            VALUES (?, CURDATE(), 'bank', ?, ?, ?)
        ");
        $jrOtherStmt = $pdo->prepare("
            INSERT INTO journal_entries (branch_id, entry_date, account_type, amount, description, created_by)
            VALUES (?, CURDATE(), 'other', ?, ?, ?)
        ");

        $amountRemaining = $total;
        $commissionRate  = get_card_commission_rate($pdo, $branch_id);

        foreach ($payments as $pm) {
            if ($amountRemaining <= 0) break;

            $method      = $pm['method'];
            $tenderedAmt = (float)$pm['amount'];
            $amt         = min($tenderedAmt, $amountRemaining);   // applied part
            $chqNum      = $pm['cheque_number'] ?? null;
            $bankNm      = $pm['bank_name'] ?? null;
            $bankBr      = $pm['bank_branch'] ?? null;
            $depDt       = null; // set later when deposited
            // transfer_date may come from frontend for cheque/bank transfer payments
            $trfDt       = $pm['transfer_date'] ?? null;

            // Insert the applied payment (includes transfer_date column)
            $payStmt->execute([$sale_id, $method, $amt, $chqNum, $bankNm, $bankBr, $depDt, $trfDt]);

            // If card/upi, create commission rows + expense + journal entries
            if (in_array($method, ['card','upi'], true) && $amt > 0.00001) {
                $commissionAmount = round($amt * $commissionRate / 100, 2);

                // 1) Card commission audit table
                $ccStmt->execute([$sale_id, $branch_id, $amt, $commissionRate, $commissionAmount]);

                // 2) Expense row (shows in Expense screens)
                $expDesc = "Card commission for Sale #{$sale_id}";
                $expStmt->execute([$branch_id, $expDesc, $commissionAmount, $user['id'] ?? 0]);

                // 3) Journal entries to net the bank: bank -fee, other +fee
                $jrDesc = "Card commission fee for Sale #{$sale_id}";
                $jrBankStmt->execute([$branch_id, -$commissionAmount, $jrDesc, $user['id'] ?? 0]); // bank ↓
                $jrOtherStmt->execute([$branch_id,  $commissionAmount, $jrDesc, $user['id'] ?? 0]); // other ↑
            }

            $amountRemaining -= $amt;
        }
    }

    $pdo->commit();

    try {
        logActivity($pdo, $user['id'], 'Sale', 'Created sale #' . $sale_id, $branch_id);
    } catch (Throwable $e) { /* ignore logging error */ }

    echo json_encode(['status' => 'success', 'sale_id' => $sale_id]);

} catch (Throwable $e) {
    $pdo->rollBack();
    echo json_encode(['status' => 'error', 'message' => 'Failed to save sale: ' . $e->getMessage()]);
}
