<?php
declare(strict_types=1);
/* Platform (super admin) sidebar — dark/blue branded. Included by sa-header.php.
 * Active state is derived from the current script basename. */
$saCur = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
$saLink = static function (string $file, string $icon, string $label) use ($saCur): string {
    $active = $saCur === $file ? ' active' : '';
    return '<a class="side-link' . $active . '" href="' . $file . '"><i class="bi ' . $icon . '"></i><span>' . e($label) . '</span></a>';
};
?>
<aside class="sidebar sa-sidebar" id="appSidebar">
  <a class="brand" href="index.php">
    <span class="brand-mark"><i class="bi bi-heart-pulse-fill"></i></span>
    <span class="brand-text">MediCare <em>Cloud</em><span class="d-block" style="font-size:.68rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;opacity:.75">— Platform</span></span>
  </a>
  <p class="side-heading">Platform</p>
  <nav>
    <?= $saLink('index.php', 'bi-grid-1x2', 'Dashboard') ?>
    <?= $saLink('tenants.php', 'bi-buildings', 'Tenants') ?>
    <?= $saLink('users.php', 'bi-people', 'Users') ?>
  </nav>
  <p class="side-heading">Billing</p>
  <nav>
    <?= $saLink('plans.php', 'bi-box-seam', 'Plans') ?>
    <?= $saLink('subscriptions.php', 'bi-arrow-repeat', 'Subscriptions') ?>
    <?= $saLink('payments.php', 'bi-cash-stack', 'Payments') ?>
    <?= $saLink('invoices.php', 'bi-receipt', 'Invoices') ?>
    <?= $saLink('coupons.php', 'bi-ticket-percent', 'Coupons') ?>
  </nav>
  <p class="side-heading">Support &amp; System</p>
  <nav>
    <?= $saLink('tickets.php', 'bi-life-preserver', 'Support Tickets') ?>
    <?= $saLink('announcements.php', 'bi-megaphone', 'Announcements') ?>
    <?= $saLink('settings.php', 'bi-gear', 'Platform Settings') ?>
    <?= $saLink('logs.php', 'bi-clock-history', 'Logs') ?>
  </nav>
  <div class="side-footer">
    <a class="side-link" href="../index.php"><i class="bi bi-globe"></i><span>View Site</span></a>
    <a class="side-link" href="../auth/logout.php"><i class="bi bi-box-arrow-right"></i><span>Logout</span></a>
  </div>
</aside>
