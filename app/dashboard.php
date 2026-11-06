<?php declare(strict_types=1); require_once __DIR__.'/../includes/auth.php'; $pdo=db(); $user=require_role(['hospital_admin','doctor','receptionist','nurse','accountant','pharmacist','lab_technician']); $tid=require_tenant(); check_tenant_active($pdo);

// Quick actions on today's appointments
if (is_post()) {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    $apptId = (int)($_POST['appointment_id'] ?? 0);
    if ($action === 'appt_status' && $apptId > 0) {
        $status = (string)($_POST['status'] ?? '');
        if (in_array($status, ['completed','cancelled','confirmed'], true)) {
            $s = $pdo->prepare('UPDATE appointments SET status=? WHERE id=? AND tenant_id=?');
            $s->execute([$status, $apptId, $tid]);
            audit_log($pdo, 'appointment.'.$status, 'appointments', $apptId, 'Quick action from dashboard');
            log_activity($pdo, 'Appointment #'.$apptId.' marked '.$status);
            flash('success', 'Appointment updated.');
        }
        redirect('dashboard.php');
    }
    redirect('dashboard.php');
}

// Stats
$cnt = function(string $sql, array $params = []) use ($pdo, $tid): mixed {
    $s = $pdo->prepare($sql); $s->execute($params); return $s->fetchColumn();
};
$totalPatients = (int)$cnt('SELECT COUNT(*) FROM patients WHERE tenant_id=?', [$tid]);
$totalDoctors = (int)$cnt('SELECT COUNT(*) FROM doctors WHERE tenant_id=? AND status="active"', [$tid]);
$todayAppts = (int)$cnt('SELECT COUNT(*) FROM appointments WHERE tenant_id=? AND appointment_date=CURDATE()', [$tid]);
$totalBeds = (int)$cnt('SELECT COUNT(*) FROM beds WHERE tenant_id=?', [$tid]);
$occupiedBeds = (int)$cnt('SELECT COUNT(*) FROM beds WHERE tenant_id=? AND status="occupied"', [$tid]);
$bedOccupancy = $totalBeds > 0 ? round(($occupiedBeds / $totalBeds) * 100, 1) : 0;
$revenueMonth = (float)$cnt('SELECT COALESCE(SUM(amount),0) FROM patient_payments WHERE tenant_id=? AND MONTH(paid_at)=MONTH(CURDATE()) AND YEAR(paid_at)=YEAR(CURDATE())', [$tid]);
$pendingLab = (int)$cnt('SELECT COUNT(*) FROM lab_results WHERE tenant_id=? AND status IN ("pending","in_progress")', [$tid]);
$lowStock = (int)$cnt('SELECT COUNT(*) FROM medicines WHERE tenant_id=? AND stock<=reorder_level', [$tid]);
$admitted = (int)$cnt('SELECT COUNT(*) FROM admissions WHERE tenant_id=? AND status="admitted"', [$tid]);

// Charts: appointments last 7 days
$s = $pdo->prepare('SELECT appointment_date, COUNT(*) AS c FROM appointments WHERE tenant_id=? AND appointment_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) GROUP BY appointment_date ORDER BY appointment_date');
$s->execute([$tid]);
$byDay = [];
foreach ($s->fetchAll() as $r) { $byDay[$r['appointment_date']] = (int)$r['c']; }
$chartLabels = []; $chartData = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-{$i} days"));
    $chartLabels[] = date('D d M', strtotime($d));
    $chartData[] = $byDay[$d] ?? 0;
}
// Bed status doughnut
$s = $pdo->prepare('SELECT status, COUNT(*) AS c FROM beds WHERE tenant_id=? GROUP BY status');
$s->execute([$tid]);
$bedStatus = ['available'=>0,'occupied'=>0,'reserved'=>0,'maintenance'=>0];
foreach ($s->fetchAll() as $r) { $bedStatus[$r['status']] = (int)$r['c']; }

// Patients by department via appointments
$s = $pdo->prepare('SELECT d.name, COUNT(a.id) AS c FROM appointments a LEFT JOIN departments d ON d.id=a.department_id AND d.tenant_id=a.tenant_id WHERE a.tenant_id=? GROUP BY a.department_id, d.name ORDER BY c DESC LIMIT 8');
$s->execute([$tid]);
$deptLabels = []; $deptData = [];
foreach ($s->fetchAll() as $r) { $deptLabels[] = $r['name'] ?: 'No Department'; $deptData[] = (int)$r['c']; }

// Today's appointments
$s = $pdo->prepare('SELECT a.*, p.name AS patient_name, p.patient_code, doc.name AS doctor_name FROM appointments a JOIN patients p ON p.id=a.patient_id JOIN doctors doc ON doc.id=a.doctor_id WHERE a.tenant_id=? AND a.appointment_date=CURDATE() ORDER BY a.appointment_time ASC LIMIT 20');
$s->execute([$tid]);
$todayList = $s->fetchAll();

