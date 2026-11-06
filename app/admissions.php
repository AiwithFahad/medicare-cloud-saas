<?php declare(strict_types=1); require_once __DIR__.'/../includes/auth.php'; $pdo=db(); $user=require_role(['hospital_admin','receptionist','nurse','doctor']); $tid=require_tenant(); check_tenant_active($pdo);
$canManage = has_permission('admissions.manage') || $user['role']==='hospital_admin';
$tab = (string)($_GET['tab'] ?? 'current'); if (!in_array($tab,['current','history'],true)) $tab='current';

if (is_post()) {
    verify_csrf();
    if (!$canManage) { flash('error','You do not have permission to manage admissions.'); redirect('admissions.php'); }
    $action=(string)($_POST['action'] ?? '');
    if ($action==='admit') {
        $patientId=(int)($_POST['patient_id'] ?? 0);
        $doctorId=(int)($_POST['doctor_id'] ?? 0) ?: null;
        $bedId=(int)($_POST['bed_id'] ?? 0);
        $reason=trim((string)($_POST['reason'] ?? '')) ?: null;
        $notes=trim((string)($_POST['notes'] ?? '')) ?: null;
        if (!$patientId || !$bedId) { flash('error','Patient and bed are required.'); redirect('admissions.php'); }
        try {
            $pdo->beginTransaction();
            // Lock bed row and verify it is available + belongs to tenant
            $s=$pdo->prepare('SELECT * FROM beds WHERE id=? AND tenant_id=? FOR UPDATE'); $s->execute([$bedId,$tid]); $bed=$s->fetch();
            if (!$bed || $bed['status']!=='available') { $pdo->rollBack(); flash('error','Selected bed is no longer available.'); redirect('admissions.php'); }
            $c=$pdo->prepare('SELECT COUNT(*) FROM patients WHERE id=? AND tenant_id=?'); $c->execute([$patientId,$tid]); if(!(int)$c->fetchColumn()){ $pdo->rollBack(); flash('error','Invalid patient.'); redirect('admissions.php'); }
            if ($doctorId) { $c=$pdo->prepare('SELECT COUNT(*) FROM doctors WHERE id=? AND tenant_id=?'); $c->execute([$doctorId,$tid]); if(!(int)$c->fetchColumn()) $doctorId=null; }
            $wardId=(int)$bed['ward_id'];
            $ins=$pdo->prepare('INSERT INTO admissions(tenant_id,patient_id,doctor_id,ward_id,bed_id,reason,notes,status) VALUES(?,?,?,?,?,?,?,"admitted")');
            $ins->execute([$tid,$patientId,$doctorId,$wardId,$bedId,$reason,$notes]);
            $nid=(int)$pdo->lastInsertId();
            $upd=$pdo->prepare('UPDATE beds SET status="occupied", current_patient_id=? WHERE id=? AND tenant_id=?'); $upd->execute([$patientId,$bedId,$tid]);
            $pdo->commit();
            audit_log($pdo,'admission.created','admissions',$nid,'Bed '.$bedId); log_activity($pdo,'Patient admitted (admission #'.$nid.')');
            flash('success','Patient admitted successfully.');
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash('error','Could not admit patient. Please try again.');
        }
        redirect('admissions.php');
    }
    if ($action==='discharge') {
        $id=(int)($_POST['id'] ?? 0);
        try {
            $pdo->beginTransaction();
            $s=$pdo->prepare('SELECT * FROM admissions WHERE id=? AND tenant_id=? AND status="admitted" FOR UPDATE'); $s->execute([$id,$tid]); $adm=$s->fetch();
            if (!$adm) { $pdo->rollBack(); flash('error','Admission not found or already discharged.'); redirect('admissions.php'); }
            $u=$pdo->prepare('UPDATE admissions SET discharge_date=NOW(), status="discharged" WHERE id=? AND tenant_id=?'); $u->execute([$id,$tid]);
            $u2=$pdo->prepare('UPDATE beds SET status="available", current_patient_id=NULL WHERE id=? AND tenant_id=?'); $u2->execute([(int)$adm['bed_id'],$tid]);
            $pdo->commit();
            audit_log($pdo,'admission.discharged','admissions',$id); log_activity($pdo,'Patient discharged (admission #'.$id.')');
            flash('success','Patient discharged and bed freed.');
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash('error','Could not discharge patient.');
        }
        redirect('admissions.php?tab=history');
    }
    if ($action==='transfer') {
        $id=(int)($_POST['id'] ?? 0);
        $u=$pdo->prepare('UPDATE admissions SET status="transferred", discharge_date=NOW() WHERE id=? AND tenant_id=? AND status="admitted"'); $u->execute([$id,$tid]);
        // Free bed if admission existed
        $s=$pdo->prepare('SELECT bed_id FROM admissions WHERE id=? AND tenant_id=?'); $s->execute([$id,$tid]); $bedId=$s->fetchColumn();
        if ($bedId) { $f=$pdo->prepare('UPDATE beds SET status="available", current_patient_id=NULL WHERE id=? AND tenant_id=?'); $f->execute([(int)$bedId,$tid]); }
        audit_log($pdo,'admission.transferred','admissions',$id); log_activity($pdo,'Admission #'.$id.' transferred');
        flash('success','Admission marked as transferred.'); redirect('admissions.php');
    }
    redirect('admissions.php');
}

