<?php
include '../includes/session_fix.php';
require_roles(['Cashier']);
$role = $_SESSION['role'];
?>
<?php
$pageTitle = 'Manage Balance';
$activeNav = 'balance';
require_once __DIR__ . '/../includes/layout_app_start.php';
?>
            <nav class="breadcrumb" style="margin-bottom: 1rem; font-size: 0.85rem; color: var(--text-secondary);">
                <a href="../index.php" style="color: var(--primary); text-decoration: none;">Dashboard</a>
                <span style="margin: 0 0.5rem;">/</span>
                <span>Manage Balance</span>
            </nav>

            <h1 class="page-title"><i class="fas fa-scale-balanced"></i> Manage Balance</h1>
            <p style="color: var(--text-secondary); margin-bottom: 2rem;">Billing, payment tracking, and balance reporting for lodging.</p>

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1.5rem;">
                <?php if (in_array((string)$role, ['Dean', 'Rector', 'Admin'], true)) { ?>
                <a href="lodging_billing.php" class="card" style="padding: 2rem; margin: 0; text-decoration: none; text-align: center; transition: var(--transition);">
                    <span style="display: block; font-size: 2.5rem; margin-bottom: 1rem;"><i class="fas fa-file-invoice-dollar"></i></span>
                    <h3 style="color: #1e293b;">Billing</h3>
                    <p style="color: var(--text-secondary); font-size: 0.9rem;">Create invoices and view billing status</p>
                </a>
                <?php } ?>
                <a href="lodging_payments.php" class="card" style="padding: 2rem; margin: 0; text-decoration: none; text-align: center; transition: var(--transition);">
                    <span style="display: block; font-size: 2.5rem; margin-bottom: 1rem;"><i class="fas fa-money-bill-wave"></i></span>
                    <h3 style="color: #1e293b;">Payments</h3>
                    <p style="color: var(--text-secondary); font-size: 0.9rem;">Record payments and auto-allocate to invoices</p>
                </a>
                <a href="lodging_reports.php" class="card" style="padding: 2rem; margin: 0; text-decoration: none; text-align: center; transition: var(--transition);">
                    <span style="display: block; font-size: 2.5rem; margin-bottom: 1rem;"><i class="fas fa-chart-pie"></i></span>
                    <h3 style="color: #1e293b;">Reports</h3>
                    <p style="color: var(--text-secondary); font-size: 0.9rem;">View balances and print reports</p>
                </a>
            </div>
<?php require_once __DIR__ . '/../includes/layout_app_end.php'; ?>
<?php exit; ?>
