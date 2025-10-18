<?php
require_once 'includes/header.php';
checkRole(['admin','manager']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header('Location: cargo_service.php');
  exit;
}

$branchId = (int)($user['branch_id'] ?? 0);

$supplier_id = (int)($_POST['supplier_id'] ?? 0);
$provider_id = (int)($_POST['provider_id'] ?? 0);
$reference_no = trim($_POST['reference_no'] ?? '');
$amount = (float)($_POST['amount'] ?? 0);
$description = trim($_POST['description'] ?? '');

if ($branchId <= 0 || $supplier_id <= 0 || $provider_id <= 0 || $amount <= 0) {
  $_SESSION['flash'] = ['type'=>'danger','msg'=>'Please provide supplier, provider and a valid amount.'];
  header('Location: cargo_service.php');
  exit;
}

try {
  $stmt = $pdo->prepare("
    INSERT INTO cargo_services
      (branch_id, supplier_id, cargo_provider_id, reference_no, amount, description, transfer_date)
    VALUES
      (:b, :s, :p, :r, :a, :d, NOW())
  ");
  $stmt->execute([
    ':b'=>$branchId,
    ':s'=>$supplier_id,
    ':p'=>$provider_id,
    ':r'=>$reference_no,
    ':a'=>$amount,
    ':d'=>$description
  ]);
  $_SESSION['flash'] = ['type'=>'success','msg'=>'Cargo transfer recorded successfully.'];
} catch (Throwable $e) {
  $_SESSION['flash'] = ['type'=>'danger','msg'=>'Error saving transfer: '.$e->getMessage()];
}

header('Location: cargo_service.php');
exit;
