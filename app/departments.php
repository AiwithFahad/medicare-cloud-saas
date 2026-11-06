<?php declare(strict_types=1); require_once __DIR__.'/../includes/auth.php'; $pdo=db(); $user=require_role(['hospital_admin','doctor','receptionist','nurse']); $tid=require_tenant(); check_tenant_active($pdo);
$canManage = has_permission('departments.manage');

if (is_post()) {
    verify_csrf();
    if (!$canManage) { flash('error','You do not have permission to manage departments.'); redirect('departments.php'); }
    $action=(string)($_POST['action'] ?? '');
    if ($action==='delete') {
        $id=(int)($_POST['id'] ?? 0);
        $s=$pdo->prepare('DELETE FROM departments WHERE id=? AND tenant_id=?'); $s->execute([$id,$tid]);
        audit_log($pdo,'department.deleted','departments',$id); log_activity($pdo,'Department deleted #'.$id);
        flash('success','Department deleted.'); redirect('departments.php');
    }
    if ($action==='save') {
        $id=(int)($_POST['id'] ?? 0);
        $name=trim((string)($_POST['name'] ?? ''));
        $desc=trim((string)($_POST['description'] ?? '')) ?: null;
        $floor=trim((string)($_POST['floor'] ?? '')) ?: null;
        $phone=trim((string)($_POST['phone'] ?? '')) ?: null;
        $head=(int)($_POST['head_doctor_id'] ?? 0) ?: null;
        $status=(string)($_POST['status'] ?? 'active'); if(!in_array($status,['active','inactive'],true)) $status='active';
        if ($name==='') { flash('error','Department name is required.'); redirect('departments.php'); }
        if ($head) { $c=$pdo->prepare('SELECT COUNT(*) FROM doctors WHERE id=? AND tenant_id=?'); $c->execute([$head,$tid]); if(!(int)$c->fetchColumn()) $head=null; }
        try {
            if ($id>0) {
                $s=$pdo->prepare('UPDATE departments SET name=?,description=?,floor=?,phone=?,head_doctor_id=?,status=? WHERE id=? AND tenant_id=?');
                $s->execute([$name,$desc,$floor,$phone,$head,$status,$id,$tid]);
                audit_log($pdo,'department.updated','departments',$id,$name); log_activity($pdo,'Department updated: '.$name); flash('success','Department updated.');
            } else {
                if (plan_limit_reached($pdo,$tid,'departments')) { flash('error','Department limit reached for your current plan. Please upgrade to add more departments.'); redirect('departments.php'); }
                $s=$pdo->prepare('INSERT INTO departments(tenant_id,name,description,floor,phone,head_doctor_id,status) VALUES(?,?,?,?,?,?,?)');
                $s->execute([$tid,$name,$desc,$floor,$phone,$head,$status]);
                $nid=(int)$pdo->lastInsertId();
                audit_log($pdo,'department.created','departments',$nid,$name); log_activity($pdo,'Department created: '.$name); flash('success','Department created.');
            }
        } catch (PDOException $ex) {
            flash('error','Could not save department — a department with this name may already exist.');
        }
        redirect('departments.php');
    }
    redirect('departments.php');
}

$q=trim((string)($_GET['q'] ?? ''));
$where='dep.tenant_id=?'; $params=[$tid];
if ($q!==''){ $where.=' AND dep.name LIKE ?'; $params[]='%'.$q.'%'; }
[$limit,$offset,$page]=page_params(15);
$s=$pdo->prepare("SELECT COUNT(*) FROM departments dep WHERE $where"); $s->execute($params); $total=(int)$s->fetchColumn();
$s=$pdo->prepare("SELECT dep.*, hd.name AS head_name, (SELECT COUNT(*) FROM doctors d WHERE d.department_id=dep.id AND d.tenant_id=dep.tenant_id) AS doctor_count, (SELECT COUNT(*) FROM appointments a WHERE a.department_id=dep.id AND a.tenant_id=dep.tenant_id) AS appt_count FROM departments dep LEFT JOIN doctors hd ON hd.id=dep.head_doctor_id AND hd.tenant_id=dep.tenant_id WHERE $where ORDER BY dep.name ASC LIMIT $limit OFFSET $offset");
$s->execute($params); $departments=$s->fetchAll();
$s=$pdo->prepare('SELECT id,name FROM doctors WHERE tenant_id=? AND status="active" ORDER BY name'); $s->execute([$tid]); $doctors=$s->fetchAll();
$editDept=null; if(!empty($_GET['edit']) && $canManage){ $eid=(int)$_GET['edit']; $s=$pdo->prepare('SELECT * FROM departments WHERE id=? AND tenant_id=?'); $s->execute([$eid,$tid]); $editDept=$s->fetch() ?: null; }

