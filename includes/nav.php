<?php
// Navigation bar for the POS system
// Assumes $user is available from header.php
//
// This navigation bar has been enhanced to include Bootstrap Icons on
// each menu entry and provide a more structured layout for reports
// and transactions.  A link to the Bootstrap Icons stylesheet is
// injected the first time the nav is loaded to ensure the icons are
// available on every page that includes this file.
?>
<!-- Include Bootstrap Icons once -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
<nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4">
    <div class="container-fluid">
        <img src="./logo.png" alt="Logo" width="40" class="me-2">
        <a class="navbar-brand" href="dashboard.php">POS SYSTEM</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <!-- Always show dashboard link -->
                <li class="nav-item">
                    <a class="nav-link" href="dashboard.php">
                        <i class="bi bi-speedometer2 me-1"></i>Dashboard
                    </a>
                </li>

                <?php
                // Determine current script for context-specific links
                $currentScript = basename($_SERVER['SCRIPT_NAME'] ?? '');
                ?>

                <!-- Masters dropdown: products, categories, units, brands, customers, workers, branches -->
                <?php if ($user && in_array($user['role_name'], ['admin','manager','inventory_officer'])): ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" id="mastersDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-box-seam me-1"></i>Masters
                    </a>
                    <ul class="dropdown-menu" aria-labelledby="mastersDropdown">
                        <li><a class="dropdown-item" href="products.php"><i class="bi bi-cube me-1"></i>Products</a></li>
                        <li><a class="dropdown-item" href="categories.php"><i class="bi bi-tags me-1"></i>Categories</a></li>
                        <li><a class="dropdown-item" href="units.php"><i class="bi bi-rulers me-1"></i>Units</a></li>
                        <li><a class="dropdown-item" href="brands.php"><i class="bi bi-gem me-1"></i>Brands</a></li>
                        <li><a class="dropdown-item" href="customers.php"><i class="bi bi-people me-1"></i>Customers</a></li>
                        <li><a class="dropdown-item" href="workers.php"><i class="bi bi-person-badge me-1"></i>Workers</a></li>
                        <li><a class="dropdown-item" href="suppliers.php"><i class="bi bi-truck me-1"></i>Suppliers</a></li>
                        <li><a class="dropdown-item" href="cargo_providers.php"><i class="bi bi-truck me-1"></i>Provider</a></li>
                        <?php if ($user['role_name'] === 'admin'): ?>
                            <li><a class="dropdown-item" href="branches.php"><i class="bi bi-building me-1"></i>Branches</a></li>
                            <li><a class="dropdown-item" href="bank_accounts.php"><i class="bi bi-bank me-1"></i>Bank Accounts</a></li>
                            <!-- <li><a class="dropdown-item" href="wallets.php"><i class="bi bi-wallet2 me-1"></i>Wallets</a></li> -->
                        <?php endif; ?>
                    </ul>
                </li>
                <?php endif; ?>

                <!-- Transactions dropdown: sales, purchases, transfers, expenses, inventory -->
                <?php if ($user && in_array($user['role_name'], ['admin','manager','cashier','inventory_officer'])): ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" id="transactionsDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-cash-stack me-1"></i>Transactions
                    </a>
                    <ul class="dropdown-menu" aria-labelledby="transactionsDropdown">
                        <?php if ($user && in_array($user['role_name'], ['admin','manager','cashier'])): ?>
                            <li><a class="dropdown-item" href="pos.php"><i class="bi bi-cash-register me-1"></i>POS</a></li>
                            <li><a class="dropdown-item" href="sales_list.php"><i class="bi bi-receipt me-1"></i>Sales</a></li>
                            <li><a class="dropdown-item" href="sales_return.php"><i class="bi bi-arrow-return-left me-1"></i>Returns</a></li>
                        <?php endif; ?>
                        <?php if ($user && in_array($user['role_name'], ['admin','manager','inventory_officer'])): ?>
                            <li><a class="dropdown-item" href="purchase.php"><i class="bi bi-box-arrow-in-down me-1"></i>Purchase Stock</a></li>
                            <li><a class="dropdown-item" href="purchase_list.php"><i class="bi bi-journal-bookmark me-1"></i>Purchases</a></li>
                            <li><a class="dropdown-item" href="purchase_return.php"><i class="bi bi-arrow-return-left me-1"></i>Purchase Return</a></li>
                        <?php endif; ?>
                        <?php if ($user && in_array($user['role_name'], ['admin','manager'])): ?>
                            <li><a class="dropdown-item" href="transfer.php"><i class="bi bi-arrow-left-right me-1"></i>Transfer Stock</a></li>
                            <li><a class="dropdown-item" href="transfer_report.php"><i class="bi bi-currency-exchange me-1"></i>Transfer Payments</a></li>
                            <li><a class="dropdown-item" href="expenses.php"><i class="bi bi-credit-card me-1"></i>Expenses</a></li>
                        <?php endif; ?>
                        <?php if ($user && $user['role_name'] === 'admin'): ?>
                            <li><a class="dropdown-item" href="batch_inventory.php"><i class="bi bi-archive me-1"></i>Inventory</a></li>
                        <?php endif; ?>
                    </ul>
                </li>
                <?php endif; ?>

                <!-- Barcode Print tool accessible to admin, manager and inventory officer -->
                <?php if ($user && in_array($user['role_name'], ['admin','manager','inventory_officer'])): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="barcode_print.php"><i class="bi bi-upc me-1"></i>Print Barcode</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="write_off.php"><i class="bi bi-x-circle me-1"></i>Write Off Items</a>
                    </li>
                <?php endif; ?>

                <!-- Reports dropdown + Activity Logs for admin and manager -->
                <?php if ($user && in_array($user['role_name'], ['admin','manager'])): ?>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="reportsDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-bar-chart-line me-1"></i>Reports
                        </a>
                        <ul class="dropdown-menu" aria-labelledby="reportsDropdown">
                            <li><a class="dropdown-item" href="sales_list.php"><i class="bi bi-receipt me-1"></i>Sales Report</a></li>
                            <li><a class="dropdown-item" href="transaction_summary.php"><i class="bi bi-list-nested me-1"></i>Transaction Summary</a></li>
                            <li><a class="dropdown-item" href="audit_report.php"><i class="bi bi-shield-check me-1"></i>Audit Report</a></li>
                            <li><a class="dropdown-item" href="stock_report.php"><i class="bi bi-clipboard-data me-1"></i>Stock Report</a></li>
                            <li><a class="dropdown-item" href="profit_report.php"><i class="bi bi-graph-up-arrow me-1"></i>Profit Report</a></li>
                            <li><a class="dropdown-item" href="commission.php"><i class="bi bi-percent me-1"></i>Commission</a></li>
                             <li><a class="dropdown-item" href="card_commissions.php"><i class="bi bi-percent me-1"></i>Card Commission</a></li>
                            <li><a class="dropdown-item" href="cash_in_hand.php"><i class="bi bi-wallet me-1"></i>Cash In Hand</a></li>
                            <li><a class="dropdown-item" href="cheques_in_hand.php"><i class="bi bi-journal-check me-1"></i>Cheques In Hand</a></li>
                             <li><a class="dropdown-item" href="bank_report.php"><i class="bi bi-bank me-1"></i>Cash In Bank</a></li>
                            <li><a class="dropdown-item" href="purchase_cheques.php"><i class="bi bi-journal-check me-1"></i>Supplier Cheques</a></li>
                            <li><a class="dropdown-item" href="financial_statement.php"><i class="bi bi-file-earmark-bar-graph me-1"></i>Financial Statement</a></li>
                            <li><a class="dropdown-item" href="customer_report.php"><i class="bi bi-person-lines-fill me-1"></i>Customer Report</a></li>
                            <li><a class="dropdown-item" href="supplier_report.php"><i class="bi bi-truck me-1"></i>Supplier Report</a></li>
                            <li><a class="dropdown-item" href="cargo_service.php"><i class="bi bi-truck me-1"></i> <span>Cargo Services</span></a></li>
                            <li><a class="dropdown-item" href="sales_return.php"><i class="bi bi-arrow-return-left me-1"></i>Return List</a></li>
                            <li><a class="dropdown-item" href="transfer_report.php"><i class="bi bi-box-arrow-right me-1"></i>Transfer Report</a></li>
                            <li><a class="dropdown-item" href="journal_entries.php"><i class="bi bi-journal-plus me-1"></i>Journal Entries</a></li>
                        </ul>
                    </li>
                <?php endif; ?>

                <!-- Statements for admin and manager -->
                <?php if ($user && in_array($user['role_name'], ['admin','manager'])): ?>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="statementDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-receipt"></i>Statement
                        </a>
                        <ul class="dropdown-menu" aria-labelledby="statementDropdown">
                            
                             <li><a class="dropdown-item" href="cash_statement.php"><i class="bi bi-wallet me-1"></i>Cash Statement</a></li>
                            <li><a class="dropdown-item" href="bank_report.php"><i class="bi bi-bank me-1"></i>Bank Statement</a></li>
                            <li><a class="dropdown-item" href="customer_statement.php"><i class="bi bi-receipt me-1"></i>Customer Statement</a></li>
                            <li><a class="dropdown-item" href="supplier_statement.php"><i class="bi bi-receipt me-1"></i>Supplier Statement</a></li>
                            <li><a class="dropdown-item" href="branch_transfer_statement.php"><i class="bi bi-house me-1"></i>Branch Transfer Statement</a></li>
                            <!-- Added cargo provider statement link -->
                            <li><a class="dropdown-item" href="cargo_statement.php"><i class="bi bi-truck me-1"></i>Cargo Provider Statement</a></li>
                        </ul>
                    </li>
                <?php endif; ?>

                <!-- Users management only for admin -->
                <?php if ($user && $user['role_name'] === 'admin'): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="users.php"><i class="bi bi-people-fill me-1"></i>Users</a>
                    </li>
                <?php endif; ?>

                <!-- POS page specific actions: Held Sales and Refresh as separate nav items -->
                <?php if ($currentScript === 'pos.php'): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="#" id="retrieveHeldBtn"><i class="bi bi-bookmark-plus-fill me-1"></i>Held Sales</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="javascript:location.reload()"><i class="bi bi-arrow-clockwise me-1"></i>Refresh</a>
                    </li>
                <?php endif; ?>
            </ul>
            <!-- User profile dropdown on the right -->
            <?php if ($user): ?>
            <ul class="navbar-nav ms-auto">
              <li class="nav-item dropdown">
                <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" id="profileDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                  <i class="bi bi-person-circle me-1"></i>
                  <?php echo htmlspecialchars($user['username']); ?>
                </a>
                <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="profileDropdown">
                  <li class="dropdown-item-text"><strong><?php echo htmlspecialchars($user['username']); ?></strong></li>
                  <li class="dropdown-item-text">Role: <?php echo htmlspecialchars($user['role_name']); ?></li>
                  <?php if ($user['branch_name']): ?>
                    <li class="dropdown-item-text">Branch: <?php echo htmlspecialchars($user['branch_name']); ?></li>
                  <?php endif; ?>
                  <li><hr class="dropdown-divider"></li>
                  <?php if (in_array($user['role_name'], ['admin','manager'])): ?>
                    <li><a class="dropdown-item" href="activity_log.php"><i class="bi bi-clock-history me-1"></i>Activity Logs</a></li>
                    <li><a class="dropdown-item" href="stock_adjustment.php"><i class="bi bi-terminal-plus me-1"></i>Stock Adjustment</a></li>
                    <li><a class="dropdown-item" href=".php"><i class="bi bi-gear me-1"></i>Setting</a></li>
                  <?php endif; ?>
                <?php if ($user['role_name'] === 'admin'): ?>
                    <li><a class="dropdown-item text-danger" href="admin_tools.php"><i class="bi bi-tools me-1"></i>Admin Tools</a></li>
                    <li><a class="dropdown-item text-danger" href="admin_activity_reverse.php"><i class="bi bi-backspace-reverse"></i>Activity Reverse</a></li>
                <?php endif; ?>
                  <li><a class="dropdown-item" href="logout.php"><i class="bi bi-box-arrow-right me-1"></i>Logout</a></li>
                </ul>
              </li>
            </ul>
            <?php endif; ?>
        </div>
    </div>
</nav>