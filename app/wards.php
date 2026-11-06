<?php declare(strict_types=1); require_once __DIR__.'/../includes/auth.php'; $pdo=db(); $user=require_role(['hospital_admin','receptionist','nurse','doctor']); $tid=require_tenant(); check_tenant_active($pdo);
$canManage = has_permission('wards.manage') || $user['role']==='hospital_admin';
$wardTypes = ['General','ICU','CCU','Pediatric','Maternity','Private'];
$bedStatuses = ['available','occupied','reserved','maintenance'];

if (is_post()) {
    verify_csrf();
    $action=(string)($_POST['action'] ?? '');
    if ($action==='ward_save') {
        if (!$canManage) { flash('error','You do not have permission to manage wards.'); redirect('wards.php'); }
        $id=(int)($_POST['id'] ?? 0);
        $name=trim((string)($_POST['name'] ?? ''));
        $type=(string)($_POST['type'] ?? 'General'); if(!in_array($type,$wardTypes,true)) $type='General';
        $floor=trim((string)($_POST['floor'] ?? '')) ?: null;
        if ($name==='') { flash('error','Ward name is required.'); redirect('wards.php'); }
        if ($id>0) {
            $s=$pdo->prepare('UPDATE wards SET name=?,type=?,floor=? WHERE id=? AND tenant_id=?'); $s->execute([$name,$type,$floor,$id,$tid]);
            audit_log($pdo,'ward.updated','wards',$id,$name); log_activity($pdo,'Ward updated: '.$name); flash('success','Ward updated.');
        } else {
            $s=$pdo->prepare('INSERT INTO wards(tenant_id,name,type,floor) VALUES(?,?,?,?)'); $s->execute([$tid,$name,$type,$floor]);
            $nid=(int)$pdo->lastInsertId();
            audit_log($pdo,'ward.created','wards',$nid,$name); log_activity($pdo,'Ward created: '.$name); flash('success','Ward created.');
        }
        redirect('wards.php');
    }
    if ($action==='ward_delete') {
        if (!$canManage) { flash('error','You do not have permission to manage wards.'); redirect('wards.php'); }
        $id=(int)($_POST['id'] ?? 0);
        $s=$pdo->prepare('DELETE FROM wards WHERE id=? AND tenant_id=?'); $s->execute([$id,$tid]);
        audit_log($pdo,'ward.deleted','wards',$id); log_activity($pdo,'Ward deleted #'.$id); flash('success','Ward deleted (its beds were removed too).'); redirect('wards.php');
    }
    if ($action==='bed_add') {
        if (!$canManage) { flash('error','You do not have permission to manage beds.'); redirect('wards.php'); }
        $wardId=(int)($_POST['ward_id'] ?? 0); $bedNo=trim((string)($_POST['bed_number'] ?? ''));
        if (!$wardId || $bedNo==='') { flash('error','Ward and bed number are required.'); redirect('wards.php'); }
        $c=$pdo->prepare('SELECT COUNT(*) FROM wards WHERE id=? AND tenant_id=?'); $c->execute([$wardId,$tid]); if(!(int)$c->fetchColumn()){ flash('error','Invalid ward.'); redirect('wards.php'); }
        try {
            $s=$pdo->prepare('INSERT INTO beds(tenant_id,ward_id,bed_number,status) VALUES(?,?,?,"available")'); $s->execute([$tid,$wardId,$bedNo]);
            $nid=(int)$pdo->lastInsertId();
            audit_log($pdo,'bed.created','beds',$nid,$bedNo); log_activity($pdo,'Bed added: '.$bedNo); flash('success','Bed added.');
        } catch (PDOException $ex) { flash('error','That bed number already exists in this ward.'); }
        redirect('wards.php');
    }
    if ($action==='bed_status') {
        $bedId=(int)($_POST['bed_id'] ?? 0); $status=(string)($_POST['status'] ?? '');
        $patientId=(int)($_POST['patient_id'] ?? 0) ?: null;
        if (!in_array($status,$bedStatuses,true)) { flash('error','Invalid bed status.'); redirect('wards.php'); }
        if ($status==='occupied') {
            if (!$patientId) { flash('error','Please choose a patient when marking a bed occupied.'); redirect('wards.php'); }
            $c=$pdo->prepare('SELECT COUNT(*) FROM patients WHERE id=? AND tenant_id=?'); $c->execute([$patientId,$tid]); if(!(int)$c->fetchColumn()){ flash('error','Invalid patient.'); redirect('wards.php'); }
        } else {
            $patientId=null;
        }
        $s=$pdo->prepare('UPDATE beds SET status=?, current_patient_id=? WHERE id=? AND tenant_id=?'); $s->execute([$status,$patientId,$bedId,$tid]);
        audit_log($pdo,'bed.status_'.$status,'beds',$bedId); log_activity($pdo,'Bed #'.$bedId.' → '.$status);
        flash('success','Bed status updated.'); redirect('wards.php');
    }
    if ($action==='bed_delete') {
        if (!$canManage) { flash('error','You do not have permission to manage beds.'); redirect('wards.php'); }
        $bedId=(int)($_POST['bed_id'] ?? 0);
        $s=$pdo->prepare('DELETE FROM beds WHERE id=? AND tenant_id=?'); $s->execute([$bedId,$tid]);
        audit_log($pdo,'bed.deleted','beds',$bedId); log_activity($pdo,'Bed deleted #'.$bedId); flash('success','Bed deleted.'); redirect('wards.php');
    }
    redirect('wards.php');
}