$q=trim((string)($_GET['q'] ?? ''));
$statusFilter = $tab==='history' ? ['discharged','transferred'] : ['admitted'];
$placeholders = implode(',', array_fill(0, count($statusFilter), '?'));
$where="a.tenant_id=? AND a.status IN ($placeholders)"; $params=array_merge([$tid], $statusFilter);
if ($q!==''){ $where.=' AND (p.name LIKE ? OR p.patient_code LIKE ?)'; $like='%'.$q.'%'; $params[]=$like; $params[]=$like; }
[$limit,$offset,$page]=page_params(15);
$s=$pdo->prepare("SELECT COUNT(*) FROM admissions a JOIN patients p ON p.id=a.patient_id WHERE $where"); $s->execute($params); $total=(int)$s->fetchColumn();
$s=$pdo->prepare("SELECT a.*, p.name AS patient_name, p.patient_code, doc.name AS doctor_name, w.name AS ward_name, b.bed_number FROM admissions a JOIN patients p ON p.id=a.patient_id LEFT JOIN doctors doc ON doc.id=a.doctor_id LEFT JOIN wards w ON w.id=a.ward_id LEFT JOIN beds b ON b.id=a.bed_id WHERE $where ORDER BY a.admission_date DESC LIMIT $limit OFFSET $offset");
$s->execute($params); $admissions=$s->fetchAll();

// Form data
$s=$pdo->prepare('SELECT id,name,patient_code FROM patients WHERE tenant_id=? AND status="active" ORDER BY name'); $s->execute([$tid]); $patients=$s->fetchAll();
$s=$pdo->prepare('SELECT id,name FROM doctors WHERE tenant_id=? AND status="active" ORDER BY name'); $s->execute([$tid]); $doctors=$s->fetchAll();
$s=$pdo->prepare('SELECT b.id, b.bed_number, w.name AS ward_name FROM beds b JOIN wards w ON w.id=b.ward_id WHERE b.tenant_id=? AND b.status="available" ORDER BY w.name, b.bed_number'); $s->execute([$tid]); $availBeds=$s->fetchAll();
$s=$pdo->prepare('SELECT COUNT(*) FROM admissions WHERE tenant_id=? AND status="admitted"'); $s->execute([$tid]); $currentCount=(int)$s->fetchColumn();

$pageTitle='Admissions'; $base='../'; include __DIR__.'/../includes/header.php';
?>
<div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
  <div><h5 class="mb-0">Admissions</h5><span class="text-muted small"><?= $currentCount ?> currently admitted · <?= count($availBeds) ?> beds available</span></div>
  <?php if ($canManage): ?><button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#admitModal"><i class="bi bi-plus-lg"></i> Admit Patient</button><?php endif; ?>
</div>
<ul class="nav nav-tabs mb-3">
  <li class="nav-item"><a class="nav-link <?= $tab==='current'?'active':'' ?>" href="?tab=current">Current Admissions</a></li>
  <li class="nav-item"><a class="nav-link <?= $tab==='history'?'active':'' ?>" href="?tab=history">History (Discharged)</a></li>
</ul>
<form method="get" class="card card-body mb-3"><input type="hidden" name="tab" value="<?= e($tab) ?>"><div class="row g-2"><div class="col-md-9"><input class="form-control" name="q" value="<?= e($q) ?>" placeholder="Search patient name or code…"></div><div class="col-md-3 d-flex gap-2"><button class="btn btn-primary flex-fill">Search</button><a class="btn btn-outline-secondary" href="admissions.php?tab=<?= e($tab) ?>">Reset</a></div></div></form>

