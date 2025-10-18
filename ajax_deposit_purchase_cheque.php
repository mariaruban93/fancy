<?php
/**
 * ajax_deposit_purchase_cheque.php
 * Input: POST payment_id, deposit_date (Y-m-d), bank_id
 * - Validates branch permissions.
 * - Sets deposit_date, bank account column (if present),
 *   and a clear timestamp NOW (cleared_on/clear_date/transferred_on) or status='cleared'.
 * Output: JSON {status:"success"} or {status:"error", message:"..."}
 * PHP 8 compatible.
 */
require_once 'includes/header.php';
header('Content-Type: application/json');
checkRole(['admin','manager']);

function col_exists(PDO $pdo, string $table, string $col): bool {
  try { return (bool)$pdo->query("SHOW COLUMNS FROM `$table` LIKE ".$pdo->quote($col))->fetch(PDO::FETCH_ASSOC); }
  catch (Throwable $e) { return false; }
}

function table_exists(PDO $pdo, string $table): bool {
  try {
    $stmt = $pdo->query("SHOW TABLES LIKE ".$pdo->quote($table));
    return (bool)($stmt && $stmt->fetchColumn());
  } catch (Throwable $e) {
    return false;
  }
}

$source_raw   = isset($_POST['source']) ? strtolower(trim((string)$_POST['source'])) : 'purchase';
$payment_id   = isset($_POST['payment_id']) ? (int)$_POST['payment_id'] : 0;
$deposit_date = $_POST['deposit_date'] ?? '';
$bank_id      = isset($_POST['bank_id']) ? (int)$_POST['bank_id'] : 0;
$source       = in_array($source_raw, ['purchase','opening'], true) ? $source_raw : 'purchase';

$today = date('Y-m-d');
$nowdt = date('Y-m-d H:i:s');

if (!$payment_id || !$deposit_date || !$bank_id) { echo json_encode(['status'=>'error','message'=>'Missing fields']); exit; }
if ($deposit_date < $today) { echo json_encode(['status'=>'error','message'=>'Deposit date cannot be before today']); exit; }

try {
  $pdo->beginTransaction();

  if ($source === 'opening') {
    if (!table_exists($pdo, 'supplier_opening_payments')) {
      throw new RuntimeException('Opening payment tracking table missing.');
    }
    $branchCol = col_exists($pdo, 'supplier_opening_payments', 'branch_id');
    $bankCol   = col_exists($pdo, 'supplier_opening_payments', 'bank_account_id');
    $st = $pdo->prepare("SELECT id, method" . ($branchCol ? ', branch_id' : '') . " , deposit_date FROM supplier_opening_payments WHERE id=:id");
    $st->execute([':id'=>$payment_id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new RuntimeException('Payment not found.');
    if (strtolower((string)$row['method']) !== 'cheque') {
      throw new RuntimeException('This payment is not a cheque.');
    }
    if (!empty($row['deposit_date']) && $row['deposit_date'] !== '0000-00-00') {
      throw new RuntimeException('Cheque already deposited.');
    }

    if (($user['role_name'] ?? '') !== 'admin' && $branchCol) {
      $myb = (int)($user['branch_id'] ?? 0);
      if ($myb && $myb !== (int)$row['branch_id']) throw new RuntimeException('Not allowed for this branch.');
    }

    $stmtBank = $pdo->prepare("SELECT id, branch_id FROM bank_accounts WHERE id = ?");
    $stmtBank->execute([$bank_id]);
    $bank = $stmtBank->fetch(PDO::FETCH_ASSOC);
    if (!$bank) throw new RuntimeException('Bank not found.');
    if ($branchCol) {
      $paymentBranch = (int)$row['branch_id'];
      $bankBranchId = is_null($bank['branch_id']) ? 0 : (int)$bank['branch_id'];
      if ($paymentBranch > 0 && $bankBranchId > 0 && $paymentBranch !== $bankBranchId) {
        throw new RuntimeException('Selected bank does not belong to the supplier\'s branch.');
      }
    }

    $sets = ['deposit_date = :dd'];
    $params = [':dd'=>$deposit_date, ':id'=>$payment_id];
    if ($bankCol) {
      $sets[] = 'bank_account_id = :ba';
      $params[':ba'] = $bank_id;
    }
    $upd = $pdo->prepare("UPDATE supplier_opening_payments SET ".implode(', ',$sets)." WHERE id=:id");
    $upd->execute($params);

    $pdo->commit();
    echo json_encode(['status'=>'success']);
    return;
  }

  $select = "SELECT pp.id, pp.purchase_id, p.branch_id";
  if (col_exists($pdo,'purchase_payments','method')) $select .= ", LOWER(pp.method) AS m";
  else $select .= ", '' AS m";

  $st = $pdo->prepare($select." FROM purchase_payments pp JOIN purchases p ON pp.purchase_id=p.id WHERE pp.id=:id");
  $st->execute([':id'=>$payment_id]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) throw new RuntimeException('Payment not found.');

  if (($user['role_name'] ?? '') !== 'admin') {
    $myb = (int)($user['branch_id'] ?? 0);
    if ($myb && $myb !== (int)$row['branch_id']) throw new RuntimeException('Not allowed for this branch.');
  }

  if (isset($row['m']) && $row['m'] !== '' && !in_array($row['m'], ['cheque','chuque','chq','cheq','check','cq'], true)) {
    throw new RuntimeException('This payment is not a cheque.');
  }

  $sets = []; $par = [':id'=>$payment_id];

  if (col_exists($pdo,'purchase_payments','deposit_date')) { $sets[]="deposit_date=:dd"; $par[':dd']=$deposit_date; }

  if (col_exists($pdo,'purchase_payments','bank_account_id')) { $sets[]="bank_account_id=:ba"; $par[':ba']=$bank_id; }
  elseif (col_exists($pdo,'purchase_payments','bank_id'))     { $sets[]="bank_id=:ba";       $par[':ba']=$bank_id; }

  // Mark cleared NOW (if there is a column) or status='cleared'
  $cleared_col = null;
  foreach (['cleared_on','clear_date','transferred_on'] as $c) { if (col_exists($pdo,'purchase_payments',$c)) { $cleared_col=$c; break; } }
  if ($cleared_col) { $sets[]="$cleared_col=:nowdt"; $par[':nowdt']=$nowdt; }
  elseif (col_exists($pdo,'purchase_payments','status')) { $sets[]="status='cleared'"; }

  if (!$sets) throw new RuntimeException('No updatable columns on purchase_payments.');

  $upd = "UPDATE purchase_payments SET ".implode(', ',$sets)." WHERE id=:id";
  $u = $pdo->prepare($upd); $u->execute($par);

  $pdo->commit();
  echo json_encode(['status'=>'success']);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
}
