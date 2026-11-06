<?php declare(strict_types=1); require_once __DIR__.'/../includes/auth.php'; $pdo=db(); $user=require_role(['hospital_admin','doctor','receptionist','nurse','accountant','pharmacist','lab_technician']); $tid=require_tenant(); check_tenant_active($pdo);
$canManage = in_array($user['role'], ['hospital_admin','receptionist'], true) || has_permission('patients.manage');

// CSV export
if (isset($_GET['export']) && $_GET['export'] === '1') {
    $s = $pdo->prepare('SELECT patient_code,name,gender,dob,blood_group,phone,email,address,emergency_contact,allergies,status,created_at FROM patients WHERE tenant_id=? ORDER BY id DESC');
    $s->execute([$tid]);
    export_csv($s->fetchAll(), 'patients.csv');
}

// POST handlers
if (is_post()) {
    verify_csrf();
    if (!$canManage) { flash('error','You do not have permission to manage patients.'); redirect('patients.php'); }
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $s = $pdo->prepare('DELETE FROM patients WHERE id=? AND tenant_id=?'); $s->execute([$id,$tid]);
        audit_log($pdo,'patient.deleted','patients',$id); log_activity($pdo,'Patient deleted #'.$id);
        flash('success','Patient deleted.'); redirect('patients.php');
    }
    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $gender = (string)($_POST['gender'] ?? 'other'); if (!in_array($gender,['male','female','other'],true)) $gender='other';
        $dob = trim((string)($_POST['dob'] ?? '')) ?: null;
        $blood = trim((string)($_POST['blood_group'] ?? '')) ?: null;
        $phone = trim((string)($_POST['phone'] ?? '')) ?: null;
        $email = trim((string)($_POST['email'] ?? '')) ?: null;
        $address = trim((string)($_POST['address'] ?? '')) ?: null;
        $emergency = trim((string)($_POST['emergency_contact'] ?? '')) ?: null;
        $allergies = trim((string)($_POST['allergies'] ?? '')) ?: null;
        $status = (string)($_POST['status'] ?? 'active'); if (!in_array($status,['active','inactive'],true)) $status='active';
        if ($name === '') { flash('error','Patient name is required.'); redirect('patients.php'); }
        if ($id > 0) {
            $s = $pdo->prepare('UPDATE patients SET name=?,gender=?,dob=?,blood_group=?,phone=?,email=?,address=?,emergency_contact=?,allergies=?,status=? WHERE id=? AND tenant_id=?');
            $s->execute([$name,$gender,$dob,$blood,$phone,$email,$address,$emergency,$allergies,$status,$id,$tid]);
            audit_log($pdo,'patient.updated','patients',$id,$name); log_activity($pdo,'Patient updated: '.$name);
            flash('success','Patient updated.');
        } else {
            if (plan_limit_reached($pdo,$tid,'patients')) { flash('error','Patient limit reached for your current plan. Please upgrade to add more patients.'); redirect('patients.php'); }
            // Temporary code then fix with real id for uniqueness
            $s = $pdo->prepare('INSERT INTO patients(tenant_id,patient_code,name,gender,dob,blood_group,phone,email,address,emergency_contact,allergies,status) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');
            $tmpCode = 'PAT-TMP-'.bin2hex(random_bytes(4));
            $s->execute([$tid,$tmpCode,$name,$gender,$dob,$blood,$phone,$email,$address,$emergency,$allergies,$status]);
            $newId = (int)$pdo->lastInsertId();
            $code = 'PAT-'.str_pad((string)$newId,4,'0',STR_PAD_LEFT);
            $u = $pdo->prepare('UPDATE patients SET patient_code=? WHERE id=? AND tenant_id=?'); $u->execute([$code,$newId,$tid]);
            audit_log($pdo,'patient.created','patients',$newId,$name.' '.$code); log_activity($pdo,'Patient registered: '.$name.' ('.$code.')');
            flash('success','Patient created with code '.$code.'.');
        }
        redirect('patients.php');
    }
    redirect('patients.php');
}