$q=trim((string)($_GET['q'] ?? ''));
$s=$pdo->prepare('SELECT * FROM wards WHERE tenant_id=? AND (?="" OR name LIKE ?) ORDER BY name'); $s->execute([$tid,$q,'%'.$q.'%']); $wards=$s->fetchAll();
$s=$pdo->prepare('SELECT b.*, p.name AS patient_name FROM beds b LEFT JOIN patients p ON p.id=b.current_patient_id WHERE b.tenant_id=? ORDER BY b.ward_id, b.bed_number'); $s->execute([$tid]);
$bedsByWard=[]; foreach($s->fetchAll() as $b){ $bedsByWard[(int)$b['ward_id']][]=$b; }
$s=$pdo->prepare('SELECT status, COUNT(*) AS c FROM beds WHERE tenant_id=? GROUP BY status'); $s->execute([$tid]);
$summary=['available'=>0,'occupied'=>0,'reserved'=>0,'maintenance'=>0,'total'=>0];
foreach($s->fetchAll() as $r){ $summary[$r['status']]=(int)$r['c']; $summary['total']+=(int)$r['c']; }
$s=$pdo->prepare('SELECT id,name,patient_code FROM patients WHERE tenant_id=? AND status="active" ORDER BY name'); $s->execute([$tid]); $patients=$s->fetchAll();
$editWard=null; if(!empty($_GET['edit_ward']) && $canManage){ $wid=(int)$_GET['edit_ward']; $s=$pdo->prepare('SELECT * FROM wards WHERE id=? AND tenant_id=?'); $s->execute([$wid,$tid]); $editWard=$s->fetch() ?: null; }

$pageTitle='Wards & Beds'; $base='../'; include __DIR__.'/../includes/header.php';
?>
<div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
  <div><h5 class="mb-0">Wards &amp; Beds</h5><span class="text-muted small"><?= $summary['total'] ?> beds · <?= $summary['occupied'] ?> occupied · <?= $summary['available'] ?> available</span></div>
  <?php if ($canManage): ?><button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#wardModal"><i class="bi bi-plus-lg"></i> Add Ward</button><?php endif; ?>
</div>
<div class="d-flex flex-wrap gap-2 mb-3">
  <span class="chip">Available: <strong><?= $summary['available'] ?></strong></span><span class="chip">Occupied: <strong><?= $summary['occupied'] ?></strong></span><span class="chip">Reserved: <strong><?= $summary['reserved'] ?></strong></span><span class="chip">Maintenance: <strong><?= $summary['maintenance'] ?></strong></span>
</div>
<form method="get" class="card card-body mb-3"><div class="row g-2"><div class="col-md-9"><input class="form-control" name="q" value="<?= e($q) ?>" placeholder="Search wards…"></div><div class="col-md-3 d-flex gap-2"><button class="btn btn-primary flex-fill">Search</button><a class="btn btn-outline-secondary" href="wards.php">Reset</a></div></div></form>

<?php if (!$wards): ?><div class="card"><div class="empty-state"><img src="../assets/img/empty.svg" alt=""><p>No wards yet. Add your first ward to start managing beds.</p></div></div><?php endif; ?>

<?php foreach($wards as $w): $beds=$bedsByWard[(int)$w['id']] ?? []; ?>
<div class="card mb-3"><div class="card-header d-flex flex-wrap gap-2 justify-content-between align-items-center">
  <span><?= e((string)$w['name']) ?> <span class="chip ms-1"><?= e((string)$w['type']) ?></span> <span class="text-muted small"><?= e((string)($w['floor'] ?? '')) ?> · <?= count($beds) ?> bed(s)</span></span>
  <span class="d-flex gap-2">
    <?php if ($canManage): ?><a class="btn btn-sm btn-outline-secondary" href="?edit_ward=<?= (int)$w['id'] ?>">Edit Ward</a>
    <form method="post" class="d-inline" data-confirm="Delete this ward and all its beds?"><?= csrf_field() ?><input type="hidden" name="action" value="ward_delete"><input type="hidden" name="id" value="<?= (int)$w['id'] ?>"><button class="btn btn-sm btn-outline-danger">Delete</button></form><?php endif; ?>
  </span>
