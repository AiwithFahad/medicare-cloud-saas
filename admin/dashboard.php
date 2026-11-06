<?php
declare(strict_types=1);
/* Hospital Admin dashboard (Upgrade v2 page tree).
 * Every number and chart is a live query: stat cards + charts load from
 * api/dashboard-stats.php through the MediCare JS layer; the tables below
 * (today's appointments, stock alerts, reminders, emergencies) are
 * server-rendered from the same live tables. No hardcoded figures. */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/panel.php';
$pdo = db();
$user = require_role(['hospital_admin']);
$tid = require_tenant();
check_tenant_active($pdo);

/* Reminder quick actions */
if (is_post()) {
    verify_csrf();
    if (($_POST['action'] ?? '') === 'reminder_done') {
        $pdo->prepare("UPDATE reminders SET status = 'done' WHERE id = ? AND tenant_id = ?")
            ->execute([(int)($_POST['reminder_id'] ?? 0), $tid]);
        flash('success', 'Reminder marked done.');
    }
    redirect('dashboard.php');
}

$s = $pdo->prepare(
    "SELECT a.*, p.name AS patient_name, p.patient_code, d.name AS doctor_name
       FROM appointments a
       JOIN patients p ON p.id = a.patient_id
       JOIN doctors d ON d.id = a.doctor_id
      WHERE a.tenant_id = ? AND a.appointment_date = CURDATE()
      ORDER BY a.appointment_time"
);
$s->execute([$tid]);
$todayAppts = $s->fetchAll();

$s = $pdo->prepare('SELECT * FROM medicines WHERE tenant_id = ? AND stock <= reorder_level ORDER BY stock ASC LIMIT 6');
$s->execute([$tid]);
$stockAlerts = $s->fetchAll();

$s = $pdo->prepare(
    "SELECT r.*, p.name AS patient_name FROM reminders r
       LEFT JOIN patients p ON p.id = r.patient_id
      WHERE r.tenant_id = ? AND r.status = 'pending' AND r.remind_at <= DATE_ADD(NOW(), INTERVAL 24 HOUR)
      ORDER BY r.remind_at LIMIT 6"
);
$s->execute([$tid]);
$reminders = $s->fetchAll();

$s = $pdo->prepare(
    "SELECT ec.*, d.name AS doctor_name FROM emergency_cases ec
       LEFT JOIN doctors d ON d.id = ec.doctor_id
      WHERE ec.tenant_id = ? AND ec.status IN ('waiting','in_treatment')
      ORDER BY FIELD(ec.triage_level, 'critical','urgent','non_urgent'), ec.arrival_at LIMIT 6"
);
$s->execute([$tid]);
$emergencies = $s->fetchAll();

