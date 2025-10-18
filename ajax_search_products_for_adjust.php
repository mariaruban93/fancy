<?php
require_once 'includes/header.php';
checkRole(['admin','manager']); // same access as page

header('Content-Type: application/json');

$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$branch_id = isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : 0;

if ($branch_id <= 0) { echo json_encode([]); exit; }
// Managers cannot search other branches
if ($user['role_name'] === 'manager' && (int)$user['branch_id'] !== $branch_id) { echo json_encode([]); exit; }
if ($q === '') { echo json_encode([]); exit; }

// ---- helpers to tolerate schema diffs ----
function col_exists(PDO $pdo, string $t, string $c): bool {
  try {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $st->execute([$t,$c]);
    return (int)$st->fetchColumn() > 0;
  } catch (Throwable $e) { return false; }
}

// Optional columns
$has_p_size   = col_exists($pdo,'products','size');
$has_p_bar    = col_exists($pdo,'products','barcode');
$has_cat_fk   = col_exists($pdo,'products','category_id');
$has_brand_fk = col_exists($pdo,'products','brand_id');

$sel_bar  = $has_p_bar  ? "COALESCE(p.barcode,'')" : "''";
$sel_size = $has_p_size ? "p.size"                 : "NULL";
$sel_cat  = $has_cat_fk ? "c.name"                 : "NULL";
$sel_br   = $has_brand_fk ? "b.name"               : "NULL";

$cat_join = $has_cat_fk ? "LEFT JOIN categories c ON c.id = p.category_id" : "";
$br_join  = $has_brand_fk ? "LEFT JOIN brands b ON b.id = p.brand_id" : "";

// Sell price (optional)
$has_sb_sell  = col_exists($pdo,'stock_batches','selling_price');
$has_p_sell   = col_exists($pdo,'products','selling_price');
$has_p_price  = col_exists($pdo,'products','price');

if ($has_sb_sell)      $sell_expr = "sb.selling_price";
elseif ($has_p_sell)   $sell_expr = "p.selling_price";
elseif ($has_p_price)  $sell_expr = "p.price";
else                   $sell_expr = "0.00";

// Build query terms
$qstart = $q.'%';
$qany   = '%'.$q.'%';

// Prefer prefix on name, also match barcode exactly or by prefix if present
$where = "sb.branch_id = :bid AND (p.name LIKE :qstart OR p.name LIKE :qany";
if ($has_p_bar) { $where .= " OR p.barcode = :qexact OR p.barcode LIKE :qstart"; }
$where .= ")";

$sql = "
  SELECT
    p.id AS product_id, p.name AS product_name,
    {$sel_bar}   AS barcode,
    {$sel_size}  AS product_size,
    {$sel_cat}   AS cat_name,
    {$sel_br}    AS brand_name,
    sb.id AS batch_id, COALESCE(sb.batch_no,'') AS batch_no, sb.expiry_date,
    sb.quantity, sb.cost_price, {$sell_expr} AS selling_price
  FROM stock_batches sb
  JOIN products p ON p.id = sb.product_id
  {$cat_join}
  {$br_join}
  WHERE {$where}
  ORDER BY p.name, sb.expiry_date, sb.id
  LIMIT 120
";

$st = $pdo->prepare($sql);
$st->bindValue(':bid', $branch_id, PDO::PARAM_INT);
$st->bindValue(':qstart', $qstart, PDO::PARAM_STR);
$st->bindValue(':qany', $qany, PDO::PARAM_STR);
if ($has_p_bar) $st->bindValue(':qexact', $q, PDO::PARAM_STR);

$st->execute();
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

// Group rows by product
$out = [];
$idx = [];
foreach ($rows as $r) {
  $pid = (int)$r['product_id'];
  if (!isset($idx[$pid])) {
    $idx[$pid] = count($out);
    $out[] = [
      'id'       => $pid,
      'name'     => $r['product_name'],
      'barcode'  => $r['barcode'] ?? '',
      'size'     => $r['product_size'],
      'category' => $r['cat_name'],
      'brand'    => $r['brand_name'],
      'batches'  => []
    ];
  }
  $out[$idx[$pid]]['batches'][] = [
    'id'       => (int)$r['batch_id'],
    'batch_no' => $r['batch_no'] ?: 'N/A',
    'expiry'   => $r['expiry_date'] ?: null,
    'qty'      => (float)$r['quantity'],
    'cost'     => (float)$r['cost_price'],
    'price'    => isset($r['selling_price']) ? (float)$r['selling_price'] : 0.00
  ];
}

echo json_encode($out, JSON_UNESCAPED_UNICODE);
