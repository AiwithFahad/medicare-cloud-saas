<?php
/* Upgrade v2: role-specific sidebar for the separate panels.
 * The including page sets $panelSidebar to 'admin' | 'doctor' |
 * 'receptionist' | 'patient' and $base to '../'. Links are filtered by
 * the session permissions exactly like includes/sidebar.php, and point
 * into the panel's own directory (plus a "More modules" group linking
 * to the shared app/ pages that have no panel equivalent). */
$b = $base ?? '../';
$panel = (string)($panelSidebar ?? 'admin');
$cur = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
$plink = function (string $file, string $icon, string $label, string $perm) use ($b, $panel, $cur): string {
    if ($perm !== '' && !has_permission($perm)) {
        return '';
    }
    $active = $cur === $file ? ' active' : '';
    return '<a class="side-link' . $active . '" href="' . $b . $panel . '/' . $file . '"><i class="bi ' . $icon . '"></i><span>' . e($label) . '</span></a>';
};
$alink = function (string $file, string $icon, string $label, string $perm) use ($b, $cur): string {
    if ($perm !== '' && !has_permission($perm)) {
        return '';
    }
    return '<a class="side-link" href="' . $b . 'app/' . $file . '"><i class="bi ' . $icon . '"></i><span>' . e($label) . '</span></a>';
};
?>
<aside class="sidebar" id="appSidebar">
  <a class="brand" href="<?= $b ?>index.php">
    <span class="brand-mark"><i class="bi bi-heart-pulse-fill"></i></span>
    <span class="brand-text">MediCare <em>Cloud</em></span>
  </a>
  <?php if ($panel === 'admin'): ?>
    <p class="side-heading">Hospital Admin</p>
    <nav>
      <?= $plink('dashboard.php', 'bi-grid-1x2', 'Dashboard', 'dashboard.view') ?>
      <?= $plink('patients.php', 'bi-people', 'Patients', 'patients.view') ?>
      <?= $plink('doctors.php', 'bi-person-badge', 'Doctors', 'doctors.view') ?>
      <?= $plink('appointments.php', 'bi-calendar-check', 'Appointments', 'appointments.view') ?>
      <?= $plink('departments.php', 'bi-diagram-3', 'Departments', 'departments.view') ?>
      <?= $plink('staff.php', 'bi-person-gear', 'Staff', 'staff.view') ?>
    </nav>
    <p class="side-heading">Clinical</p>
    <nav>
      <?= $plink('wards.php', 'bi-hospital', 'Wards, Beds &amp; Admissions', 'wards.view') ?>
      <?= $plink('pharmacy.php', 'bi-capsule', 'Pharmacy &amp; Rx', 'pharmacy.view') ?>
      <?= $plink('laboratory.php', 'bi-droplet-half', 'Laboratory', 'lab.view') ?>
    </nav>
    <p class="side-heading">Finance &amp; Admin</p>
    <nav>
      <?= $plink('billing.php', 'bi-receipt', 'Billing &amp; Finance', 'billing.view') ?>
      <?= $plink('reports.php', 'bi-bar-chart', 'Reports', 'reports.view') ?>
      <?= $plink('settings.php', 'bi-gear', 'Settings', 'settings.view') ?>
    </nav>
    <p class="side-heading">More Modules</p>
    <nav>
      <?= $alink('records.php', 'bi-file-medical', 'Medical Records', 'records.view') ?>
      <?= $alink('subscription.php', 'bi-credit-card', 'Subscription', 'subscription.view') ?>
      <?= $alink('tickets.php', 'bi-life-preserver', 'Support Tickets', 'tickets.view') ?>
    </nav>
  <?php elseif ($panel === 'doctor'): ?>
    <p class="side-heading">Doctor</p>
    <nav>
      <?= $plink('dashboard.php', 'bi-grid-1x2', 'Dashboard', 'dashboard.view') ?>
      <?= $plink('appointments.php', 'bi-calendar-check', 'My Appointments', 'appointments.view') ?>
      <?= $plink('patients.php', 'bi-people', 'My Patients', 'patients.view') ?>
      <?= $plink('prescriptions.php', 'bi-prescription2', 'Prescriptions', 'records.view') ?>
      <?= $plink('schedule.php', 'bi-calendar-week', 'My Schedule', 'appointments.view') ?>
      <?= $plink('reports.php', 'bi-bar-chart', 'My Reports', 'reports.view') ?>
    </nav>
    <p class="side-heading">More Modules</p>
    <nav>
      <?= $alink('laboratory.php', 'bi-droplet-half', 'Laboratory', 'lab.view') ?>
      <?= $alink('tickets.php', 'bi-life-preserver', 'Support Tickets', 'tickets.view') ?>
    </nav>
  <?php elseif ($panel === 'receptionist'): ?>
    <p class="side-heading">Front Desk</p>
    <nav>
      <?= $plink('dashboard.php', 'bi-grid-1x2', 'Dashboard', 'dashboard.view') ?>
      <?= $plink('patients.php', 'bi-people', 'Patients', 'patients.view') ?>
      <?= $plink('appointments.php', 'bi-calendar-check', 'Appointments', 'appointments.view') ?>
      <?= $plink('billing.php', 'bi-receipt', 'Billing', 'billing.view') ?>
    </nav>
    <p class="side-heading">More Modules</p>
    <nav>
      <?= $alink('wards.php', 'bi-hospital', 'Wards &amp; Beds', 'wards.view') ?>
      <?= $alink('tickets.php', 'bi-life-preserver', 'Support Tickets', 'tickets.view') ?>
    </nav>
  <?php else: /* patient */ ?>
    <p class="side-heading">My Portal</p>
    <nav>
      <?= $plink('dashboard.php', 'bi-grid-1x2', 'Dashboard', 'portal.view') ?>
      <?= $plink('appointments.php', 'bi-calendar-check', 'My Appointments', 'portal.view') ?>
      <?= $plink('medical-records.php', 'bi-file-medical', 'Medical Records', 'portal.view') ?>
      <?= $plink('prescriptions.php', 'bi-prescription2', 'Prescriptions', 'portal.view') ?>
      <?= $plink('invoices.php', 'bi-receipt', 'My Invoices', 'portal.view') ?>
      <?= $plink('profile.php', 'bi-person-circle', 'My Profile', 'portal.view') ?>
    </nav>
  <?php endif; ?>
  <div class="side-footer">
    <a class="side-link" href="<?= $b ?>auth/logout.php"><i class="bi bi-box-arrow-right"></i><span>Logout</span></a>
  </div>
</aside>
