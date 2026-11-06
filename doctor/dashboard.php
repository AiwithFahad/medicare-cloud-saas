<?php
declare(strict_types=1);
/* Doctor dashboard (Upgrade v2 page tree) — scoped to the signed-in
 * doctor's own practice: today's clinic, upcoming visits, own-patient
 * counts and a personal appointments chart (api/dashboard-stats.php
 * with doctor_id). Every number is a live query. */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/panel.php';
$pdo = db();
$user = require_role(['doctor']);
$tid = require_tenant();
check_tenant_active($pdo);
$doctor = panel_doctor($pdo, $tid, (int)$user['id']);
if (!$doctor) {
    flash('error', 'Your login is not linked to a doctor profile yet. Ask your hospital admin to link it from the Doctors page.');
    redirect('../app/dashboard.php');
}
$did = (int)$doctor['id'];
$base = '../';
$panelSidebar = 'doctor';

$cnt = static function (string $sql, array $params = []) use ($pdo): int {
    $s = $pdo->prepare($sql);
    $s->execute($params);
    return (int)$s->fetchColumn();
};
$todayCount = $cnt('SELECT COUNT(*) FROM appointments WHERE tenant_id = ? AND doctor_id = ? AND appointment_date = CURDATE()', [$tid, $did]);
$todayPending = $cnt("SELECT COUNT(*) FROM appointments WHERE tenant_id = ? AND doctor_id = ? AND appointment_date = CURDATE() AND status IN ('scheduled','confirmed')", [$tid, $did]);
$completedTotal = $cnt("SELECT COUNT(*) FROM appointments WHERE tenant_id = ? AND doctor_id = ? AND status = 'completed'", [$tid, $did]);
$myPatients = $cnt('SELECT COUNT(DISTINCT patient_id) FROM appointments WHERE tenant_id = ? AND doctor_id = ?', [$tid, $did]);
$rxCount = $cnt('SELECT COUNT(*) FROM prescriptions WHERE tenant_id = ? AND doctor_id = ?', [$tid, $did]);
$admittedMine = $cnt("SELECT COUNT(*) FROM admissions WHERE tenant_id = ? AND doctor_id = ? AND status = 'admitted'", [$tid, $did]);

$s = $pdo->prepare(
    "SELECT a.*, p.name AS patient_name, p.patient_code, p.phone
       FROM appointments a JOIN patients p ON p.id = a.patient_id
      WHERE a.tenant_id = ? AND a.doctor_id = ? AND a.appointment_date = CURDATE()
      ORDER BY a.appointment_time"
);
$s->execute([$tid, $did]);
$today = $s->fetchAll();

$s = $pdo->prepare(
    "SELECT a.*, p.name AS patient_name, p.patient_code
       FROM appointments a JOIN patients p ON p.id = a.patient_id
      WHERE a.tenant_id = ? AND a.doctor_id = ? AND a.appointment_date > CURDATE() AND a.status IN ('scheduled','confirmed')
      ORDER BY a.appointment_date, a.appointment_time LIMIT 6"
);
$s->execute([$tid, $did]);
$upcoming = $s->fetchAll();

$todaySchedule = doctor_schedule_for($pdo, $tid, $did, date('Y-m-d'));

