<?php
/**
 * Save stock adjustment (increase/decrease multiple lines on existing batches).
 * Expected JSON:
 * {
 *   branch_id: number,
 *   header_reason?: string,
 *   header_note?: string,
 *   lines: [
 *     { product_id, batch_id, delta_qty, line_reason?, line_note? }, ...
 *   ]
 * }
 *
 * Tables used (created if missing):
 *  - stock_adjustments
 *      (id, branch_id, adjusted_by, header_reason, header_note, total_lines, adj_datetime)
 *  - stock_adjustment_items
 *      (id, adjustment_id, product_id, batch_id, quantity_before, quantity_delta,
 *       quantity_after, cost_price, value_impact, line_reason, line_note)
 *  - journal_entries
 *      (id, branch_id, entry_date, account_type, amount, description, created_by, created_at)
 *
 * Ledger rule:
 *   delta < 0 : stock amount negative; 'other' positive with "Drawings — ..."
 *   delta > 0 : stock amount positive; 'other' positive with "Opening Equity — ..."
 */

require_once 'includes/header.php';
checkRole(['admin','manager']); // tighter than sales

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  echo json_encode(['status'=>'error','message'=>'Invalid method']); exit;
}

$raw = file_get_contents('php://input');
$js  = json_decode($raw, true);

if (!$js || !isset($js['branch_id']) || empty($js['lines']) || !is_array($js['lines'])) {
  echo json_encode(['status'=>'error','message'=>'Invalid payload']); exit;
}

$branch_id = (int)$js['branch_id'];
if ($branch_id <= 0) { echo json_encode(['status'=>'error','message'=>'Branch required']); exit; }

// Managers: only own branch
if (strtolower($user['role_name']) === 'manager' && (int)$user['branch_id'] !== $branch_id) {
  echo json_encode(['status'=>'error','message'=>'You cannot adjust other branches']); exit;
}

$header_reason = isset($js['header_reason']) ? trim((string)$js['header_reason']) : null;
$header_note   = isset($js['header_note'])   ? trim((string)$js['header_note'])   : null;

// --------- Normalize & validate input lines ----------
$lines = [];
foreach ($js['lines'] as $ln) {
  if (!isset($ln['product_id'],$ln['batch_id'],$ln['delta_qty'])) continue;
  $pid  = (int)$ln['product_id'];
  $bid  = (int)$ln['batch_id'];
  $dq   = (float)$ln['delta_qty']; // +/- allowed
  if ($pid<=0 || $bid<=0 || abs($dq) < 0.00001) continue;
  $lines[] = [
    'product_id'  => $pid,
    'batch_id'    => $bid,
    'delta_qty'   => $dq,
    'line_reason' => isset($ln['line_reason']) ? trim((string)$ln['line_reason']) : null,
    'line_note'   => isset($ln['line_note'])   ? trim((string)$ln['line_note'])   : null
  ];
}
if (empty($lines)) {
  echo json_encode(['status'=>'error','message'=>'No valid lines']); exit;
}

