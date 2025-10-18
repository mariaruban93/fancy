<?php
// Return the outstanding due and credit limit for a given customer as JSON.
// This endpoint is used by the POS page to display whether the customer's
// outstanding balance exceeds their due limit.

require_once 'includes/header.php';

// Only logged in users with any role can access
if (!isset($user) || !$user) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Validate provided customer ID as a positive integer
$cid = isset($_GET['customer_id']) && ctype_digit($_GET['customer_id']) ? (int)$_GET['customer_id'] : 0;
if ($cid <= 0) {
    echo json_encode(['due' => 0, 'due_limit' => null]);
    exit;
}

// Determine the current user's branch.  All dues and limits are computed per branch to ensure
// separation of customer accounts across branches.  Use 0 if user has no branch.
$currentBranchId = isset($user['branch_id']) ? (int)$user['branch_id'] : 0;

// Check if the customers table has a branch_id column.  If present, ensure the requested
// customer belongs to the current branch.  If not, return zero due and null limit.
try {
    $custBranchCheck = $pdo->prepare("SHOW COLUMNS FROM customers LIKE 'branch_id'");
    $custBranchCheck->execute();
    $hasCustBranch = (bool)$custBranchCheck->fetch();
    if ($hasCustBranch) {
        $bCheck = $pdo->prepare("SELECT branch_id FROM customers WHERE id = ?");
        $bCheck->execute([$cid]);
        $branchRow = $bCheck->fetch(PDO::FETCH_ASSOC);
        if (!$branchRow || (int)$branchRow['branch_id'] !== $currentBranchId) {
            // The customer is not in the current branch; return zero due
            echo json_encode(['due' => 0, 'due_limit' => null]);
            exit;
        }
    }
} catch (Throwable $e) {
    // On error, continue with global fallback
}

// Initialize due and limit
$due = 0;
$limit = null;

try {
    // Fetch due limit from customers table
    $stmt = $pdo->prepare("SELECT due_limit FROM customers WHERE id = ?");
    $stmt->execute([$cid]);
    $limitRow = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($limitRow) {
        $limit = $limitRow['due_limit'] !== null ? (float)$limitRow['due_limit'] : null;
    }
    // Compute outstanding due: sum of total_amount minus payments for this customer.
    // Limit the sales to the current branch if the sales table has branch_id column.
    $salesBranchCheck = $pdo->prepare("SHOW COLUMNS FROM sales LIKE 'branch_id'");
    $salesBranchCheck->execute();
    $salesHasBranch = (bool)$salesBranchCheck->fetch();
    if ($salesHasBranch) {
        $sql = "SELECT COALESCE(SUM(s.total_amount),0) AS billed, COALESCE(SUM(p.amount),0) AS paid
                FROM sales s
                LEFT JOIN sale_payments p ON s.id = p.sale_id
                WHERE s.customer_id = ? AND s.branch_id = ?";
        $stmt2 = $pdo->prepare($sql);
        $stmt2->execute([$cid, $currentBranchId]);
    } else {
        $sql = "SELECT COALESCE(SUM(s.total_amount),0) AS billed, COALESCE(SUM(p.amount),0) AS paid
                FROM sales s
                LEFT JOIN sale_payments p ON s.id = p.sale_id
                WHERE s.customer_id = ?";
        $stmt2 = $pdo->prepare($sql);
        $stmt2->execute([$cid]);
    }
    $row = $stmt2->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $billed = (float)$row['billed'];
        $paid   = (float)$row['paid'];
        if ($paid >= $billed) {
            $due = 0;
        } else {
            $due = $billed - $paid;
        }
    }
} catch (Throwable $e) {
    // On error, leave due as 0
    $due = 0;
}

header('Content-Type: application/json');
echo json_encode(['due' => $due, 'due_limit' => $limit]);
?>