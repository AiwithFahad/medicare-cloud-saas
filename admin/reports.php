<?php
declare(strict_types=1);
/* Hospital Admin — Reports (Upgrade v2 page tree).
 * Charts come live from api/dashboard-stats.php; the summary tables below
 * are computed from the same tables, and every report can be downloaded
 * as CSV (real exports, generated server-side from live data). */
require_once __DIR__ . '/../includes/auth.php';
$pdo = db();
$user = require_role(['hospital_admin']);
$tid = require_tenant();
check_tenant_active($pdo);
if (!has_permission('reports.view')) {
    flash('error', 'You do not have permission to view reports.');
    redirect('dashboard.php');
}
$base = '../';
$panelSidebar = 'admin';

/* ---------------- CSV exports ---------------- */
$export = (string)($_GET['export'] ?? '');
if ($export === 'revenue') {
    $s = $pdo->prepare("SELECT DATE_FORMAT(paid_at, '%Y-%m') AS month, COUNT(*) AS payments, COALESCE(SUM(amount),0) AS revenue FROM patient_payments WHERE tenant_id = ? GROUP BY month ORDER BY month");
    $s->execute([$tid]);
    export_csv($s->fetchAll(PDO::FETCH_ASSOC), 'hospital-revenue-by-month.csv');
}
if ($export === 'appointments') {
    $s = $pdo->prepare(
        "SELECT a.appointment_date, a.appointment_time, p.patient_code, p.name AS patient, d.name AS doctor, dep.name AS department, a.type, a.status, a.fee
           FROM appointments a JOIN patients p ON p.id = a.patient_id JOIN doctors d ON d.id = a.doctor_id
           LEFT JOIN departments dep ON dep.id = a.department_id
          WHERE a.tenant_id = ? ORDER BY a.appointment_date DESC LIMIT 5000"
    );
    $s->execute([$tid]);
    export_csv($s->fetchAll(PDO::FETCH_ASSOC), 'hospital-appointments.csv');
}
if ($export === 'patients') {
    $s = $pdo->prepare('SELECT patient_code, name, gender, dob, blood_group, phone, email, address, emergency_contact, allergies, created_at FROM patients WHERE tenant_id = ? ORDER BY id');
    $s->execute([$tid]);
    export_csv($s->fetchAll(PDO::FETCH_ASSOC), 'hospital-patients.csv');
}
if ($export === 'expenses') {
    $s = $pdo->prepare('SELECT expense_date, title, category, vendor, payment_method, amount, notes FROM expenses WHERE tenant_id = ? ORDER BY expense_date DESC');
    $s->execute([$tid]);
    export_csv($s->fetchAll(PDO::FETCH_ASSOC), 'hospital-expenses.csv');
}

/* ---------------- Summary tables ---------------- */
$deptReport = $pdo->prepare(
    "SELECT dep.name,
            (SELECT COUNT(*) FROM doctors d WHERE d.department_id = dep.id AND d.tenant_id = dep.tenant_id) AS doctors,
            (SELECT COUNT(*) FROM appointments a WHERE a.department_id = dep.id AND a.tenant_id = dep.tenant_id) AS appointments,
            (SELECT COUNT(*) FROM appointments a WHERE a.department_id = dep.id AND a.tenant_id = dep.tenant_id AND a.status = 'completed') AS completed
       FROM departments dep WHERE dep.tenant_id = ? ORDER BY appointments DESC"
);
$deptReport->execute([$tid]);
$deptReport = $deptReport->fetchAll();

$docReport = $pdo->prepare(
    "SELECT d.name, d.specialization, d.consultation_fee,
            COUNT(a.id) AS total,
            SUM(a.status = 'completed') AS completed,
            SUM(a.status = 'cancelled') AS cancelled,
            COALESCE(SUM(CASE WHEN a.status = 'completed' THEN a.fee ELSE 0 END), 0) AS earned
       FROM doctors d LEFT JOIN appointments a ON a.doctor_id = d.id AND a.tenant_id = d.tenant_id
      WHERE d.tenant_id = ? GROUP BY d.id, d.name, d.specialization, d.consultation_fee ORDER BY completed DESC"
);
$docReport->execute([$tid]);
$docReport = $docReport->fetchAll();

$statusReport = $pdo->prepare('SELECT status, COUNT(*) AS c FROM appointments WHERE tenant_id = ? GROUP BY status ORDER BY c DESC');
$statusReport->execute([$tid]);
$statusReport = $statusReport->fetchAll();
$apptTotal = array_sum(array_map(static fn($r) => (int)$r['c'], $statusReport)) ?: 1;

