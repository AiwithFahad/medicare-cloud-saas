<?php
declare(strict_types=1);
/* Patient portal — dashboard (Upgrade v2 page tree).
 * Everything here is scoped to the patient profile linked to this login
 * (patients.user_id): own upcoming visits, outstanding balance, latest
 * prescription and the records doctors chose to share. */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/panel.php';
$pdo = db();
$user = require_role(['patient']);
$tid = require_tenant();
check_tenant_active($pdo);
$base = '../';
$panelSidebar = 'patient';
$patient = panel_patient($pdo, $tid, (int)$user['id']);

$pageTitle = 'My Dashboard';
include __DIR__ . '/../includes/header.php';
if (!$patient) {
    ?>
    <div class="card"><div class="empty-state py-5">
      <i class="bi bi-person-heart"></i>
      <p class="mb-2"><strong>Your login is not linked to a patient profile yet.</strong></p>
      <p class="text-muted mb-0">Please ask the hospital front desk to link this account to your patient record (<?= e($user['email'] ?? '') ?>). Once linked, your appointments, prescriptions, records and bills will appear here.</p>
    </div></div>
    <?php include __DIR__ . '/../includes/footer.php';
    return;
}
$pid = (int)$patient['id'];

$next = $pdo->prepare(
    "SELECT a.*, d.name AS doctor_name, d.specialization FROM appointments a JOIN doctors d ON d.id = a.doctor_id
      WHERE a.tenant_id = ? AND a.patient_id = ? AND a.appointment_date >= CURDATE() AND a.status IN ('scheduled','confirmed')
      ORDER BY a.appointment_date, a.appointment_time LIMIT 1"
);
$next->execute([$tid, $pid]);
$next = $next->fetch() ?: null;

$bal = $pdo->prepare("SELECT COALESCE(SUM(total - amount_paid),0) FROM patient_invoices WHERE tenant_id = ? AND patient_id = ? AND status IN ('unpaid','partial')");
$bal->execute([$tid, $pid]);
$balance = (float)$bal->fetchColumn();

$sharedCount = $pdo->prepare("SELECT COUNT(*) FROM medical_records WHERE tenant_id = ? AND patient_id = ? AND visibility = 'patient'");
$sharedCount->execute([$tid, $pid]);
$sharedCount = (int)$sharedCount->fetchColumn();

$latestRx = $pdo->prepare('SELECT pr.*, d.name AS doctor_name FROM prescriptions pr JOIN doctors d ON d.id = pr.doctor_id WHERE pr.tenant_id = ? AND pr.patient_id = ? ORDER BY pr.id DESC LIMIT 1');
$latestRx->execute([$tid, $pid]);
$latestRx = $latestRx->fetch() ?: null;

$upcoming = $pdo->prepare(
    "SELECT a.*, d.name AS doctor_name FROM appointments a JOIN doctors d ON d.id = a.doctor_id
      WHERE a.tenant_id = ? AND a.patient_id = ? AND a.appointment_date >= CURDATE()
      ORDER BY a.appointment_date, a.appointment_time LIMIT 5"
);
$upcoming->execute([$tid, $pid]);
$upcoming = $upcoming->fetchAll();
?>
<div class="card mb-3">
  <div class="card-body d-flex flex-wrap align-items-center gap-3">
    <span class="avatar-initials" style="width:56px;height:56px;font-size:1.2rem"><?= e(initials($patient['name'])) ?></span>
    <span>
      <strong class="d-block fs-5">Welcome, <?= e($patient['name']) ?></strong>
      <small class="text-muted">Patient ID <?= e($patient['patient_code']) ?> · Blood group <?= e($patient['blood_group'] ?? '—') ?><?= $patient['allergies'] ? ' · Allergies: ' . e($patient['allergies']) : '' ?></small>
    </span>
    <span class="ms-auto d-flex gap-2">
      <a class="btn btn-primary" href="appointments.php"><i class="bi bi-calendar-plus"></i> Book a visit</a>
      <a class="btn btn-outline-primary" href="medical-records.php"><i class="bi bi-file-medical"></i> My records</a>
    </span>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-md-4">
    <div class="card h-100">
      <div class="card-header"><i class="bi bi-calendar-event me-1"></i>Next appointment</div>
      <div class="card-body">
        <?php if ($next): ?>
          <strong class="d-block"><?= e($next['doctor_name']) ?></strong>
          <span class="text-muted d-block"><?= e($next['specialization'] ?? '') ?></span>
          <span class="d-block mt-2"><i class="bi bi-clock me-1"></i><?= fmt_date($next['appointment_date']) ?> · <?= e(date('h:i A', strtotime((string)$next['appointment_time']))) ?></span>
          <span class="d-block"><?= status_badge((string)$next['status']) ?> <span class="chip ms-1"><?= e($next['type']) ?></span></span>
        <?php else: ?>
          <p class="text-muted mb-2">You have no upcoming appointments.</p>
          <a class="btn btn-sm btn-primary" href="appointments.php">Book a visit</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="card h-100">
      <div class="card-header"><i class="bi bi-prescription2 me-1"></i>Latest prescription</div>
      <div class="card-body">
        <?php if ($latestRx): ?>
          <strong class="d-block"><?= e($latestRx['doctor_name']) ?></strong>
          <span class="text-muted d-block"><?= fmt_date($latestRx['prescription_date']) ?> · <?= e($latestRx['diagnosis'] ?? 'General') ?></span>
          <span class="d-block mt-2"><?= status_badge((string)$latestRx['status']) ?></span>
          <a class="btn btn-sm btn-outline-primary mt-2" href="prescriptions.php">View my prescriptions</a>
        <?php else: ?>
          <p class="text-muted mb-0">No prescriptions yet.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="card h-100">
      <div class="card-header"><i class="bi bi-wallet2 me-1"></i>My balance &amp; records</div>
      <div class="card-body">
        <span class="d-block">Outstanding balance: <strong><?= money($balance) ?></strong></span>
        <span class="d-block mt-1">Medical records shared with you: <strong><?= $sharedCount ?></strong></span>
        <a class="btn btn-sm btn-outline-primary mt-2" href="invoices.php">View my invoices</a>
      </div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header">Upcoming appointments</div>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead><tr><th>When</th><th>Doctor</th><th>Type</th><th>Reason</th><th>Status</th></tr></thead>
      <tbody>
      <?php if (!$upcoming): ?><tr><td colspan="5" class="text-muted text-center py-4">No upcoming appointments.</td></tr><?php endif; ?>
      <?php foreach ($upcoming as $a): ?>
        <tr><td><?= fmt_date($a['appointment_date']) ?> · <?= e(date('h:i A', strtotime((string)$a['appointment_time']))) ?></td><td><?= e($a['doctor_name']) ?></td><td><?= e($a['type']) ?></td><td><?= e($a['reason'] ?? '—') ?></td><td><?= status_badge((string)$a['status']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