// --------- Utilities ----------
function ensure_tables(PDO $pdo): void {
  // stock_adjustments
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS `stock_adjustments` (
      `id` INT NOT NULL AUTO_INCREMENT,
      `branch_id` INT NOT NULL,
      `adjusted_by` INT NOT NULL,
      `header_reason` VARCHAR(80) DEFAULT NULL,
      `header_note` VARCHAR(255) DEFAULT NULL,
      `total_lines` INT NOT NULL,
      `adj_datetime` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `idx_sa_branch_time` (`branch_id`,`adj_datetime`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");

  // stock_adjustment_items
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS `stock_adjustment_items` (
      `id` INT NOT NULL AUTO_INCREMENT,
      `adjustment_id` INT NOT NULL,
      `product_id` INT NOT NULL,
      `batch_id` INT NOT NULL,
      `quantity_before` DECIMAL(15,4) NOT NULL,
      `quantity_delta`  DECIMAL(15,4) NOT NULL,
      `quantity_after`  DECIMAL(15,4) NOT NULL,
      `cost_price`      DECIMAL(12,4) NOT NULL,
      `value_impact`    DECIMAL(12,2) NOT NULL,
      `line_reason` VARCHAR(80) DEFAULT NULL,
      `line_note`   VARCHAR(255) DEFAULT NULL,
      PRIMARY KEY (`id`),
      KEY `idx_sai_adj` (`adjustment_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");

  // journal_entries (ledger postings)
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS `journal_entries` (
      `id` INT NOT NULL AUTO_INCREMENT,
      `branch_id` INT NOT NULL,
      `entry_date` DATE NOT NULL,
      `account_type` ENUM('stock','cash','supplier','customer','other','bank','wallet','cargo') NOT NULL,
      `amount` DECIMAL(12,2) NOT NULL,
      `description` VARCHAR(255) DEFAULT NULL,
      `created_by` INT NOT NULL,
      `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `idx_je_branch_date` (`branch_id`,`entry_date`),
      KEY `idx_je_type` (`account_type`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");
}

try {
  // Create missing tables if needed (prevents "table not found")
  ensure_tables($pdo);

  // Pre-check batches: same branch, product matches, no negative after apply
  $stmtB = $pdo->prepare("
    SELECT id, product_id, branch_id, quantity, cost_price
    FROM stock_batches
    WHERE id=? LIMIT 1
  ");
  foreach ($lines as $ln) {
    $stmtB->execute([$ln['batch_id']]);
    $b = $stmtB->fetch(PDO::FETCH_ASSOC);
    if (!$b) {
      echo json_encode(['status'=>'error','message'=>'Batch not found (#'.$ln['batch_id'].')']); exit;
    }
    if ((int)$b['branch_id'] !== $branch_id) {
      echo json_encode(['status'=>'error','message'=>'Batch does not belong to selected branch']); exit;
    }
    if ((int)$b['product_id'] !== (int)$ln['product_id']) {
      echo json_encode(['status'=>'error','message'=>'Product/Batch mismatch']); exit;
    }
    if ($ln['delta_qty'] < 0 && ((float)$b['quantity'] + $ln['delta_qty']) < -0.00001) {
      echo json_encode(['status'=>'error','message'=>'Insufficient stock for reduction on batch #'.$ln['batch_id']]); exit;
    }
  }

  $pdo->beginTransaction();

  // Header
  $insH = $pdo->prepare("
    INSERT INTO stock_adjustments (branch_id, adjusted_by, header_reason, header_note, total_lines, adj_datetime)
    VALUES (?, ?, ?, ?, ?, NOW())
  ");
  $insH->execute([$branch_id, (int)$user['id'], $header_reason, $header_note, count($lines)]);
  $adjId = (int)$pdo->lastInsertId();

  // Statements
  $selB = $pdo->prepare("SELECT quantity, cost_price FROM stock_batches WHERE id=? FOR UPDATE");
  $updB = $pdo->prepare("UPDATE stock_batches SET quantity = ? WHERE id=?");
  $insL = $pdo->prepare("
    INSERT INTO stock_adjustment_items
      (adjustment_id, product_id, batch_id, quantity_before, quantity_delta, quantity_after, cost_price, value_impact, line_reason, line_note)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
  ");
  $insJ = $pdo->prepare("
    INSERT INTO journal_entries (branch_id, entry_date, account_type, amount, description, created_by, created_at)
    VALUES (?, ?, ?, ?, ?, ?, NOW())
  ");

  $today = date('Y-m-d');

  foreach ($lines as $ln) {
    // Lock batch and recalc
    $selB->execute([$ln['batch_id']]);
    $b = $selB->fetch(PDO::FETCH_ASSOC);
    if (!$b) { throw new Exception('Batch vanished'); }

    $before = (float)$b['quantity'];
    $cost   = (float)$b['cost_price'];
    $after  = $before + (float)$ln['delta_qty'];
    if ($after < -0.00001) { throw new Exception('Negative stock would result'); }

    // Update physical qty
    $updB->execute([$after, $ln['batch_id']]);

    // Value impact at current batch cost
    $impact = round($ln['delta_qty'] * $cost, 2);

    // Write line
    $insL->execute([
      $adjId,
      $ln['product_id'],
      $ln['batch_id'],
      $before,
      $ln['delta_qty'],
      $after,
      $cost,
      $impact,
      $ln['line_reason'],
      $ln['line_note']
    ]);

    // -------- Ledger postings --------
    // Stock side: signed amount (impact)
    $desc_stock = "Adj #$adjId — P:".$ln['product_id']."/B:".$ln['batch_id'].($ln['line_reason']? " — ".$ln['line_reason'] : "");
    $insJ->execute([$branch_id, $today, 'stock', $impact, $desc_stock, (int)$user['id']]);

    // Equity side: positive amount with label
    if ($ln['delta_qty'] < 0) {
      $desc_eq = "Drawings — Stock Adjustment #$adjId (P:".$ln['product_id']."/B:".$ln['batch_id'].")".($ln['line_reason']? " — ".$ln['line_reason'] : "");
      $insJ->execute([$branch_id, $today, 'other', abs($impact), $desc_eq, (int)$user['id']]);
    } elseif ($ln['delta_qty'] > 0) {
      $desc_eq = "Opening Equity — Stock Adjustment #$adjId (P:".$ln['product_id']."/B:".$ln['batch_id'].")".($ln['line_reason']? " — ".$ln['line_reason'] : "");
      $insJ->execute([$branch_id, $today, 'other', abs($impact), $desc_eq, (int)$user['id']]);
    }
  }

  $pdo->commit();

  // Activity log (best effort)
  try {
    if (function_exists('logActivity')) {
      logActivity($pdo, $user['id'], 'Stock', 'Adjustment #'.$adjId.' ('.$branch_id.')', $branch_id);
    }
  } catch (Throwable $e) {}

  echo json_encode(['status'=>'success','adjustment_id'=>$adjId]);

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
}