// Recent activity
$s = $pdo->prepare('SELECT * FROM activity_logs WHERE tenant_id=? ORDER BY id DESC LIMIT 10');
$s->execute([$tid]);
$activities = $s->fetchAll();

$sub = active_subscription($pdo, $tid);
$usage = usage_counts($pdo, $tid);
$planLimits = [];
if ($sub) {
    $planLimits = [
        'patients' => (int)$sub['max_patients'],
        'doctors' => (int)$sub['max_doctors'],
        'staff' => (int)$sub['max_staff'],
        'departments' => (int)$sub['max_departments'],
    ];
}

$pageTitle='Dashboard'; $base='../'; include __DIR__.'/../includes/header.php';
?>
<?php if ($sub): ?>
<div class="card mb-4"><div class="card-body d-flex flex-wrap gap-3 align-items-center justify-content-between">
  <div>
    <strong>Current Plan: <?= e((string)$sub['plan_name']) ?></strong>
    <span class="badge text-bg-<?= e((string)$sub['status']==='trial'?'info':'success') ?> ms-2"><?= e(ucfirst((string)$sub['status'])) ?></span>
    <div class="text-muted small">
      <?php if (!empty($sub['trial_ends_at'])): ?>Trial ends <?= e(fmt_date((string)$sub['trial_ends_at'])) ?> · <?php endif; ?>
      <?php if (!empty($sub['ends_at'])): ?>Renews / ends <?= e(fmt_date((string)$sub['ends_at'])) ?><?php endif; ?>
      · <a href="subscription.php">Manage subscription</a>
    </div>
  </div>
  <div class="d-flex flex-wrap gap-4">
    <?php foreach (['patients'=>'Patients','doctors'=>'Doctors','staff'=>'Staff','departments'=>'Departments'] as $k=>$label): $lim=$planLimits[$k]??0; $used=$usage[$k]??0; $pct=$lim>0?min(100, round($used/$lim*100)):0; ?>
      <div style="min-width:130px"><div class="d-flex justify-content-between small"><span><?= e($label) ?></span><strong><?= $used ?> / <?= $lim<0?'∞':$lim ?></strong></div><div class="progress mt-1"><div class="progress-bar" style="width:<?= $pct ?>%"></div></div></div>
    <?php endforeach; ?>
  </div>
</div></div>
<?php endif; ?>

<div class="row g-3 mb-4">
  <div class="col-6 col-xl-3"><div class="card stat-card"><span class="stat-ic ic-blue"><i class="bi bi-people"></i></span><div><div class="num"><?= $totalPatients ?></div><div class="lbl">Total Patients</div></div></div></div>
  <div class="col-6 col-xl-3"><div class="card stat-card"><span class="stat-ic ic-green"><i class="bi bi-person-badge"></i></span><div><div class="num"><?= $totalDoctors ?></div><div class="lbl">Total Doctors</div></div></div></div>
  <div class="col-6 col-xl-3"><div class="card stat-card"><span class="stat-ic ic-violet"><i class="bi bi-calendar-check"></i></span><div><div class="num"><?= $todayAppts ?></div><div class="lbl">Today's Appointments</div></div></div></div>
  <div class="col-6 col-xl-3"><div class="card stat-card"><span class="stat-ic ic-rose"><i class="bi bi-hospital"></i></span><div><div class="num"><?= e((string)$bedOccupancy) ?>%</div><div class="lbl">Bed Occupancy (<?= $occupiedBeds ?>/<?= $totalBeds ?>)</div></div></div></div>
  <div class="col-6 col-xl-3"><div class="card stat-card"><span class="stat-ic ic-teal"><i class="bi bi-cash-stack"></i></span><div><div class="num"><?= e(money($revenueMonth)) ?></div><div class="lbl">Revenue This Month</div></div></div></div>
  <div class="col-6 col-xl-3"><div class="card stat-card"><span class="stat-ic ic-amber"><i class="bi bi-droplet-half"></i></span><div><div class="num"><?= $pendingLab ?></div><div class="lbl">Pending Lab Results</div></div></div></div>
  <div class="col-6 col-xl-3"><div class="card stat-card"><span class="stat-ic ic-amber"><i class="bi bi-capsule"></i></span><div><div class="num"><?= $lowStock ?></div><div class="lbl">Low-Stock Medicines</div></div></div></div>
  <div class="col-6 col-xl-3"><div class="card stat-card"><span class="stat-ic ic-blue"><i class="bi bi-door-open"></i></span><div><div class="num"><?= $admitted ?></div><div class="lbl">Admitted Patients</div></div></div></div>
</div>