$base = '../';
$panelSidebar = 'admin';
$pageTitle = 'Hospital Dashboard';
include __DIR__ . '/../includes/header.php';
?>
<div class="row g-3 mb-3">
  <?php
  $cards = [
      ['Total Patients', 'total_patients', 'bi-people', 'ic-blue', ''],
      ['New Patients Today', 'new_patients_today', 'bi-person-plus', 'ic-green', ''],
      ['Doctors (total)', 'total_doctors', 'bi-person-badge', 'ic-violet', ''],
      ['Doctors On Duty Today', 'available_doctors', 'bi-person-check', 'ic-teal', ''],
      ['Appointments Today', 'appointments_today', 'bi-calendar2-check', 'ic-blue', ''],
      ['Pending Appointments', 'appointments_pending', 'bi-hourglass-split', 'ic-amber', ''],
      ['Completed Appointments', 'appointments_completed', 'bi-check2-circle', 'ic-green', ''],
      ['Cancelled Appointments', 'appointments_cancelled', 'bi-x-circle', 'ic-rose', ''],
      ['Total Revenue', 'total_revenue', 'bi-cash-stack', 'ic-green', '$'],
      ['Pending Invoices', 'pending_invoices', 'bi-receipt-cutoff', 'ic-amber', ''],
      ['Beds Available', 'beds_available', 'bi-hospital', 'ic-teal', ''],
      ['Beds Occupied', 'beds_occupied', 'bi-hospital', 'ic-rose', ''],
      ['Emergency Cases Today', 'emergency_today', 'bi-heart-pulse', 'ic-rose', ''],
      ['Pharmacy Stock Alerts', 'stock_alerts', 'bi-capsule-pill', 'ic-amber', ''],
      ['Lab Results Pending', 'lab_pending', 'bi-droplet-half', 'ic-violet', ''],
      ['Reminders Due (24h)', 'reminders_due', 'bi-bell', 'ic-blue', ''],
  ];
  foreach ($cards as [$lbl, $key, $icon, $cls, $prefix]): ?>
  <div class="col-6 col-md-4 col-xl-3">
    <div class="card stat-card h-100">
      <span class="stat-ic <?= e($cls) ?>"><i class="bi <?= e($icon) ?>"></i></span>
      <span><span class="num d-block" data-stat="<?= e($key) ?>" data-prefix="<?= e($prefix) ?>">…</span><span class="lbl"><?= e($lbl) ?></span></span>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<div class="row g-3 mb-3">
  <div class="col-lg-8">
    <div class="card h-100">
      <div class="card-header d-flex flex-wrap gap-2 justify-content-between align-items-center">
        <span>Revenue analytics</span>
        <span class="d-flex gap-2 align-items-center">
          <input type="date" class="form-control form-control-sm" id="revFrom" aria-label="Revenue from">
          <input type="date" class="form-control form-control-sm" id="revTo" aria-label="Revenue to">
          <button class="btn btn-sm btn-primary" id="revApply" type="button"><i class="bi bi-funnel"></i> Apply</button>
        </span>
      </div>
      <div class="card-body"><div style="height:230px"><canvas id="revenueChart"></canvas></div></div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header">Patient registrations — last 7 days</div>
      <div class="card-body"><div style="height:230px"><canvas id="weeklyChart"></canvas></div></div>
    </div>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header">Appointments — last 6 months</div>
      <div class="card-body"><div style="height:210px"><canvas id="monthlyChart"></canvas></div></div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header">Department performance</div>
      <div class="card-body"><div style="height:210px"><canvas id="deptChart"></canvas></div></div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header">Doctor performance (completed)</div>
      <div class="card-body"><div style="height:210px"><canvas id="doctorChart"></canvas></div></div>
    </div>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header">Admission trends — last 6 weeks</div>
      <div class="card-body"><div style="height:200px"><canvas id="admissionChart"></canvas></div></div>
    </div>
  </div>
  <div class="col-lg-8">
    <div class="card h-100">
      <div class="card-header d-flex justify-content-between align-items-center"><span>Today's appointments</span><a class="btn btn-sm btn-outline-primary" href="appointments.php">Manage</a></div>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead><tr><th>Time</th><th>Patient</th><th>Doctor</th><th>Type</th><th>Status</th></tr></thead>
          <tbody>
          <?php if (!$todayAppts): ?><tr><td colspan="5" class="text-muted text-center py-4">No appointments scheduled for today.</td></tr><?php endif; ?>
          <?php foreach ($todayAppts as $a): ?>
            <tr>
              <td><?= e(date('h:i A', strtotime((string)$a['appointment_time']))) ?></td>
              <td><a href="patients.php?id=<?= (int)$a['patient_id'] ?>"><?= e($a['patient_name']) ?></a> <small class="text-muted"><?= e($a['patient_code']) ?></small></td>
              <td><?= e($a['doctor_name']) ?></td>
              <td><?= e($a['type']) ?></td>
              <td><?= status_badge((string)$a['status']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header d-flex justify-content-between align-items-center"><span><i class="bi bi-heart-pulse me-1"></i>Emergency — active now</span><a class="btn btn-sm btn-outline-danger" href="appointments.php#emergency">Open board</a></div>
      <div class="card-body">
        <?php if (!$emergencies): ?><p class="text-muted mb-0">No active emergency cases.</p><?php endif; ?>
        <?php foreach ($emergencies as $ec): ?>
          <div class="d-flex gap-2 py-2 border-bottom">
            <span class="doc-thumb"><i class="bi bi-heart-pulse"></i></span>
            <span>
              <strong class="d-block small"><?= e($ec['patient_name']) ?> <?= status_badge((string)$ec['triage_level']) ?></strong>
              <small class="text-muted d-block"><?= e($ec['complaint'] ?? '') ?></small>
              <small class="text-muted"><?= e($ec['doctor_name'] ?? 'Unassigned') ?> · arrived <?= fmt_datetime($ec['arrival_at']) ?></small>
            </span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header d-flex justify-content-between align-items-center"><span><i class="bi bi-capsule me-1"></i>Pharmacy stock alerts</span><a class="btn btn-sm btn-outline-primary" href="pharmacy.php">Pharmacy</a></div>
      <div class="card-body">
        <?php if (!$stockAlerts): ?><p class="text-muted mb-0">All medicines are above their reorder level.</p><?php endif; ?>
        <?php foreach ($stockAlerts as $m): ?>
          <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
            <span><strong class="d-block small"><?= e($m['name']) ?></strong><small class="text-muted">Reorder at <?= (int)$m['reorder_level'] ?> <?= e($m['unit']) ?></small></span>
            <span class="badge text-bg-<?= (int)$m['stock'] <= 0 ? 'danger' : 'warning' ?>"><?= (int)$m['stock'] ?> left</span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header"><i class="bi bi-bell me-1"></i>Reminders due</div>
      <div class="card-body">
        <?php if (!$reminders): ?><p class="text-muted mb-0">No reminders due in the next 24 hours.</p><?php endif; ?>
        <?php foreach ($reminders as $r): ?>
          <div class="d-flex gap-2 py-2 border-bottom align-items-start">
            <span class="flex-grow-1">
              <span class="d-block small"><?= e($r['title']) ?></span>
              <small class="text-muted"><?= e(ucfirst((string)$r['type'])) ?> · <?= e(ucfirst((string)$r['channel'])) ?> · <?= fmt_datetime($r['remind_at']) ?><?= $r['patient_name'] ? ' · ' . e($r['patient_name']) : '' ?></small>
            </span>
            <form method="post" class="d-inline">
              <?= csrf_field() ?><input type="hidden" name="action" value="reminder_done"><input type="hidden" name="reminder_id" value="<?= (int)$r['id'] ?>">
              <button class="btn btn-sm btn-outline-success" type="submit" title="Mark reminder done"><i class="bi bi-check-lg"></i></button>
            </form>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  var palette = ['#2563eb', '#10b981', '#0d9488', '#f59e0b', '#8b5cf6', '#e11d48', '#64748b'];
  function loadStats(extra) {
    var url = 'dashboard-stats.php' + (extra ? '?' + extra : '');
    MediCare.api(url).then(function (data) {
      document.querySelectorAll('[data-stat]').forEach(function (el) {
        var v = data.stats[el.getAttribute('data-stat')];
        el.textContent = v === undefined ? '—' : (el.getAttribute('data-prefix') || '') + Number(v).toLocaleString(undefined, { maximumFractionDigits: 0 });
      });
      var c = data.charts;
      MediCare.chart('revenueChart', { type: 'line', data: { labels: c.revenue.labels, datasets: [{ label: 'Revenue ($)', data: c.revenue.data, borderColor: '#2563eb', backgroundColor: 'rgba(37,99,235,.12)', fill: true, tension: .35 }] }, options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } } });
      MediCare.chart('weeklyChart', { type: 'bar', data: { labels: c.weekly_registrations.labels, datasets: [{ label: 'New patients', data: c.weekly_registrations.data, backgroundColor: 'rgba(16,185,129,.8)', borderRadius: 6 }] }, options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } } });
      MediCare.chart('monthlyChart', { type: 'bar', data: { labels: c.monthly_appointments.labels, datasets: [{ label: 'Appointments', data: c.monthly_appointments.data, backgroundColor: 'rgba(37,99,235,.75)', borderRadius: 6 }] }, options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } } });
      MediCare.chart('deptChart', { type: 'bar', data: { labels: c.department_performance.labels, datasets: [{ label: 'Appointments', data: c.department_performance.appointments, backgroundColor: 'rgba(13,148,136,.8)', borderRadius: 6 }, { label: 'Doctors', data: c.department_performance.doctors, backgroundColor: 'rgba(100,116,139,.55)', borderRadius: 6 }] }, options: { responsive: true, maintainAspectRatio: false } });
      MediCare.chart('doctorChart', { type: 'bar', data: { labels: c.doctor_performance.labels, datasets: [{ label: 'Completed', data: c.doctor_performance.completed, backgroundColor: 'rgba(16,185,129,.85)', borderRadius: 6 }, { label: 'Total', data: c.doctor_performance.total, backgroundColor: 'rgba(37,99,235,.35)', borderRadius: 6 }] }, options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false } });
      MediCare.chart('admissionChart', { type: 'line', data: { labels: c.admission_trends.labels, datasets: [{ label: 'Admissions', data: c.admission_trends.data, borderColor: '#f59e0b', backgroundColor: 'rgba(245,158,11,.12)', fill: true, tension: .35 }] }, options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } } });
    }).catch(function (err) { MediCare.toast(err.message, 'error'); });
  }
  loadStats('');
  document.getElementById('revApply').addEventListener('click', function () {
    var f = document.getElementById('revFrom').value, t = document.getElementById('revTo').value;
    var qs = new URLSearchParams();
    if (f) qs.set('from', f);
    if (t) qs.set('to', t);
    loadStats(qs.toString());
  });
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