$pageTitle = 'Reports';
include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex flex-wrap gap-2 mb-3">
  <span class="me-auto align-self-center text-muted small">All figures are computed live from your hospital's data.</span>
  <a class="btn btn-outline-primary btn-sm" href="reports.php?export=revenue"><i class="bi bi-download"></i> Revenue CSV</a>
  <a class="btn btn-outline-primary btn-sm" href="reports.php?export=appointments"><i class="bi bi-download"></i> Appointments CSV</a>
  <a class="btn btn-outline-primary btn-sm" href="reports.php?export=patients"><i class="bi bi-download"></i> Patients CSV</a>
  <a class="btn btn-outline-primary btn-sm" href="reports.php?export=expenses"><i class="bi bi-download"></i> Expenses CSV</a>
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
      <div class="card-header">Appointments by status (all time)</div>
      <div class="card-body">
        <?php foreach ($statusReport as $s): $pct = round((int)$s['c'] / $apptTotal * 100); ?>
          <div class="d-flex justify-content-between small mb-1"><span><?= e(ucfirst(str_replace('_', ' ', (string)$s['status']))) ?></span><span><?= (int)$s['c'] ?> · <?= $pct ?>%</span></div>
          <div class="progress mb-3"><div class="progress-bar" style="width:<?= $pct ?>%"></div></div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-lg-4"><div class="card h-100"><div class="card-header">Patient registrations — last 7 days</div><div class="card-body"><div style="height:200px"><canvas id="weeklyChart"></canvas></div></div></div></div>
  <div class="col-lg-4"><div class="card h-100"><div class="card-header">Department performance</div><div class="card-body"><div style="height:200px"><canvas id="deptChart"></canvas></div></div></div></div>
  <div class="col-lg-4"><div class="card h-100"><div class="card-header">Admission trends — last 6 weeks</div><div class="card-body"><div style="height:200px"><canvas id="admissionChart"></canvas></div></div></div></div>
</div>

<div class="row g-3">
  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header">Department report</div>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead><tr><th>Department</th><th>Doctors</th><th>Appointments</th><th>Completed</th><th>Completion rate</th></tr></thead>
          <tbody>
          <?php if (!$deptReport): ?><tr><td colspan="5" class="text-muted text-center py-4">No departments yet.</td></tr><?php endif; ?>
          <?php foreach ($deptReport as $d): $rate = (int)$d['appointments'] > 0 ? round((int)$d['completed'] / (int)$d['appointments'] * 100) : 0; ?>
            <tr><td><strong><?= e($d['name']) ?></strong></td><td><?= (int)$d['doctors'] ?></td><td><?= (int)$d['appointments'] ?></td><td><?= (int)$d['completed'] ?></td>
            <td><div class="progress" style="min-width:90px"><div class="progress-bar bg-success" style="width:<?= $rate ?>%"></div></div><small class="text-muted"><?= $rate ?>%</small></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header">Doctor report</div>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead><tr><th>Doctor</th><th>Total</th><th>Completed</th><th>Cancelled</th><th class="text-end">Fees earned</th></tr></thead>
          <tbody>
          <?php if (!$docReport): ?><tr><td colspan="5" class="text-muted text-center py-4">No doctors yet.</td></tr><?php endif; ?>
          <?php foreach ($docReport as $d): ?>
            <tr><td><strong><?= e($d['name']) ?></strong><br><small class="text-muted"><?= e($d['specialization'] ?? '') ?></small></td><td><?= (int)$d['total'] ?></td><td><?= (int)$d['completed'] ?></td><td><?= (int)$d['cancelled'] ?></td><td class="text-end"><?= money((float)$d['earned']) ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  function load(extra) {
    MediCare.api('dashboard-stats.php' + (extra ? '?' + extra : '')).then(function (data) {
      var c = data.charts;
      MediCare.chart('revenueChart', { type: 'line', data: { labels: c.revenue.labels, datasets: [{ label: 'Revenue ($)', data: c.revenue.data, borderColor: '#2563eb', backgroundColor: 'rgba(37,99,235,.12)', fill: true, tension: .35 }] }, options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } } });
      MediCare.chart('weeklyChart', { type: 'bar', data: { labels: c.weekly_registrations.labels, datasets: [{ label: 'New patients', data: c.weekly_registrations.data, backgroundColor: 'rgba(16,185,129,.8)', borderRadius: 6 }] }, options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } } });
      MediCare.chart('deptChart', { type: 'bar', data: { labels: c.department_performance.labels, datasets: [{ label: 'Appointments', data: c.department_performance.appointments, backgroundColor: 'rgba(13,148,136,.8)', borderRadius: 6 }, { label: 'Doctors', data: c.department_performance.doctors, backgroundColor: 'rgba(100,116,139,.55)', borderRadius: 6 }] }, options: { responsive: true, maintainAspectRatio: false } });
      MediCare.chart('admissionChart', { type: 'line', data: { labels: c.admission_trends.labels, datasets: [{ label: 'Admissions', data: c.admission_trends.data, borderColor: '#f59e0b', backgroundColor: 'rgba(245,158,11,.12)', fill: true, tension: .35 }] }, options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } } });
    }).catch(function (err) { MediCare.toast(err.message, 'error'); });
  }
  load('');
  document.getElementById('revApply').addEventListener('click', function () {
    var qs = new URLSearchParams();
    var f = document.getElementById('revFrom').value, t = document.getElementById('revTo').value;
    if (f) qs.set('from', f);
    if (t) qs.set('to', t);
    load(qs.toString());
  });
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