<div class="row g-3 mb-4">
  <div class="col-lg-8"><div class="card h-100"><div class="card-header">Appointments — Last 7 Days</div><div class="card-body"><canvas id="apptChart" height="220"></canvas></div></div></div>
  <div class="col-lg-4"><div class="card h-100"><div class="card-header">Bed Status</div><div class="card-body"><canvas id="bedChart" height="220"></canvas></div></div></div>
  <div class="col-12"><div class="card"><div class="card-header">Appointments by Department</div><div class="card-body"><canvas id="deptChart" height="160"></canvas></div></div></div>
</div>

<div class="row g-3">
  <div class="col-lg-8"><div class="card"><div class="card-header d-flex justify-content-between align-items-center"><span>Today's Appointments</span><a class="btn btn-sm btn-primary" href="appointments.php">View all</a></div>
    <?php if (!$todayList): ?><div class="empty-state"><img src="../assets/img/empty.svg" alt=""><p>No appointments scheduled for today.</p><a class="btn btn-primary btn-sm" href="appointments.php">Book appointment</a></div>
    <?php else: ?><div class="table-responsive"><table class="table"><thead><tr><th>Patient</th><th>Doctor</th><th>Time</th><th>Status</th><th class="text-end">Actions</th></tr></thead><tbody>
      <?php foreach ($todayList as $a): ?><tr>
        <td><span class="avatar-sm me-2"><?= e(initials((string)$a['patient_name'])) ?></span><strong><?= e((string)$a['patient_name']) ?></strong><div class="text-muted small"><?= e((string)$a['patient_code']) ?></div></td>
        <td><?= e((string)$a['doctor_name']) ?></td>
        <td><?= e(date('h:i A', strtotime((string)$a['appointment_time']))) ?></td>
        <td><?= status_badge((string)$a['status']) ?></td>
        <td class="text-end"><div class="d-inline-flex gap-1">
          <?php if (in_array($a['status'], ['scheduled','confirmed'], true)): ?>
          <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="appt_status"><input type="hidden" name="appointment_id" value="<?= (int)$a['id'] ?>"><input type="hidden" name="status" value="completed"><button class="btn btn-sm btn-success" title="Mark completed"><i class="bi bi-check-lg"></i></button></form>
          <form method="post" class="d-inline" data-confirm="Cancel this appointment?"><?= csrf_field() ?><input type="hidden" name="action" value="appt_status"><input type="hidden" name="appointment_id" value="<?= (int)$a['id'] ?>"><input type="hidden" name="status" value="cancelled"><button class="btn btn-sm btn-outline-danger" title="Cancel"><i class="bi bi-x-lg"></i></button></form>
          <?php endif; ?>
        </div></td>
      </tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
  </div></div>
  <div class="col-lg-4"><div class="card"><div class="card-header">Recent Activity</div><div class="card-body">
    <?php if (!$activities): ?><div class="empty-state"><img src="../assets/img/empty.svg" alt=""><p>No activity yet.</p></div>
    <?php else: ?><ul class="list-unstyled mb-0"><?php foreach ($activities as $act): ?><li class="d-flex gap-2 mb-3"><span class="avatar-sm"><i class="bi bi-activity"></i></span><div><div class="small"><?= e((string)$act['description']) ?></div><div class="text-muted small"><?= e(fmt_datetime((string)$act['created_at'])) ?></div></div></li><?php endforeach; ?></ul><?php endif; ?>
  </div></div></div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function(){
  if (typeof Chart === 'undefined') return;
  new Chart(document.getElementById('apptChart'), {type:'line', data:{labels:<?= json_encode($chartLabels) ?>, datasets:[{label:'Appointments', data:<?= json_encode($chartData) ?>, borderColor:'#2563eb', backgroundColor:'rgba(37,99,235,.12)', fill:true, tension:.35}]}, options:{responsive:true, scales:{y:{beginAtZero:true, ticks:{precision:0}}}}});
  new Chart(document.getElementById('bedChart'), {type:'doughnut', data:{labels:['Available','Occupied','Reserved','Maintenance'], datasets:[{data:[<?= (int)$bedStatus['available'] ?>,<?= (int)$bedStatus['occupied'] ?>,<?= (int)$bedStatus['reserved'] ?>,<?= (int)$bedStatus['maintenance'] ?>], backgroundColor:['#10b981','#e11d48','#f59e0b','#64748b']}]}, options:{responsive:true}});
  new Chart(document.getElementById('deptChart'), {type:'bar', data:{labels:<?= json_encode($deptLabels) ?>, datasets:[{label:'Appointments', data:<?= json_encode($deptData) ?>, backgroundColor:'#0d9488'}]}, options:{responsive:true, scales:{y:{beginAtZero:true, ticks:{precision:0}}}}});
});
</script>
<?php include __DIR__.'/../includes/footer.php'; ?>
