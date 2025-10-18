<?php
// Reports dashboard with category cards
require_once 'includes/header.php';

// Only admin and manager roles can access
checkRole(['admin','manager']);

// Define report categories. Each category has a label, target URL and a Bootstrap color class
$categories = [
    [
        'label' => 'Sales',
        'url'   => 'reports.php?report=sales',
        'color' => 'primary'
    ],
    [
        'label' => 'Items',
        // Link to stock summary report
        'url'   => 'reports.php?report=stock',
        'color' => 'success'
    ],
    [
        'label' => 'Suppliers',
        'url'   => 'suppliers.php',
        'color' => 'warning'
    ],
    [
        'label' => 'Purchases',
        'url'   => 'purchase_list.php',
        'color' => 'danger'
    ],
    [
        'label' => 'Transfers',
        'url'   => 'reports.php?report=transfers',
        'color' => 'info'
    ],
    [
        'label' => 'Audit',
        // Link to audit report page
        'url'   => 'audit_report.php',
        'color' => 'secondary'
    ],
    [
        'label' => 'Transaction Summary',
        'url'   => 'transaction_summary.php',
        'color' => 'dark'
    ],
    // Additional categories: Customers, Profit, Cash Book, Commission
    [
        'label' => 'Customers',
        'url'   => 'customer_report.php',
        'color' => 'primary'
    ],
    [
        'label' => 'Profit',
        'url'   => 'profit_report.php',
        'color' => 'success'
    ],
    [
        'label' => 'Commission',
        'url'   => 'commission.php',
        'color' => 'warning'
    ],
    [
        'label' => 'Balance Sheet',
        'url'   => 'balance_sheet.php',
        'color' => 'success'
    ],
    [
        'label' => 'Cash in Hand',
        'url'   => 'cash_in_hand.php',
        'color' => 'success'
    ],
    [
        'label' => 'Stock Report',
        'url'   => 'stock_report.php',
        'color' => 'success'
    ]
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports Dashboard - POS System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .report-card {
            border: 1px solid #dee2e6;
            border-radius: 6px;
            transition: transform .2s, box-shadow .2s;
            position: relative;
            padding: 1.5rem;
            text-align: center;
            background-color: #ffffff;
            cursor: pointer;
            min-height: 100px;
        }
        .report-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 0.5rem 1rem rgba(0,0,0,0.15);
        }
        .report-card .bar {
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 4px;
            border-radius: 6px 0 0 6px;
        }
        .report-card .label {
            font-size: 1.2rem;
            font-weight: 600;
        }
    </style>
</head>
<body>
<?php include 'includes/nav.php'; ?>
<div class="container">
    <h1 class="mb-4">Reports</h1>
    <div class="row g-4">
        <?php foreach ($categories as $cat): ?>
            <div class="col-12 col-sm-6 col-md-4 col-lg-3">
                <a href="<?php echo $cat['url']; ?>" class="text-decoration-none text-dark">
                    <div class="report-card">
                        <div class="bar bg-<?php echo $cat['color']; ?>"></div>
                        <div class="label"><?php echo htmlspecialchars($cat['label']); ?></div>
                    </div>
                </a>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>