$pageTitle = 'Doctor Dashboard';
include __DIR__ . '/../includes/header.php';
?>
<div class="card mb-3">
  <div class="card-body d-flex flex-wrap align-items-center gap-3">
    <span class="avatar-initials" style="width:56px;height:56px;font-size:1.2rem"><?= e(initials($doctor['name'])) ?></span>
    <span>
      <strong class="d-block fs-5"><?= e($doctor['name']) ?></strong>
      <small class="text-muted"><?= e($doctor['specialization'] ?? '') ?> · <?= e($doctor['department_name'] ?? 'No department') ?> · Consultation fee <?= money((float)$doctor['consultation_fee']) ?></small>
    </span>
    <span class="ms-auto text-end">
      <span class="d-block small text-muted">Today's clinic hours</span>
      <strong><?= $todaySchedule && (int)$todaySchedule['is_available'] ? e(substr((string)$todaySchedule['start_time'], 0, 5)) . ' – ' . e(substr((string)$todaySchedule['end_time'], 0, 5)) : 'Not scheduled today' ?></strong>
    </span>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-6 col-xl-2"><div class="card stat-card h-100"><span class="stat-ic ic-blue"><i class="bi bi-calendar2-check"></i></span><span><span class="num d-block"><?= $todayCount ?></span><span class="lbl">Appointments today</span></span></div></div>
  <div class="col-6 col-xl-2"><div class="card stat-card h-100"><span class="stat-ic ic-amber"><i class="bi bi-hourglass-split"></i></span><span><span class="num d-block"><?= $todayPending ?></span><span class="lbl">Still to see today</span></span></div></div>
  <div class="col-6 col-xl-2"><div class="card stat-card h-100"><span class="stat-ic ic-green"><i class="bi bi-check2-circle"></i></span><span><span class="num d-block"><?= $completedTotal ?></span><span class="lbl">Completed visits</span></span></div></div>
  <div class="col-6 col-xl-2"><div class="card stat-card h-100"><span class="stat-ic ic-violet"><i class="bi bi-people"></i></span><span><span class="num d-block"><?= $myPatients ?></span><span class="lbl">My patients</span></span></div></div>
  <div class="col-6 col-xl-2"><div class="card stat-card h-100"><span class="stat-ic ic-teal"><i class="bi bi-prescription2"></i></span><span><span class="num d-block"><?= $rxCount ?></span><span class="lbl">Prescriptions written</span></span></div></div>
  <div class="col-6 col-xl-2"><div class="card stat-card h-100"><span class="stat-ic ic-rose"><i class="bi bi-hospital"></i></span><span><span class="num d-block"><?= $admittedMine ?></span><span class="lbl">Admitted under me</span></span></div></div>
</div>

<div class="row g-3">
  <div class="col-lg-8">
    <div class="card mb-3">
      <div class="card-header d-flex justify-content-between align-items-center"><span>Today's clinic</span><a class="btn btn-sm btn-outline-primary" href="appointments.php">All my appointments</a></div>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead><tr><th>Time</th><th>Patient</th><th>Contact</th><th>Type</th><th>Reason</th><th>Status</th></tr></thead>
          <tbody>
          <?php if (!$today): ?><tr><td colspan="6" class="text-muted text-center py-4">No appointments today — enjoy the quiet.</td></tr><?php endif; ?>
          <?php foreach ($today as $a): ?>
            <tr>
              <td><strong><?= e(date('h:i A', strtotime((string)$a['appointment_time']))) ?></strong></td>
              <td><a href="patients.php?id=<?= (int)$a['patient_id'] ?>"><?= e($a['patient_name']) ?></a> <small class="text-muted"><?= e($a['patient_code']) ?></small></td>
              <td><small><?= e($a['phone'] ?? '—') ?></small></td>
              <td><?= e($a['type']) ?></td>
              <td><?= e($a['reason'] ?? '—') ?></td>
              <td><?= status_badge((string)$a['status']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <div class="card">
      <div class="card-header">My appointments — last 6 months</div>
      <div class="card-body"><div style="height:210px"><canvas id="docMonthly"></canvas></div></div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header">Upcoming appointments</div>
      <div class="card-body">
        <?php if (!$upcoming): ?><p class="text-muted mb-0">Nothing upcoming on your schedule.</p><?php endif; ?>
        <?php foreach ($upcoming as $a): ?>
          <div class="d-flex gap-2 py-2 border-bottom">
            <span class="doc-thumb"><i class="bi bi-calendar-event"></i></span>
            <span>
              <strong class="d-block small"><?= e($a['patient_name']) ?></strong>
              <small class="text-muted"><?= fmt_date($a['appointment_date']) ?> · <?= e(date('h:i A', strtotime((string)$a['appointment_time']))) ?> · <?= e($a['type']) ?></small>
            </span>
            <span class="ms-auto"><?= status_badge((string)$a['status']) ?></span>
          </div>
        <?php endforeach; ?>
        <a class="btn btn-primary w-100 mt-3" href="prescriptions.php"><i class="bi bi-prescription2"></i> Write a prescription</a>
        <a class="btn btn-outline-primary w-100 mt-2" href="schedule.php"><i class="bi bi-calendar-week"></i> My schedule &amp; availability</a>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  MediCare.api('dashboard-stats.php?doctor_id=<?= $did ?>').then(function (data) {
    var c = data.charts.monthly_appointments;
    MediCare.chart('docMonthly', { type: 'bar', data: { labels: c.labels, datasets: [{ label: 'Appointments', data: c.data, backgroundColor: 'rgba(37,99,235,.75)', borderRadius: 6 }] }, options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } } });
  }).catch(function (err) { MediCare.toast(err.message, 'error'); });
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
