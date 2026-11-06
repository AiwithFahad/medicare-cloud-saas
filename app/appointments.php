<?php declare(strict_types=1); require_once __DIR__.'/../includes/auth.php'; $pdo=db(); $user=require_role(['hospital_admin','doctor','receptionist','nurse']); $tid=require_tenant(); check_tenant_active($pdo);
$canManage = has_permission('appointments.manage');

if (is_post()) {
    verify_csrf();
    $action=(string)($_POST['action'] ?? '');
    if ($action==='delete') {
        if (!$canManage) { flash('error','You do not have permission to manage appointments.'); redirect('appointments.php'); }
        $id=(int)($_POST['id'] ?? 0);
        $s=$pdo->prepare('DELETE FROM appointments WHERE id=? AND tenant_id=?'); $s->execute([$id,$tid]);
        audit_log($pdo,'appointment.deleted','appointments',$id); log_activity($pdo,'Appointment deleted #'.$id);
        flash('success','Appointment deleted.'); redirect('appointments.php');
    }
    if ($action==='status') {
        $id=(int)($_POST['id'] ?? 0); $status=(string)($_POST['status'] ?? '');
        if (in_array($status,['scheduled','confirmed','completed','cancelled','no_show'],true)) {
            $s=$pdo->prepare('UPDATE appointments SET status=? WHERE id=? AND tenant_id=?'); $s->execute([$status,$id,$tid]);
            audit_log($pdo,'appointment.'.$status,'appointments',$id); log_activity($pdo,'Appointment #'.$id.' → '.$status);
            flash('success','Appointment status updated.');
        }
        redirect('appointments.php?'.http_build_query(array_filter(['date'=>$_POST['return_date'] ?? null])));
    }
    if ($action==='save') {
        if (!$canManage) { flash('error','You do not have permission to book appointments.'); redirect('appointments.php'); }
        $id=(int)($_POST['id'] ?? 0);
        $patientId=(int)($_POST['patient_id'] ?? 0);
        $doctorId=(int)($_POST['doctor_id'] ?? 0);
        $deptId=(int)($_POST['department_id'] ?? 0) ?: null;
        $date=trim((string)($_POST['appointment_date'] ?? ''));
        $time=trim((string)($_POST['appointment_time'] ?? ''));
        $type=(string)($_POST['type'] ?? 'OPD'); if(!in_array($type,['OPD','Follow-up','Emergency','Teleconsult'],true)) $type='OPD';
        $reason=trim((string)($_POST['reason'] ?? '')) ?: null;
        $feeRaw=trim((string)($_POST['fee'] ?? ''));
        $status=(string)($_POST['status'] ?? 'scheduled'); if(!in_array($status,['scheduled','confirmed','completed','cancelled','no_show'],true)) $status='scheduled';
        if (!$patientId || !$doctorId || $date==='' || $time==='') { flash('error','Patient, doctor, date and time are required.'); redirect('appointments.php'); }
        // Validate tenant ownership
        $c=$pdo->prepare('SELECT COUNT(*) FROM patients WHERE id=? AND tenant_id=?'); $c->execute([$patientId,$tid]); if(!(int)$c->fetchColumn()){ flash('error','Invalid patient.'); redirect('appointments.php'); }
        $c=$pdo->prepare('SELECT department_id, consultation_fee FROM doctors WHERE id=? AND tenant_id=?'); $c->execute([$doctorId,$tid]); $doc=$c->fetch(); if(!$doc){ flash('error','Invalid doctor.'); redirect('appointments.php'); }
        if (!$deptId) $deptId = $doc['department_id'] ? (int)$doc['department_id'] : null;
        $fee = $feeRaw==='' ? (float)$doc['consultation_fee'] : (float)$feeRaw;
        if ($id>0) {
            $s=$pdo->prepare('UPDATE appointments SET patient_id=?,doctor_id=?,department_id=?,appointment_date=?,appointment_time=?,type=?,reason=?,status=?,fee=? WHERE id=? AND tenant_id=?');
            $s->execute([$patientId,$doctorId,$deptId,$date,$time,$type,$reason,$status,$fee,$id,$tid]);
            audit_log($pdo,'appointment.updated','appointments',$id); log_activity($pdo,'Appointment updated #'.$id); flash('success','Appointment updated.');
        } else {
            $s=$pdo->prepare('INSERT INTO appointments(tenant_id,patient_id,doctor_id,department_id,appointment_date,appointment_time,type,reason,status,fee,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
            $s->execute([$tid,$patientId,$doctorId,$deptId,$date,$time,$type,$reason,$status,$fee,(int)$user['id']]);
            $nid=(int)$pdo->lastInsertId();
            audit_log($pdo,'appointment.created','appointments',$nid); log_activity($pdo,'Appointment booked #'.$nid); flash('success','Appointment booked.');
        }
        redirect('appointments.php?date='.urlencode($date));
    }
    redirect('appointments.php');
}

$dateF = trim((string)($_GET['date'] ?? '')); if ($dateF==='') $dateF=date('Y-m-d');
$doctorF=(int)($_GET['doctor_id'] ?? 0); $statusF=(string)($_GET['status'] ?? ''); $q=trim((string)($_GET['q'] ?? ''));
$where='a.tenant_id=? AND a.appointment_date=?'; $params=[$tid,$dateF];
if ($doctorF>0){ $where.=' AND a.doctor_id=?'; $params[]=$doctorF; }
if (in_array($statusF,['scheduled','confirmed','completed','cancelled','no_show'],true)){ $where.=' AND a.status=?'; $params[]=$statusF; }
if ($q!==''){ $where.=' AND (p.name LIKE ? OR p.patient_code LIKE ?)'; $like='%'.$q.'%'; $params[]=$like; $params[]=$like; }
[$limit,$offset,$page]=page_params(20);
$s=$pdo->prepare("SELECT COUNT(*) FROM appointments a JOIN patients p ON p.id=a.patient_id WHERE $where"); $s->execute($params); $total=(int)$s->fetchColumn();
$s=$pdo->prepare("SELECT a.*, p.name AS patient_name, p.patient_code, doc.name AS doctor_name, dep.name AS dept_name FROM appointments a JOIN patients p ON p.id=a.patient_id JOIN doctors doc ON doc.id=a.doctor_id LEFT JOIN departments dep ON dep.id=a.department_id AND dep.tenant_id=a.tenant_id WHERE $where ORDER BY a.appointment_time ASC LIMIT $limit OFFSET $offset");
$s->execute($params); $appointments=$s->fetchAll();

// Queue summary for selected date
$s=$pdo->prepare('SELECT status, COUNT(*) AS c FROM appointments WHERE tenant_id=? AND appointment_date=? GROUP BY status'); $s->execute([$tid,$dateF]);
$queue=['scheduled'=>0,'confirmed'=>0,'completed'=>0,'cancelled'=>0,'no_show'=>0]; $queueTotal=0;
foreach($s->fetchAll() as $r){ $queue[$r['status']]=(int)$r['c']; $queueTotal+=(int)$r['c']; }

$s=$pdo->prepare('SELECT id,name,patient_code FROM patients WHERE tenant_id=? AND status="active" ORDER BY name'); $s->execute([$tid]); $patients=$s->fetchAll();
$s=$pdo->prepare('SELECT id,name,specialization,department_id,consultation_fee FROM doctors WHERE tenant_id=? AND status="active" ORDER BY name'); $s->execute([$tid]); $doctors=$s->fetchAll();
$s=$pdo->prepare('SELECT id,name FROM departments WHERE tenant_id=? ORDER BY name'); $s->execute([$tid]); $departments=$s->fetchAll();
$editAppt=null; if(!empty($_GET['edit']) && $canManage){ $eid=(int)$_GET['edit']; $s=$pdo->prepare('SELECT * FROM appointments WHERE id=? AND tenant_id=?'); $s->execute([$eid,$tid]); $editAppt=$s->fetch() ?: null; }

$pageTitle='Appointments'; $base='../'; include __DIR__.'/../includes/header.php';
?>
<div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
  <div><h5 class="mb-0">Appointments — <?= e(fmt_date($dateF)) ?></h5><span class="text-muted small"><?= $total ?> result(s) · <?= $queueTotal ?> on this date</span></div>
  <?php if ($canManage): ?><button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#apptModal"><i class="bi bi-plus-lg"></i> Book Appointment</button><?php endif; ?>
</div>
<div class="d-flex flex-wrap gap-2 mb-3">
  <span class="chip">Total: <strong><?= $queueTotal ?></strong></span>
  <span class="chip">Scheduled: <strong><?= $queue['scheduled'] ?></strong></span>
  <span class="chip">Confirmed: <strong><?= $queue['confirmed'] ?></strong></span>
  <span class="chip">Completed: <strong><?= $queue['completed'] ?></strong></span>
  <span class="chip">Cancelled: <strong><?= $queue['cancelled'] ?></strong></span>
  <span class="chip">No Show: <strong><?= $queue['no_show'] ?></strong></span>
  <a class="chip text-decoration-none" href="?date=<?= e(date('Y-m-d')) ?>">Today</a>
</div>
<form method="get" class="card card-body mb-3"><div class="row g-2">
  <div class="col-md-3"><input type="date" class="form-control" name="date" value="<?= e($dateF) ?>"></div>
  <div class="col-md-3"><input class="form-control" name="q" value="<?= e($q) ?>" placeholder="Search patient…"></div>
  <div class="col-md-2"><select class="form-select" name="doctor_id"><option value="0">All doctors</option><?php foreach($doctors as $d): ?><option value="<?= (int)$d['id'] ?>" <?= $doctorF===(int)$d['id']?'selected':'' ?>><?= e((string)$d['name']) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-2"><select class="form-select" name="status"><option value="">All statuses</option><?php foreach(['scheduled','confirmed','completed','cancelled','no_show'] as $st): ?><option value="<?= $st ?>" <?= $statusF===$st?'selected':'' ?>><?= ucwords(str_replace('_',' ',$st)) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-2 d-flex gap-2"><button class="btn btn-primary flex-fill">Filter</button><a class="btn btn-outline-secondary" href="appointments.php">Reset</a></div>
</div></form>

<div class="card"><div class="table-responsive"><table class="table"><thead><tr><th>Time</th><th>Patient</th><th>Doctor / Dept</th><th>Type</th><th>Fee</th><th>Status</th><th class="text-end">Actions</th></tr></thead><tbody>
<?php if (!$appointments): ?><tr><td colspan="7"><div class="empty-state"><img src="../assets/img/empty.svg" alt=""><p>No appointments for this date / filter.</p></div></td></tr><?php endif; ?>
<?php foreach($appointments as $a): ?><tr>
  <td><strong><?= e(date('h:i A', strtotime((string)$a['appointment_time']))) ?></strong><div class="text-muted small"><?= e(fmt_date((string)$a['appointment_date'])) ?></div></td>
  <td><span class="avatar-sm me-2"><?= e(initials((string)$a['patient_name'])) ?></span><strong><?= e((string)$a['patient_name']) ?></strong><div class="text-muted small"><?= e((string)$a['patient_code']) ?><?= !empty($a['reason']) ? ' · '.e((string)$a['reason']) : '' ?></div></td>
  <td><?= e((string)$a['doctor_name']) ?><div class="text-muted small"><?= e((string)($a['dept_name'] ?? '—')) ?></div></td>
  <td><span class="chip"><?= e((string)$a['type']) ?></span></td>
  <td><?= e(money($a['fee'])) ?></td>
  <td><?= status_badge((string)$a['status']) ?></td>
  <td class="text-end"><div class="d-flex gap-1 justify-content-end flex-wrap">
    <?php if ($canManage): ?>
    <form method="post" class="d-inline"><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><input type="hidden" name="return_date" value="<?= e($dateF) ?>"><?= csrf_field() ?><select name="status" class="form-select form-select-sm d-inline-block w-auto" onchange="this.form.submit()"><?php foreach(['scheduled','confirmed','completed','cancelled','no_show'] as $st): ?><option value="<?= $st ?>" <?= $a['status']===$st?'selected':'' ?>><?= ucwords(str_replace('_',' ',$st)) ?></option><?php endforeach; ?></select></form>
    <a class="btn btn-sm btn-outline-secondary" href="?edit=<?= (int)$a['id'] ?>&date=<?= e($dateF) ?>">Edit</a>
    <form method="post" class="d-inline" data-confirm="Delete this appointment?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><button class="btn btn-sm btn-outline-danger">Delete</button></form>
    <?php endif; ?>
  </div></td>
</tr><?php endforeach; ?>
</tbody></table></div><div class="card-footer d-flex justify-content-between align-items-center"><span class="text-muted small">Showing <?= count($appointments) ?> of <?= $total ?></span><?= pager_html($total,$limit,$page) ?></div></div>

<?php if ($canManage): ?>
<div class="modal fade" id="apptModal" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content"><form method="post" id="apptForm">
<?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= $editAppt ? (int)$editAppt['id'] : 0 ?>">
<div class="modal-header"><h5 class="modal-title"><?= $editAppt ? 'Edit Appointment' : 'Book Appointment' ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
<div class="modal-body"><div class="row g-3">
  <div class="col-md-6"><label class="form-label">Patient *</label><select class="form-select" name="patient_id" required><option value="">Select patient…</option><?php foreach($patients as $p): ?><option value="<?= (int)$p['id'] ?>" <?= (($editAppt['patient_id'] ?? null)==$p['id'])?'selected':'' ?>><?= e((string)$p['name']) ?> (<?= e((string)$p['patient_code']) ?>)</option><?php endforeach; ?></select></div>
  <div class="col-md-6"><label class="form-label">Doctor *</label><select class="form-select" name="doctor_id" id="doctorSelect" required><option value="">Select doctor…</option><?php foreach($doctors as $d): ?><option value="<?= (int)$d['id'] ?>" data-fee="<?= e((string)$d['consultation_fee']) ?>" data-dept="<?= (int)($d['department_id'] ?? 0) ?>" <?= (($editAppt['doctor_id'] ?? null)==$d['id'])?'selected':'' ?>><?= e((string)$d['name']) ?> — <?= e((string)($d['specialization'] ?? '')) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-6"><label class="form-label">Department</label><select class="form-select" name="department_id" id="deptSelect"><option value="0">Auto (doctor's department)</option><?php foreach($departments as $dep): ?><option value="<?= (int)$dep['id'] ?>" <?= (($editAppt['department_id'] ?? null)==$dep['id'])?'selected':'' ?>><?= e((string)$dep['name']) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-3"><label class="form-label">Date *</label><input type="date" class="form-control" name="appointment_date" required value="<?= e((string)($editAppt['appointment_date'] ?? $dateF)) ?>"></div>
  <div class="col-md-3"><label class="form-label">Time *</label><input type="time" class="form-control" name="appointment_time" required value="<?= e((string)($editAppt['appointment_time'] ?? '09:00')) ?>"></div>
  <div class="col-md-4"><label class="form-label">Type</label><select class="form-select" name="type"><?php foreach(['OPD','Follow-up','Emergency','Teleconsult'] as $t): ?><option value="<?= $t ?>" <?= (($editAppt['type'] ?? 'OPD')===$t)?'selected':'' ?>><?= $t ?></option><?php endforeach; ?></select></div>
  <div class="col-md-4"><label class="form-label">Fee</label><input type="number" step="0.01" min="0" class="form-control" name="fee" id="feeInput" placeholder="Defaults to doctor fee" value="<?= $editAppt ? e((string)$editAppt['fee']) : '' ?>"></div>
  <div class="col-md-4"><label class="form-label">Status</label><select class="form-select" name="status"><?php foreach(['scheduled','confirmed','completed','cancelled','no_show'] as $st): ?><option value="<?= $st ?>" <?= (($editAppt['status'] ?? 'scheduled')===$st)?'selected':'' ?>><?= ucwords(str_replace('_',' ',$st)) ?></option><?php endforeach; ?></select></div>
  <div class="col-12"><label class="form-label">Reason</label><input class="form-control" name="reason" value="<?= e((string)($editAppt['reason'] ?? '')) ?>"></div>
</div></div>
<div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Save Appointment</button></div>
</form></div></div></div>
<script>
document.addEventListener('DOMContentLoaded',function(){
  var ds=document.getElementById('doctorSelect'), fee=document.getElementById('feeInput'), dept=document.getElementById('deptSelect');
  if(ds){ ds.addEventListener('change',function(){ var o=ds.options[ds.selectedIndex]; if(o){ if(fee && !fee.value) fee.value=o.getAttribute('data-fee')||''; if(dept && dept.value==='0'){ var d=o.getAttribute('data-dept'); if(d && d!=='0') dept.value=d; } } }); }
  <?php if ($editAppt): ?>new bootstrap.Modal(document.getElementById('apptModal')).show();<?php endif; ?>
});
</script>
<?php endif; ?>
<?php include __DIR__.'/../includes/footer.php'; ?>
