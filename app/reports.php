<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
$pdo = db();
$user = require_login();
$tid = require_tenant();
check_tenant_active($pdo);
if (!has_permission('reports.view')) { http_response_code(403); exit('Access denied for your role.'); }

/* ---- Monthly series helper (last 6 months, zero-filled) ---- */
$months = [];
for ($i = 5; $i >= 0; $i--) $months[date('Y-m', strtotime("-$i months"))] = date('M Y', strtotime("-$i months"));

/* ---- Revenue by month ---- */
$revS = $pdo->prepare('SELECT DATE_FORMAT(paid_at,"%Y-%m") AS ym, COALESCE(SUM(amount),0) AS total FROM patient_payments WHERE tenant_id=? AND paid_at >= DATE_SUB(DATE_FORMAT(CURDATE(),"%Y-%m-01"), INTERVAL 5 MONTH) GROUP BY ym');
$revS->execute([$tid]);
$revenue = array_fill_keys(array_keys($months), 0.0);
foreach ($revS->fetchAll() as $r) if (isset($revenue[$r['ym']])) $revenue[$r['ym']] = (float)$r['total'];

/* ---- CSV export must run before any HTML output ---- */
if (($_GET['export'] ?? '') === 'revenue') {
    $clean = [];
    foreach ($months as $ym => $label) $clean[] = ['month' => $label, 'revenue' => number_format($revenue[$ym], 2, '.', '')];
    export_csv($clean, 'monthly-revenue.csv');
}

/* ---- Appointments by status ---- */
$as = $pdo->prepare('SELECT status, COUNT(*) AS c FROM appointments WHERE tenant_id=? GROUP BY status');
$as->execute([$tid]);
$apptByStatus = $as->fetchAll();

/* ---- Bed occupancy per ward ---- */
$bs = $pdo->prepare('SELECT w.name AS ward, COUNT(b.id) AS total_beds, COALESCE(SUM(CASE WHEN b.status="occupied" THEN 1 ELSE 0 END),0) AS occupied FROM wards w LEFT JOIN beds b ON b.ward_id=w.id AND b.tenant_id=? WHERE w.tenant_id=? GROUP BY w.id, w.name ORDER BY w.name');
$bs->execute([$tid, $tid]);
$wards = $bs->fetchAll();

/* ---- Top departments by appointments ---- */
$ds = $pdo->prepare('SELECT d.name, COUNT(a.id) AS c FROM appointments a JOIN departments d ON d.id=a.department_id WHERE a.tenant_id=? GROUP BY d.id, d.name ORDER BY c DESC LIMIT 5');
$ds->execute([$tid]);
$topDepts = $ds->fetchAll();

/* ---- Lab completion rate ---- */
$ls = $pdo->prepare('SELECT COUNT(*) AS total, COALESCE(SUM(CASE WHEN status="completed" THEN 1 ELSE 0 END),0) AS done FROM lab_results WHERE tenant_id=?');
$ls->execute([$tid]);
$lab = $ls->fetch() ?: ['total' => 0, 'done' => 0];
$labRate = (int)$lab['total'] > 0 ? round((int)$lab['done'] * 100 / (int)$lab['total'], 1) : 0.0;

/* ---- New patients per month ---- */
$ns = $pdo->prepare('SELECT DATE_FORMAT(created_at,"%Y-%m") AS ym, COUNT(*) AS c FROM patients WHERE tenant_id=? AND created_at >= DATE_SUB(DATE_FORMAT(CURDATE(),"%Y-%m-01"), INTERVAL 5 MONTH) GROUP BY ym');
$ns->execute([$tid]);
$newPatients = array_fill_keys(array_keys($months), 0);
foreach ($ns->fetchAll() as $r) if (isset($newPatients[$r['ym']])) $newPatients[$r['ym']] = (int)$r['c'];

/* ---- Summary cards ---- */
$totRev = array_sum($revenue);
$cs = $pdo->prepare('SELECT (SELECT COUNT(*) FROM patients WHERE tenant_id=?) AS patients, (SELECT COUNT(*) FROM appointments WHERE tenant_id=?) AS appointments, (SELECT COUNT(*) FROM appointments WHERE tenant_id=? AND appointment_date=CURDATE()) AS today_appts, (SELECT COUNT(*) FROM beds WHERE tenant_id=? AND status="occupied") AS beds_occ, (SELECT COUNT(*) FROM beds WHERE tenant_id=?) AS beds_total');
$cs->execute([$tid, $tid, $tid, $tid, $tid]);
$cards = $cs->fetch() ?: [];
$bedPct = (int)($cards['beds_total'] ?? 0) > 0 ? round((int)$cards['beds_occ'] * 100 / (int)$cards['beds_total'], 1) : 0;

$pageTitle = 'Reports & Analytics';
$base = '../';
include __DIR__ . '/../includes/header.php';
?>
<div class="row g-3 mb-4">
  <div class="col-md-3 col-6"><div class="card stat-card"><span class="stat-ic ic-green"><i class="bi bi-cash-stack"></i></span><div><div class="num"><?= money($totRev) ?></div><div class="lbl">Revenue (6 months)</div></div></div></div>
  <div class="col-md-3 col-6"><div class="card stat-card"><span class="stat-ic ic-blue"><i class="bi bi-people"></i></span><div><div class="num"><?= (int)($cards['patients'] ?? 0) ?></div><div class="lbl">Total patients</div></div></div></div>
  <div class="col-md-3 col-6"><div class="card stat-card"><span class="stat-ic ic-violet"><i class="bi bi-calendar-check"></i></span><div><div class="num"><?= (int)($cards['appointments'] ?? 0) ?></div><div class="lbl">Appointments (<?= (int)($cards['today_appts'] ?? 0) ?> today)</div></div></div></div>
  <div class="col-md-3 col-6"><div class="card stat-card"><span class="stat-ic ic-teal"><i class="bi bi-hospital"></i></span><div><div class="num"><?= (int)($cards['beds_occ'] ?? 0) ?>/<?= (int)($cards['beds_total'] ?? 0) ?></div><div class="lbl">Beds occupied (<?= $bedPct ?>%)</div></div></div></div>
