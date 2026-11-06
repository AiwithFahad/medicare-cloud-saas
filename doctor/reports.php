<?php
declare(strict_types=1);
/* Doctor — My Reports (Upgrade v2 page tree).
 * Personal practice reporting: monthly appointment volume (via
 * api/dashboard-stats.php with doctor_id), status mix, consultation
 * fees earned from completed visits by month, and recent activity.
 * All figures are live queries scoped to this doctor. */
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

$statusRows = $pdo->prepare('SELECT status, COUNT(*) AS c FROM appointments WHERE tenant_id = ? AND doctor_id = ? GROUP BY status ORDER BY c DESC');
$statusRows->execute([$tid, $did]);
$statusRows = $statusRows->fetchAll();
$total = array_sum(array_map(static fn($r) => (int)$r['c'], $statusRows)) ?: 1;

$feeRows = $pdo->prepare(
    "SELECT DATE_FORMAT(appointment_date, '%Y-%m') AS ym, COUNT(*) AS visits, COALESCE(SUM(fee),0) AS earned
       FROM appointments
      WHERE tenant_id = ? AND doctor_id = ? AND status = 'completed'
        AND appointment_date >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 5 MONTH), '%Y-%m-01')
      GROUP BY ym"
);
$feeRows->execute([$tid, $did]);
$feeMap = [];
foreach ($feeRows->fetchAll() as $r) {
    $feeMap[$r['ym']] = $r;
}
$feeMonths = [];
for ($i = 5; $i >= 0; $i--) {
    $ts = strtotime("-{$i} months");
    $ym = date('Y-m', $ts);
    $feeMonths[] = ['label' => date('M Y', $ts), 'visits' => (int)($feeMap[$ym]['visits'] ?? 0), 'earned' => (float)($feeMap[$ym]['earned'] ?? 0)];
}
$earnedTotal = array_sum(array_column($feeMonths, 'earned'));

$recent = $pdo->prepare(
    "SELECT a.*, p.name AS patient_name FROM appointments a JOIN patients p ON p.id = a.patient_id
      WHERE a.tenant_id = ? AND a.doctor_id = ? AND a.status = 'completed'
      ORDER BY a.appointment_date DESC, a.appointment_time DESC LIMIT 8"
);
$recent->execute([$tid, $did]);
$recent = $recent->fetchAll();

$pageTitle = 'My Reports';
include __DIR__ . '/../includes/header.php';
?>
<div class="row g-3 mb-3">
  <div class="col-lg-8">
    <div class="card h-100">
      <div class="card-header">My appointments — last 6 months (live API)</div>
      <div class="card-body"><div style="height:220px"><canvas id="monthlyChart"></canvas></div></div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header">Appointment status mix</div>
      <div class="card-body">
        <?php if (!$statusRows): ?><p class="text-muted mb-0">No appointments yet.</p><?php endif; ?>
        <?php foreach ($statusRows as $s): $pct = round((int)$s['c'] / $total * 100); ?>
          <div class="d-flex justify-content-between small mb-1"><span><?= e(ucfirst(str_replace('_', ' ', (string)$s['status']))) ?></span><span><?= (int)$s['c'] ?> · <?= $pct ?>%</span></div>
          <div class="progress mb-3"><div class="progress-bar" style="width:<?= $pct ?>%"></div></div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="card h-100">
      <div class="card-header">Consultation fees earned — completed visits</div>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead><tr><th>Month</th><th>Completed visits</th><th class="text-end">Fees earned</th><th style="width:34%">Share</th></tr></thead>
          <tbody>
          <?php foreach (array_reverse($feeMonths) as $m): $share = $earnedTotal > 0 ? round($m['earned'] / $earnedTotal * 100) : 0; ?>
            <tr><td><?= e($m['label']) ?></td><td><?= $m['visits'] ?></td><td class="text-end"><?= money($m['earned']) ?></td>
            <td><div class="progress"><div class="progress-bar bg-success" style="width:<?= $share ?>%"></div></div></td></tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot><tr><th>Total (6 months)</th><th><?= array_sum(array_column($feeMonths, 'visits')) ?></th><th class="text-end"><?= money($earnedTotal) ?></th><th></th></tr></tfoot>
        </table>
      </div>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="card h-100">
      <div class="card-header">Recent completed visits</div>
      <div class="card-body">
        <?php if (!$recent): ?><p class="text-muted mb-0">No completed visits yet.</p><?php endif; ?>
        <?php foreach ($recent as $a): ?>
          <div class="d-flex gap-2 py-2 border-bottom">
            <span class="doc-thumb"><i class="bi bi-check2-circle"></i></span>
            <span>
              <strong class="d-block small"><?= e($a['patient_name']) ?></strong>
              <small class="text-muted"><?= fmt_date($a['appointment_date']) ?> · <?= e($a['type']) ?> · <?= e($a['reason'] ?? '') ?></small>
            </span>
            <span class="ms-auto small"><?= money((float)$a['fee']) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  MediCare.api('dashboard-stats.php?doctor_id=<?= $did ?>').then(function (data) {
    var c = data.charts.monthly_appointments;
    MediCare.chart('monthlyChart', { type: 'bar', data: { labels: c.labels, datasets: [{ label: 'Appointments', data: c.data, backgroundColor: 'rgba(37,99,235,.75)', borderRadius: 6 }] }, options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } } });
  }).catch(function (err) { MediCare.toast(err.message, 'error'); });
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