$q = trim((string)($_GET['q'] ?? ''));
$genderF = (string)($_GET['gender'] ?? '');
$statusF = (string)($_GET['status'] ?? '');
$where = 'tenant_id=?'; $params = [$tid];
if ($q !== '') { $where .= ' AND (name LIKE ? OR patient_code LIKE ? OR phone LIKE ?)'; $like='%'.$q.'%'; $params[]=$like; $params[]=$like; $params[]=$like; }
if (in_array($genderF,['male','female','other'],true)) { $where .= ' AND gender=?'; $params[]=$genderF; }
if (in_array($statusF,['active','inactive'],true)) { $where .= ' AND status=?'; $params[]=$statusF; }

[$limit,$offset,$page] = page_params(15);
$s = $pdo->prepare("SELECT COUNT(*) FROM patients WHERE $where"); $s->execute($params); $total=(int)$s->fetchColumn();
$s = $pdo->prepare("SELECT * FROM patients WHERE $where ORDER BY id DESC LIMIT $limit OFFSET $offset"); $s->execute($params); $patients=$s->fetchAll();

// View detail
$viewPatient = null; $viewApptCount=0; $viewRecordCount=0; $viewInvoiceCount=0;
if (!empty($_GET['view'])) {
    $vid=(int)$_GET['view'];
    $s=$pdo->prepare('SELECT * FROM patients WHERE id=? AND tenant_id=?'); $s->execute([$vid,$tid]); $viewPatient=$s->fetch() ?: null;
    if ($viewPatient) {
        $c=$pdo->prepare('SELECT COUNT(*) FROM appointments WHERE patient_id=? AND tenant_id=?'); $c->execute([$vid,$tid]); $viewApptCount=(int)$c->fetchColumn();
        $c=$pdo->prepare('SELECT COUNT(*) FROM medical_records WHERE patient_id=? AND tenant_id=?'); $c->execute([$vid,$tid]); $viewRecordCount=(int)$c->fetchColumn();
        $c=$pdo->prepare('SELECT COUNT(*) FROM patient_invoices WHERE patient_id=? AND tenant_id=?'); $c->execute([$vid,$tid]); $viewInvoiceCount=(int)$c->fetchColumn();
    }
}
$editPatient=null;
if (!empty($_GET['edit']) && $canManage) { $eid=(int)$_GET['edit']; $s=$pdo->prepare('SELECT * FROM patients WHERE id=? AND tenant_id=?'); $s->execute([$eid,$tid]); $editPatient=$s->fetch() ?: null; }

$pageTitle='Patients'; $base='../'; include __DIR__.'/../includes/header.php';
?>
<div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
  <div><h5 class="mb-0"><?= $total ?> patients</h5><span class="text-muted small">Search, register and manage patient records</span></div>
  <div class="d-flex gap-2"><a class="btn btn-outline-secondary" href="?export=1"><i class="bi bi-download"></i> Export CSV</a>
  <?php if ($canManage): ?><button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#patientModal"><i class="bi bi-plus-lg"></i> Add Patient</button><?php endif; ?></div>
</div>

<form method="get" class="card card-body mb-3"><div class="row g-2">
  <div class="col-md-5"><input class="form-control" name="q" value="<?= e($q) ?>" placeholder="Search name, code or phone…"></div>
  <div class="col-md-2"><select class="form-select" name="gender"><option value="">All genders</option><?php foreach(['male','female','other'] as $g): ?><option value="<?= $g ?>" <?= $genderF===$g?'selected':'' ?>><?= ucfirst($g) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-2"><select class="form-select" name="status"><option value="">All statuses</option><?php foreach(['active','inactive'] as $st): ?><option value="<?= $st ?>" <?= $statusF===$st?'selected':'' ?>><?= ucfirst($st) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-3 d-flex gap-2"><button class="btn btn-primary flex-fill">Filter</button><a class="btn btn-outline-secondary" href="patients.php">Reset</a></div>