$pageTitle='Departments'; $base='../'; include __DIR__.'/../includes/header.php';
?>
<div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
  <div><h5 class="mb-0"><?= $total ?> departments</h5><span class="text-muted small">Clinical departments and heads</span></div>
  <?php if ($canManage): ?><button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#deptModal"><i class="bi bi-plus-lg"></i> Add Department</button><?php endif; ?>
</div>
<form method="get" class="card card-body mb-3"><div class="row g-2"><div class="col-md-9"><input class="form-control" name="q" value="<?= e($q) ?>" placeholder="Search departments…"></div><div class="col-md-3 d-flex gap-2"><button class="btn btn-primary flex-fill">Search</button><a class="btn btn-outline-secondary" href="departments.php">Reset</a></div></div></form>

<?php if (!$departments): ?><div class="card"><div class="empty-state"><img src="../assets/img/empty.svg" alt=""><p>No departments found.</p></div></div><?php else: ?>
<div class="row g-3">
<?php foreach($departments as $dep): ?><div class="col-md-6 col-xl-4"><div class="card h-100"><div class="card-body">
  <div class="d-flex justify-content-between align-items-start"><h6 class="mb-1"><?= e((string)$dep['name']) ?></h6><?= status_badge((string)$dep['status']) ?></div>
  <p class="text-muted small mb-2"><?= e((string)($dep['description'] ?? 'No description')) ?></p>
  <div class="d-flex gap-2 flex-wrap mb-2"><span class="chip"><i class="bi bi-building"></i> <?= e((string)($dep['floor'] ?? '—')) ?></span><span class="chip"><i class="bi bi-telephone"></i> <?= e((string)($dep['phone'] ?? '—')) ?></span></div>
  <div class="small mb-1">Head: <strong><?= e((string)($dep['head_name'] ?? 'Not assigned')) ?></strong></div>
  <div class="d-flex gap-3 small text-muted mb-3"><span><i class="bi bi-person-badge"></i> <?= (int)$dep['doctor_count'] ?> doctors</span><span><i class="bi bi-calendar-check"></i> <?= (int)$dep['appt_count'] ?> appointments</span></div>
  <?php if ($canManage): ?><div class="d-flex gap-2"><a class="btn btn-sm btn-outline-secondary flex-fill" href="?edit=<?= (int)$dep['id'] ?>">Edit</a><form method="post" data-confirm="Delete this department? Doctors in it will become unassigned."><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$dep['id'] ?>"><button class="btn btn-sm btn-outline-danger">Delete</button></form></div><?php endif; ?>
</div></div></div><?php endforeach; ?>
</div>
<div class="d-flex justify-content-between align-items-center mt-3"><span class="text-muted small">Showing <?= count($departments) ?> of <?= $total ?></span><?= pager_html($total,$limit,$page) ?></div>
<?php endif; ?>

<?php if ($canManage): ?>
<div class="modal fade" id="deptModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><form method="post">
<?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= $editDept ? (int)$editDept['id'] : 0 ?>">
<div class="modal-header"><h5 class="modal-title"><?= $editDept ? 'Edit Department' : 'Add Department' ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
<div class="modal-body"><div class="row g-3">
  <div class="col-12"><label class="form-label">Name *</label><input class="form-control" name="name" required value="<?= e((string)($editDept['name'] ?? '')) ?>"></div>
  <div class="col-12"><label class="form-label">Description</label><input class="form-control" name="description" value="<?= e((string)($editDept['description'] ?? '')) ?>"></div>
  <div class="col-md-6"><label class="form-label">Floor</label><input class="form-control" name="floor" value="<?= e((string)($editDept['floor'] ?? '')) ?>"></div>
  <div class="col-md-6"><label class="form-label">Phone</label><input class="form-control" name="phone" value="<?= e((string)($editDept['phone'] ?? '')) ?>"></div>
  <div class="col-md-6"><label class="form-label">Head Doctor</label><select class="form-select" name="head_doctor_id"><option value="0">— None —</option><?php foreach($doctors as $doc): ?><option value="<?= (int)$doc['id'] ?>" <?= (($editDept['head_doctor_id'] ?? null)==$doc['id'])?'selected':'' ?>><?= e((string)$doc['name']) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-6"><label class="form-label">Status</label><select class="form-select" name="status"><?php foreach(['active','inactive'] as $st): ?><option value="<?= $st ?>" <?= (($editDept['status'] ?? 'active')===$st)?'selected':'' ?>><?= ucfirst($st) ?></option><?php endforeach; ?></select></div>
</div></div>
<div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Save Department</button></div>
</form></div></div></div>
<?php if ($editDept): ?><script>document.addEventListener('DOMContentLoaded',function(){new bootstrap.Modal(document.getElementById('deptModal')).show();});</script><?php endif; ?>
<?php endif; ?>
<?php include __DIR__.'/../includes/footer.php'; ?>
