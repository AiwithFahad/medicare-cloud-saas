<?php declare(strict_types=1); require_once __DIR__.'/../includes/auth.php'; $pdo=db(); $user=require_role(['hospital_admin','doctor','receptionist','nurse','accountant','pharmacist','lab_technician']); $tid=require_tenant(); check_tenant_active($pdo);
$canManage = in_array($user['role'], ['hospital_admin'], true) || has_permission('doctors.manage');

if (is_post()) {
    verify_csrf();
    if (!$canManage) { flash('error','You do not have permission to manage doctors.'); redirect('doctors.php'); }
    $action=(string)($_POST['action'] ?? '');
    if ($action==='delete') {
        $id=(int)($_POST['id'] ?? 0);
        $s=$pdo->prepare('DELETE FROM doctors WHERE id=? AND tenant_id=?'); $s->execute([$id,$tid]);
        audit_log($pdo,'doctor.deleted','doctors',$id); log_activity($pdo,'Doctor deleted #'.$id);
        flash('success','Doctor deleted.'); redirect('doctors.php');
    }
    if ($action==='save') {
        $id=(int)($_POST['id'] ?? 0);
        $name=trim((string)($_POST['name'] ?? ''));
        $spec=trim((string)($_POST['specialization'] ?? '')) ?: null;
        $qual=trim((string)($_POST['qualification'] ?? '')) ?: null;
        $deptId=(int)($_POST['department_id'] ?? 0) ?: null;
        $phone=trim((string)($_POST['phone'] ?? '')) ?: null;
        $email=trim((string)($_POST['email'] ?? '')) ?: null;
        $fee=(float)($_POST['consultation_fee'] ?? 0);
        $schedule=trim((string)($_POST['schedule'] ?? '')) ?: null;
        $linkedUser=(int)($_POST['user_id'] ?? 0) ?: null;
        $status=(string)($_POST['status'] ?? 'active'); if(!in_array($status,['active','inactive'],true)) $status='active';
        if ($name==='') { flash('error','Doctor name is required.'); redirect('doctors.php'); }
        // Validate dept and user belong to tenant
        if ($deptId) { $c=$pdo->prepare('SELECT COUNT(*) FROM departments WHERE id=? AND tenant_id=?'); $c->execute([$deptId,$tid]); if(!(int)$c->fetchColumn()) $deptId=null; }
        if ($linkedUser) { $c=$pdo->prepare('SELECT COUNT(*) FROM users WHERE id=? AND tenant_id=?'); $c->execute([$linkedUser,$tid]); if(!(int)$c->fetchColumn()) $linkedUser=null; }
        if ($id>0) {
            $s=$pdo->prepare('UPDATE doctors SET name=?,specialization=?,qualification=?,department_id=?,phone=?,email=?,consultation_fee=?,schedule=?,user_id=?,status=? WHERE id=? AND tenant_id=?');
            $s->execute([$name,$spec,$qual,$deptId,$phone,$email,$fee,$schedule,$linkedUser,$status,$id,$tid]);
            audit_log($pdo,'doctor.updated','doctors',$id,$name); log_activity($pdo,'Doctor updated: '.$name); flash('success','Doctor updated.');
        } else {
            if (plan_limit_reached($pdo,$tid,'doctors')) { flash('error','Doctor limit reached for your current plan. Please upgrade to add more doctors.'); redirect('doctors.php'); }
            $s=$pdo->prepare('INSERT INTO doctors(tenant_id,user_id,department_id,name,specialization,qualification,phone,email,consultation_fee,schedule,status) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
            $s->execute([$tid,$linkedUser,$deptId,$name,$spec,$qual,$phone,$email,$fee,$schedule,$status]);
            $nid=(int)$pdo->lastInsertId();
            audit_log($pdo,'doctor.created','doctors',$nid,$name); log_activity($pdo,'Doctor added: '.$name); flash('success','Doctor added.');
        }
        redirect('doctors.php');
    }
    redirect('doctors.php');
}

$q=trim((string)($_GET['q'] ?? '')); $deptF=(int)($_GET['department_id'] ?? 0);
$where='d.tenant_id=?'; $params=[$tid];
if ($q!=='') { $where.=' AND (d.name LIKE ? OR d.specialization LIKE ?)'; $like='%'.$q.'%'; $params[]=$like; $params[]=$like; }
if ($deptF>0) { $where.=' AND d.department_id=?'; $params[]=$deptF; }
[$limit,$offset,$page]=page_params(15);
$s=$pdo->prepare("SELECT COUNT(*) FROM doctors d WHERE $where"); $s->execute($params); $total=(int)$s->fetchColumn();
$s=$pdo->prepare("SELECT d.*, dep.name AS dept_name, (SELECT COUNT(*) FROM appointments a WHERE a.doctor_id=d.id AND a.tenant_id=d.tenant_id) AS appt_count FROM doctors d LEFT JOIN departments dep ON dep.id=d.department_id AND dep.tenant_id=d.tenant_id WHERE $where ORDER BY d.name ASC LIMIT $limit OFFSET $offset");
$s->execute($params); $doctors=$s->fetchAll();

$s=$pdo->prepare('SELECT * FROM departments WHERE tenant_id=? ORDER BY name'); $s->execute([$tid]); $departments=$s->fetchAll();
$s=$pdo->prepare('SELECT u.id,u.name,u.email FROM users u JOIN roles r ON r.id=u.role_id WHERE u.tenant_id=? AND r.slug="doctor" AND u.status="active" ORDER BY u.name'); $s->execute([$tid]); $doctorUsers=$s->fetchAll();
$editDoc=null; if(!empty($_GET['edit']) && $canManage){ $eid=(int)$_GET['edit']; $s=$pdo->prepare('SELECT * FROM doctors WHERE id=? AND tenant_id=?'); $s->execute([$eid,$tid]); $editDoc=$s->fetch() ?: null; }

$pageTitle='Doctors'; $base='../'; include __DIR__.'/../includes/header.php';
?>
<div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
  <div><h5 class="mb-0"><?= $total ?> doctors</h5><span class="text-muted small">Medical staff directory</span></div>
  <?php if ($canManage): ?><button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#doctorModal"><i class="bi bi-plus-lg"></i> Add Doctor</button><?php endif; ?>
</div>
<form method="get" class="card card-body mb-3"><div class="row g-2">
  <div class="col-md-6"><input class="form-control" name="q" value="<?= e($q) ?>" placeholder="Search name or specialization…"></div>
  <div class="col-md-3"><select class="form-select" name="department_id"><option value="0">All departments</option><?php foreach($departments as $d): ?><option value="<?= (int)$d['id'] ?>" <?= $deptF===(int)$d['id']?'selected':'' ?>><?= e((string)$d['name']) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-3 d-flex gap-2"><button class="btn btn-primary flex-fill">Filter</button><a class="btn btn-outline-secondary" href="doctors.php">Reset</a></div>
</div></form>

<?php if (!$doctors): ?><div class="card"><div class="empty-state"><img src="../assets/img/empty.svg" alt=""><p>No doctors found.</p></div></div><?php else: ?>
<div class="row g-3">
<?php foreach($doctors as $d): ?><div class="col-md-6 col-xl-4"><div class="card h-100"><div class="card-body">
  <div class="d-flex gap-3 align-items-start"><span class="avatar-sm" style="width:52px;height:52px;font-size:1.1rem"><?= e(initials((string)$d['name'])) ?></span><div class="flex-grow-1"><h6 class="mb-0"><?= e((string)$d['name']) ?></h6><div class="text-muted small"><?= e((string)($d['specialization'] ?? 'General')) ?> · <?= e((string)($d['qualification'] ?? '')) ?></div><div class="mt-1"><?= status_badge((string)$d['status']) ?> <span class="chip ms-1"><?= e((string)($d['dept_name'] ?? 'No Dept')) ?></span></div></div></div>
  <dl class="row small mt-3 mb-2"><dt class="col-5 text-muted">Fee</dt><dd class="col-7"><?= e(money($d['consultation_fee'])) ?></dd><dt class="col-5 text-muted">Schedule</dt><dd class="col-7"><?= e((string)($d['schedule'] ?? '—')) ?></dd><dt class="col-5 text-muted">Phone</dt><dd class="col-7"><?= e((string)($d['phone'] ?? '—')) ?></dd><dt class="col-5 text-muted">Appointments</dt><dd class="col-7"><strong><?= (int)$d['appt_count'] ?></strong></dd></dl>
  <?php if ($canManage): ?><div class="d-flex gap-2"><a class="btn btn-sm btn-outline-secondary flex-fill" href="?edit=<?= (int)$d['id'] ?>">Edit</a><form method="post" data-confirm="Delete this doctor?"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>"><?= csrf_field() ?><button class="btn btn-sm btn-outline-danger">Delete</button></form></div><?php endif; ?>
</div></div></div><?php endforeach; ?>
</div>
<div class="d-flex justify-content-between align-items-center mt-3"><span class="text-muted small">Showing <?= count($doctors) ?> of <?= $total ?></span><?= pager_html($total,$limit,$page) ?></div>
<?php endif; ?>

<?php if ($canManage): ?>
<div class="modal fade" id="doctorModal" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content"><form method="post">
<?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= $editDoc ? (int)$editDoc['id'] : 0 ?>">
<div class="modal-header"><h5 class="modal-title"><?= $editDoc ? 'Edit Doctor' : 'Add Doctor' ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
<div class="modal-body"><div class="row g-3">
  <div class="col-md-6"><label class="form-label">Full Name *</label><input class="form-control" name="name" required value="<?= e((string)($editDoc['name'] ?? '')) ?>"></div>
  <div class="col-md-6"><label class="form-label">Specialization</label><input class="form-control" name="specialization" value="<?= e((string)($editDoc['specialization'] ?? '')) ?>"></div>
  <div class="col-md-6"><label class="form-label">Qualification</label><input class="form-control" name="qualification" value="<?= e((string)($editDoc['qualification'] ?? '')) ?>"></div>
  <div class="col-md-6"><label class="form-label">Department</label><select class="form-select" name="department_id"><option value="0">— None —</option><?php foreach($departments as $dep): ?><option value="<?= (int)$dep['id'] ?>" <?= (($editDoc['department_id'] ?? null)==$dep['id'])?'selected':'' ?>><?= e((string)$dep['name']) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-4"><label class="form-label">Phone</label><input class="form-control" name="phone" value="<?= e((string)($editDoc['phone'] ?? '')) ?>"></div>
  <div class="col-md-4"><label class="form-label">Email</label><input type="email" class="form-control" name="email" value="<?= e((string)($editDoc['email'] ?? '')) ?>"></div>
  <div class="col-md-4"><label class="form-label">Consultation Fee</label><input type="number" step="0.01" min="0" class="form-control" name="consultation_fee" value="<?= e((string)($editDoc['consultation_fee'] ?? '0')) ?>"></div>
  <div class="col-md-6"><label class="form-label">Schedule</label><input class="form-control" name="schedule" placeholder="Mon–Fri · 9 AM – 2 PM" value="<?= e((string)($editDoc['schedule'] ?? '')) ?>"></div>
  <div class="col-md-3"><label class="form-label">Linked User (optional)</label><select class="form-select" name="user_id"><option value="0">— None —</option><?php foreach($doctorUsers as $u): ?><option value="<?= (int)$u['id'] ?>" <?= (($editDoc['user_id'] ?? null)==$u['id'])?'selected':'' ?>><?= e((string)$u['name']) ?> (<?= e((string)$u['email']) ?>)</option><?php endforeach; ?></select></div>
  <div class="col-md-3"><label class="form-label">Status</label><select class="form-select" name="status"><?php foreach(['active','inactive'] as $st): ?><option value="<?= $st ?>" <?= (($editDoc['status'] ?? 'active')===$st)?'selected':'' ?>><?= ucfirst($st) ?></option><?php endforeach; ?></select></div>
</div></div>
<div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Save Doctor</button></div>
</form></div></div></div>
<?php if ($editDoc): ?><script>document.addEventListener('DOMContentLoaded',function(){new bootstrap.Modal(document.getElementById('doctorModal')).show();});</script><?php endif; ?>
<?php endif; ?>
<?php include __DIR__.'/../includes/footer.php'; ?>