</div></form>

<?php if ($viewPatient): ?>
<div class="card mb-3"><div class="card-header d-flex justify-content-between"><span>Patient Details — <?= e((string)$viewPatient['patient_code']) ?></span><a href="patients.php" class="btn btn-sm btn-outline-secondary">Close</a></div><div class="card-body">
  <div class="d-flex gap-3 align-items-center mb-3"><span class="avatar-sm" style="width:52px;height:52px;font-size:1.1rem"><?= e(initials((string)$viewPatient['name'])) ?></span><div><h5 class="mb-0"><?= e((string)$viewPatient['name']) ?></h5><span class="text-muted small"><?= e((string)$viewPatient['patient_code']) ?> · <?= e(ucfirst((string)$viewPatient['gender'])) ?> · <?= e((string)($viewPatient['blood_group'] ?? '—')) ?></span> <?= status_badge((string)$viewPatient['status']) ?></div></div>
  <div class="row g-3">
    <div class="col-md-3"><div class="chip w-100 text-center py-2">Appointments<br><strong><?= $viewApptCount ?></strong></div></div>
    <div class="col-md-3"><div class="chip w-100 text-center py-2">Medical Records<br><strong><?= $viewRecordCount ?></strong></div></div>
    <div class="col-md-3"><div class="chip w-100 text-center py-2">Invoices<br><strong><?= $viewInvoiceCount ?></strong></div></div>
    <div class="col-md-3"><div class="chip w-100 text-center py-2">DOB<br><strong><?= e(fmt_date($viewPatient['dob'])) ?></strong></div></div>
  </div>
  <dl class="row mt-3 mb-0">
    <dt class="col-sm-3">Phone</dt><dd class="col-sm-9"><?= e((string)($viewPatient['phone'] ?? '—')) ?></dd>
    <dt class="col-sm-3">Email</dt><dd class="col-sm-9"><?= e((string)($viewPatient['email'] ?? '—')) ?></dd>
    <dt class="col-sm-3">Address</dt><dd class="col-sm-9"><?= e((string)($viewPatient['address'] ?? '—')) ?></dd>
    <dt class="col-sm-3">Emergency Contact</dt><dd class="col-sm-9"><?= e((string)($viewPatient['emergency_contact'] ?? '—')) ?></dd>
    <dt class="col-sm-3">Allergies</dt><dd class="col-sm-9"><?= e((string)($viewPatient['allergies'] ?? 'None recorded')) ?></dd>
  </dl>
</div></div>
<?php endif; ?>

<div class="card"><div class="table-responsive"><table class="table"><thead><tr><th>Patient</th><th>Gender / Blood</th><th>Phone</th><th>Status</th><th>Registered</th><th class="text-end">Actions</th></tr></thead><tbody>
<?php if (!$patients): ?><tr><td colspan="6"><div class="empty-state"><img src="../assets/img/empty.svg" alt=""><p>No patients found.</p></div></td></tr><?php endif; ?>
<?php foreach ($patients as $p): ?><tr>
  <td><span class="avatar-sm me-2"><?= e(initials((string)$p['name'])) ?></span><strong><?= e((string)$p['name']) ?></strong><div class="text-muted small"><?= e((string)$p['patient_code']) ?> · <?= e((string)($p['email'] ?? '')) ?></div></td>
  <td><?= e(ucfirst((string)$p['gender'])) ?> · <?= e((string)($p['blood_group'] ?? '—')) ?></td>
  <td><?= e((string)($p['phone'] ?? '—')) ?></td>
  <td><?= status_badge((string)$p['status']) ?></td>
  <td><?= e(fmt_date((string)$p['created_at'])) ?></td>
  <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="?view=<?= (int)$p['id'] ?>">View</a>
  <?php if ($canManage): ?><a class="btn btn-sm btn-outline-secondary" href="?edit=<?= (int)$p['id'] ?>">Edit</a>
  <form method="post" class="d-inline" data-confirm="Delete this patient? This cannot be undone."><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button class="btn btn-sm btn-outline-danger">Delete</button></form><?php endif; ?></td>
