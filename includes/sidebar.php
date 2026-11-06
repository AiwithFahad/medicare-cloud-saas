<?php
/* Role-aware sidebar for the hospital application. Links are filtered by the
 * permissions loaded into the session at login, so each role only sees the
 * modules it may open. $base ('../' from app/) is set by the including page. */
$b = $base ?? '';
$role = (string)(current_user()['role'] ?? '');
$cur = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
$link = function (string $file, string $icon, string $label, string $perm) use ($b, $cur): string {
    if (!has_permission($perm)) return '';
    $active = $cur === $file ? ' active' : '';
    return '<a class="side-link' . $active . '" href="' . $b . 'app/' . $file . '"><i class="bi ' . $icon . '"></i><span>' . e($label) . '</span></a>';
};
?>
<aside class="sidebar" id="appSidebar">
  <a class="brand" href="<?= $b ?>index.php">
    <span class="brand-mark"><i class="bi bi-heart-pulse-fill"></i></span>
    <span class="brand-text">MediCare <em>Cloud</em></span>
  </a>
  <?php if ($role === 'patient'): ?>
    <p class="side-heading">My Portal</p>
    <nav>
      <a class="side-link<?= $cur === 'portal.php' ? ' active' : '' ?>" href="<?= $b ?>app/portal.php"><i class="bi bi-grid-1x2"></i><span>Overview</span></a>
      <a class="side-link" href="<?= $b ?>app/portal.php#appointments"><i class="bi bi-calendar-check"></i><span>My Appointments</span></a>
      <a class="side-link" href="<?= $b ?>app/portal.php#bills"><i class="bi bi-receipt"></i><span>My Bills</span></a>
      <a class="side-link" href="<?= $b ?>app/portal.php#records"><i class="bi bi-file-medical"></i><span>My Records</span></a>
    </nav>
  <?php else: ?>
    <p class="side-heading">Hospital</p>
    <nav>
      <?= $link('dashboard.php', 'bi-grid-1x2', 'Overview', 'dashboard.view') ?>
      <?= $link('patients.php', 'bi-people', 'Patients', 'patients.view') ?>
      <?= $link('doctors.php', 'bi-person-badge', 'Doctors', 'doctors.view') ?>
      <?= $link('appointments.php', 'bi-calendar-check', 'Appointments', 'appointments.view') ?>
      <?= $link('departments.php', 'bi-diagram-3', 'Departments', 'departments.view') ?>
      <?= $link('staff.php', 'bi-person-gear', 'Nurses &amp; Staff', 'staff.view') ?>
    </nav>
    <p class="side-heading">Clinical</p>
    <nav>
      <?= $link('wards.php', 'bi-hospital', 'Wards &amp; Beds', 'wards.view') ?>
      <?= $link('admissions.php', 'bi-door-open', 'Admissions', 'admissions.view') ?>
      <?= $link('pharmacy.php', 'bi-capsule', 'Pharmacy &amp; Rx', 'pharmacy.view') ?>
      <?= $link('laboratory.php', 'bi-droplet-half', 'Laboratory', 'lab.view') ?>
      <?= $link('records.php', 'bi-file-medical', 'Medical Records', 'records.view') ?>
    </nav>
    <p class="side-heading">Finance &amp; Admin</p>
    <nav>
      <?= $link('billing.php', 'bi-receipt', 'Billing &amp; Invoices', 'billing.view') ?>
      <?= $link('payments.php', 'bi-cash-stack', 'Payments', 'payments.view') ?>
      <?= $link('reports.php', 'bi-bar-chart', 'Reports', 'reports.view') ?>
      <?= $link('subscription.php', 'bi-credit-card', 'Subscription', 'subscription.view') ?>
      <?= $link('tickets.php', 'bi-life-preserver', 'Support Tickets', 'tickets.view') ?>
      <?= $link('settings.php', 'bi-gear', 'Settings', 'settings.view') ?>
    </nav>
  <?php endif; ?>
  <div class="side-footer">
    <a class="side-link" href="<?= $b ?>auth/logout.php"><i class="bi bi-box-arrow-right"></i><span>Logout</span></a>
  </div>
</aside>