</div><div class="card-body">
  <?php if (!$beds): ?><div class="empty-state"><img src="../assets/img/empty.svg" alt=""><p>No beds in this ward yet.</p></div>
  <?php else: ?><div class="bed-grid">
    <?php foreach($beds as $b): ?><div class="bed-tile bed-<?= e((string)$b['status']) ?>">
      <i class="bi bi-hospital"></i><strong><?= e((string)$b['bed_number']) ?></strong>
      <div><?= status_badge((string)$b['status']) ?></div>
      <?php if (!empty($b['patient_name'])): ?><div class="small text-muted mt-1"><?= e((string)$b['patient_name']) ?></div><?php endif; ?>
      <form method="post" class="mt-2">
        <?= csrf_field() ?><input type="hidden" name="action" value="bed_status"><input type="hidden" name="bed_id" value="<?= (int)$b['id'] ?>">
        <select name="status" class="form-select form-select-sm mb-1 bed-status-sel"><?php foreach($bedStatuses as $st): ?><option value="<?= $st ?>" <?= $b['status']===$st?'selected':'' ?>><?= ucfirst($st) ?></option><?php endforeach; ?></select>
        <select name="patient_id" class="form-select form-select-sm mb-1"><option value="0">— Patient (if occupied) —</option><?php foreach($patients as $p): ?><option value="<?= (int)$p['id'] ?>" <?= (($b['current_patient_id'] ?? null)==$p['id'])?'selected':'' ?>><?= e((string)$p['name']) ?> (<?= e((string)$p['patient_code']) ?>)</option><?php endforeach; ?></select>
        <button class="btn btn-sm btn-primary w-100">Update</button>
      </form>
      <?php if ($canManage): ?><form method="post" class="mt-1" data-confirm="Delete this bed?"><?= csrf_field() ?><input type="hidden" name="action" value="bed_delete"><input type="hidden" name="bed_id" value="<?= (int)$b['id'] ?>"><button class="btn btn-sm btn-outline-danger w-100">Remove Bed</button></form><?php endif; ?>
    </div><?php endforeach; ?>
  </div><?php endif; ?>
  <?php if ($canManage): ?><form method="post" class="row g-2 mt-3 align-items-end"><?= csrf_field() ?><input type="hidden" name="action" value="bed_add"><input type="hidden" name="ward_id" value="<?= (int)$w['id'] ?>"><div class="col-md-4"><label class="form-label small">New Bed Number</label><input class="form-control" name="bed_number" required placeholder="e.g. A-05"></div><div class="col-md-3"><button class="btn btn-outline-primary w-100"><i class="bi bi-plus-lg"></i> Add Bed</button></div></form><?php endif; ?>
</div></div>
<?php endforeach; ?>

<?php if ($canManage): ?>
<div class="modal fade" id="wardModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><form method="post">
<?= csrf_field() ?><input type="hidden" name="action" value="ward_save"><input type="hidden" name="id" value="<?= $editWard ? (int)$editWard['id'] : 0 ?>">
<div class="modal-header"><h5 class="modal-title"><?= $editWard ? 'Edit Ward' : 'Add Ward' ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
<div class="modal-body"><div class="row g-3">
  <div class="col-12"><label class="form-label">Ward Name *</label><input class="form-control" name="name" required value="<?= e((string)($editWard['name'] ?? '')) ?>"></div>
  <div class="col-md-6"><label class="form-label">Type</label><select class="form-select" name="type"><?php foreach($wardTypes as $t): ?><option value="<?= $t ?>" <?= (($editWard['type'] ?? 'General')===$t)?'selected':'' ?>><?= $t ?></option><?php endforeach; ?></select></div>
  <div class="col-md-6"><label class="form-label">Floor</label><input class="form-control" name="floor" value="<?= e((string)($editWard['floor'] ?? '')) ?>"></div>
</div></div>
<div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Save Ward</button></div>
</form></div></div></div>
<?php if ($editWard): ?><script>document.addEventListener('DOMContentLoaded',function(){new bootstrap.Modal(document.getElementById('wardModal')).show();});</script><?php endif; ?>
<?php endif; ?>
<?php include __DIR__.'/../includes/footer.php'; ?>