</tr><?php endforeach; ?>
</tbody></table></div><div class="card-footer d-flex justify-content-between align-items-center"><span class="text-muted small">Showing <?= count($patients) ?> of <?= $total ?></span><?= pager_html($total, $limit, $page) ?></div></div>

<?php if ($canManage): ?>
<div class="modal fade" id="patientModal" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content"><form method="post">
<?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= $editPatient ? (int)$editPatient['id'] : 0 ?>">
<div class="modal-header"><h5 class="modal-title"><?= $editPatient ? 'Edit Patient' : 'Add Patient' ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
<div class="modal-body"><div class="row g-3">
  <div class="col-md-6"><label class="form-label">Full Name *</label><input class="form-control" name="name" required value="<?= e((string)($editPatient['name'] ?? '')) ?>"></div>
  <div class="col-md-3"><label class="form-label">Gender</label><select class="form-select" name="gender"><?php foreach(['male','female','other'] as $g): ?><option value="<?= $g ?>" <?= (($editPatient['gender'] ?? 'other')===$g)?'selected':'' ?>><?= ucfirst($g) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-3"><label class="form-label">Status</label><select class="form-select" name="status"><?php foreach(['active','inactive'] as $st): ?><option value="<?= $st ?>" <?= (($editPatient['status'] ?? 'active')===$st)?'selected':'' ?>><?= ucfirst($st) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-4"><label class="form-label">Date of Birth</label><input type="date" class="form-control" name="dob" value="<?= e((string)($editPatient['dob'] ?? '')) ?>"></div>
  <div class="col-md-4"><label class="form-label">Blood Group</label><select class="form-select" name="blood_group"><option value="">—</option><?php foreach(['A+','A-','B+','B-','AB+','AB-','O+','O-'] as $bg): ?><option value="<?= $bg ?>" <?= (($editPatient['blood_group'] ?? '')===$bg)?'selected':'' ?>><?= $bg ?></option><?php endforeach; ?></select></div>
  <div class="col-md-4"><label class="form-label">Phone</label><input class="form-control" name="phone" value="<?= e((string)($editPatient['phone'] ?? '')) ?>"></div>
  <div class="col-md-6"><label class="form-label">Email</label><input type="email" class="form-control" name="email" value="<?= e((string)($editPatient['email'] ?? '')) ?>"></div>
  <div class="col-md-6"><label class="form-label">Emergency Contact</label><input class="form-control" name="emergency_contact" value="<?= e((string)($editPatient['emergency_contact'] ?? '')) ?>"></div>
  <div class="col-12"><label class="form-label">Address</label><input class="form-control" name="address" value="<?= e((string)($editPatient['address'] ?? '')) ?>"></div>
  <div class="col-12"><label class="form-label">Allergies</label><textarea class="form-control" name="allergies" rows="2"><?= e((string)($editPatient['allergies'] ?? '')) ?></textarea></div>
  <?php if (!$editPatient): ?><div class="col-12"><span class="text-muted small">Patient code will be auto-generated as PAT-XXXX.</span></div><?php else: ?><div class="col-12"><span class="chip">Code: <?= e((string)$editPatient['patient_code']) ?></span></div><?php endif; ?>
</div></div>
<div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Save Patient</button></div>
</form></div></div></div>
<?php if ($editPatient): ?><script>document.addEventListener('DOMContentLoaded',function(){new bootstrap.Modal(document.getElementById('patientModal')).show();});</script><?php endif; ?>
<?php endif; ?>
<?php include __DIR__.'/../includes/footer.php'; ?>