</div>

<div class="row g-4 mb-4">
  <div class="col-lg-8">
    <div class="card h-100"><div class="card-header d-flex justify-content-between align-items-center"><span>Revenue by month</span><a class="btn btn-sm btn-outline-primary" href="reports.php?export=revenue"><i class="bi bi-download me-1"></i>Export CSV</a></div>
      <div class="card-body"><canvas id="revChart" height="220"></canvas></div></div>
  </div>
  <div class="col-lg-4">
    <div class="card h-100"><div class="card-header">Appointments by status</div><div class="card-body"><canvas id="apptChart" height="220"></canvas>
      <?php if (!$apptByStatus): ?><p class="text-muted small text-center mb-0">No appointments yet.</p><?php endif; ?></div></div>
  </div>
</div>

<div class="row g-4 mb-4">
  <div class="col-lg-8">
    <div class="card h-100"><div class="card-header">New patients per month</div><div class="card-body"><canvas id="patChart" height="200"></canvas></div></div>
  </div>
  <div class="col-lg-4">
    <div class="card h-100"><div class="card-header">Lab completion rate</div><div class="card-body text-center">
      <div class="display-5 fw-bold text-blue"><?= $labRate ?>%</div>
      <p class="text-muted"><?= (int)$lab['done'] ?> of <?= (int)$lab['total'] ?> ordered tests completed</p>
      <div class="progress"><div class="progress-bar bg-success" style="width:<?= $labRate ?>%"></div></div>
    </div></div>
  </div>
</div>

<div class="row g-4">
  <div class="col-lg-6">
    <div class="card h-100"><div class="card-header">Bed occupancy per ward</div>
      <div class="table-responsive"><table class="table"><thead><tr><th>Ward</th><th class="text-end">Beds</th><th class="text-end">Occupied</th><th style="width:40%">Occupancy</th></tr></thead>
      <tbody>
      <?php if (!$wards): ?><tr><td colspan="4" class="text-muted text-center py-3">No wards configured.</td></tr><?php endif; ?>
      <?php foreach ($wards as $w): $pct = (int)$w['total_beds'] > 0 ? round((int)$w['occupied'] * 100 / (int)$w['total_beds']) : 0; ?>
        <tr><td><?= e($w['ward']) ?></td><td class="text-end"><?= (int)$w['total_beds'] ?></td><td class="text-end"><?= (int)$w['occupied'] ?></td>
        <td><div class="progress"><div class="progress-bar" style="width:<?= $pct ?>%"></div></div><small class="text-muted"><?= $pct ?>%</small></td></tr>
      <?php endforeach; ?>
      </tbody></table></div></div>
  </div>
  <div class="col-lg-6">
    <div class="card h-100"><div class="card-header">Top departments by appointments</div>
      <div class="table-responsive"><table class="table"><thead><tr><th>#</th><th>Department</th><th class="text-end">Appointments</th></tr></thead>
      <tbody>
      <?php if (!$topDepts): ?><tr><td colspan="3" class="text-muted text-center py-3">No appointment data yet.</td></tr><?php endif; ?>
      <?php foreach ($topDepts as $i => $d): ?><tr><td><?= $i + 1 ?></td><td><?= e($d['name']) ?></td><td class="text-end fw-semibold"><?= (int)$d['c'] ?></td></tr><?php endforeach; ?>
      </tbody></table></div></div>
  </div>
</div>

<script>
(function(){
  var labels = <?= json_encode(array_values($months)) ?>;
  var rev = document.getElementById('revChart');
  if (rev) new Chart(rev, {type:'bar', data:{labels:labels, datasets:[{label:'Revenue ($)', data:<?= json_encode(array_values($revenue)) ?>, backgroundColor:'#2563eb', borderRadius:6}]}, options:{plugins:{legend:{display:false}}, scales:{y:{beginAtZero:true}}}});
  var ap = document.getElementById('apptChart');
  if (ap) new Chart(ap, {type:'doughnut', data:{labels:<?= json_encode(array_map(fn($r) => ucwords(str_replace('_', ' ', (string)$r['status'])), $apptByStatus)) ?>, datasets:[{data:<?= json_encode(array_map(fn($r) => (int)$r['c'], $apptByStatus)) ?>, backgroundColor:['#2563eb','#10b981','#f59e0b','#64748b','#ef4444']}]}, options:{plugins:{legend:{position:'bottom'}}}});
  var pa = document.getElementById('patChart');
  if (pa) new Chart(pa, {type:'line', data:{labels:labels, datasets:[{label:'New patients', data:<?= json_encode(array_values($newPatients)) ?>, borderColor:'#10b981', backgroundColor:'rgba(16,185,129,.12)', fill:true, tension:.3}]}, options:{plugins:{legend:{display:false}}, scales:{y:{beginAtZero:true, ticks:{precision:0}}}}});
})();
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