<div class="card"><div class="table-responsive"><table class="table"><thead><tr><th>Patient</th><th>Bed / Ward</th><th>Doctor</th><th>Since</th><th>Reason</th><th>Status</th><?php if($tab==='history'): ?><th>Discharged</th><?php endif; ?><th class="text-end">Actions</th></tr></thead><tbody>
<?php if (!$admissions): ?><tr><td colspan="<?= $tab==='history'?8:7 ?>"><div class="empty-state"><img src="../assets/img/empty.svg" alt=""><p><?= $tab==='current' ? 'No patients currently admitted.' : 'No discharge history yet.' ?></p></div></td></tr><?php endif; ?>
<?php foreach($admissions as $a): ?><tr>
  <td><span class="avatar-sm me-2"><?= e(initials((string)$a['patient_name'])) ?></span><strong><?= e((string)$a['patient_name']) ?></strong><div class="text-muted small"><?= e((string)$a['patient_code']) ?></div></td>
  <td><strong><?= e((string)($a['bed_number'] ?? '—')) ?></strong><div class="text-muted small"><?= e((string)($a['ward_name'] ?? '')) ?></div></td>
  <td><?= e((string)($a['doctor_name'] ?? '—')) ?></td>
  <td><?= e(fmt_datetime((string)$a['admission_date'])) ?><?php $days=(int)floor((time()-strtotime((string)$a['admission_date']))/86400); if($a['status']==='admitted'): ?><div class="text-muted small"><?= $days ?> day(s)</div><?php endif; ?></td>
  <td><?= e((string)($a['reason'] ?? '—')) ?></td>
  <td><?= status_badge((string)$a['status']) ?></td>
  <?php if($tab==='history'): ?><td><?= !empty($a['discharge_date']) ? e(fmt_datetime((string)$a['discharge_date'])) : '—' ?></td><?php endif; ?>
  <td class="text-end"><?php if ($canManage && $a['status']==='admitted'): ?>
    <form method="post" class="d-inline" data-confirm="Discharge this patient and free the bed?"><?= csrf_field() ?><input type="hidden" name="action" value="discharge"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><button class="btn btn-sm btn-success">Discharge</button></form>
  <?php endif; ?></td>
</tr><?php endforeach; ?>
</tbody></table></div><div class="card-footer d-flex justify-content-between align-items-center"><span class="text-muted small">Showing <?= count($admissions) ?> of <?= $total ?></span><?= pager_html($total,$limit,$page) ?></div></div>

<?php if ($canManage): ?>
<div class="modal fade" id="admitModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><form method="post">
<?= csrf_field() ?><input type="hidden" name="action" value="admit">
<div class="modal-header"><h5 class="modal-title">Admit Patient</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
<div class="modal-body"><div class="row g-3">
  <div class="col-12"><label class="form-label">Patient *</label><select class="form-select" name="patient_id" required><option value="">Select patient…</option><?php foreach($patients as $p): ?><option value="<?= (int)$p['id'] ?>"><?= e((string)$p['name']) ?> (<?= e((string)$p['patient_code']) ?>)</option><?php endforeach; ?></select></div>
  <div class="col-12"><label class="form-label">Attending Doctor</label><select class="form-select" name="doctor_id"><option value="0">— None —</option><?php foreach($doctors as $d): ?><option value="<?= (int)$d['id'] ?>"><?= e((string)$d['name']) ?></option><?php endforeach; ?></select></div>
  <div class="col-12"><label class="form-label">Available Bed *</label><select class="form-select" name="bed_id" required><option value="">Select an available bed…</option><?php foreach($availBeds as $fb): ?><option value="<?= (int)$fb['id'] ?>"><?= e((string)$fb['ward_name']) ?> — Bed <?= e((string)$fb['bed_number']) ?></option><?php endforeach; ?></select><?php if (!$availBeds): ?><div class="text-danger small mt-1">No beds currently available.</div><?php endif; ?></div>
  <div class="col-12"><label class="form-label">Reason for Admission</label><input class="form-control" name="reason" placeholder="e.g. Cardiac observation"></div>
  <div class="col-12"><label class="form-label">Notes</label><textarea class="form-control" name="notes" rows="2"></textarea></div>
</div></div>
<div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" <?= !$availBeds ? 'disabled' : '' ?>>Admit Patient</button></div>
</form></div></div></div>
<?php endif; ?>
<?php include __DIR__.'/../includes/footer.php'; ?>
