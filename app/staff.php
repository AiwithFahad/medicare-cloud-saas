<?php declare(strict_types=1); require_once __DIR__.'/../includes/auth.php'; $pdo=db(); $user=require_role(['hospital_admin']); $tid=require_tenant(); check_tenant_active($pdo);
$canManage = has_permission('staff.manage');

if (is_post()) {
    verify_csrf();
    if (!$canManage) { flash('error','You do not have permission to manage staff.'); redirect('staff.php'); }
    $action=(string)($_POST['action'] ?? '');
    if ($action==='toggle') {
        $id=(int)($_POST['id'] ?? 0);
        if ($id === (int)$user['id']) { flash('error','You cannot modify your own account.'); redirect('staff.php'); }
        $s=$pdo->prepare('SELECT u.status, r.slug AS role_slug FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=? AND u.tenant_id=?'); $s->execute([$id,$tid]); $row=$s->fetch();
        if (!$row) { flash('error','User not found.'); redirect('staff.php'); }
        if ($row['role_slug']==='super_admin') { flash('error','Super admin accounts cannot be modified here.'); redirect('staff.php'); }
        $newStatus = $row['status']==='active' ? 'inactive' : 'active';
        $u=$pdo->prepare('UPDATE users SET status=? WHERE id=? AND tenant_id=?'); $u->execute([$newStatus,$id,$tid]);
        audit_log($pdo,'staff.'.$newStatus,'users',$id); log_activity($pdo,'Staff #'.$id.' set to '.$newStatus);
        flash('success','Staff status updated to '.$newStatus.'.'); redirect('staff.php');
    }
    if ($action==='save') {
        $id=(int)($_POST['id'] ?? 0);
        $name=trim((string)($_POST['name'] ?? ''));
        $email=trim((string)($_POST['email'] ?? ''));
        $phone=trim((string)($_POST['phone'] ?? '')) ?: null;
        $roleId=(int)($_POST['role_id'] ?? 0);
        $password=(string)($_POST['password'] ?? '');
        $status=(string)($_POST['status'] ?? 'active'); if(!in_array($status,['active','inactive'],true)) $status='active';
        if ($name==='' || $email==='' || !$roleId) { flash('error','Name, email and role are required.'); redirect('staff.php'); }
        // Validate role: tenant scope, not patient, not super_admin
        $c=$pdo->prepare('SELECT slug FROM roles WHERE id=? AND scope="tenant"'); $c->execute([$roleId]); $rslug=$c->fetchColumn();
        if (!$rslug || $rslug==='patient' || $rslug==='super_admin') { flash('error','Invalid role selected.'); redirect('staff.php'); }
        try {
            if ($id>0) {
                if ($id === (int)$user['id']) { flash('error','You cannot modify your own account.'); redirect('staff.php'); }
                $chk=$pdo->prepare('SELECT r.slug AS role_slug FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=? AND u.tenant_id=?'); $chk->execute([$id,$tid]); $existing=$chk->fetch();
                if (!$existing || $existing['role_slug']==='super_admin') { flash('error','Cannot modify this account.'); redirect('staff.php'); }
                if ($password!=='') {
                    $s=$pdo->prepare('UPDATE users SET name=?,email=?,phone=?,role_id=?,status=?,password_hash=? WHERE id=? AND tenant_id=?');
                    $s->execute([$name,$email,$phone,$roleId,$status,password_hash($password,PASSWORD_DEFAULT),$id,$tid]);
                } else {
                    $s=$pdo->prepare('UPDATE users SET name=?,email=?,phone=?,role_id=?,status=? WHERE id=? AND tenant_id=?');
                    $s->execute([$name,$email,$phone,$roleId,$status,$id,$tid]);
                }
                audit_log($pdo,'staff.updated','users',$id,$name); log_activity($pdo,'Staff updated: '.$name); flash('success','Staff member updated.');
            } else {
                if ($password==='') { flash('error','Password is required for a new staff member.'); redirect('staff.php'); }
                if (plan_limit_reached($pdo,$tid,'staff')) { flash('error','Staff limit reached for your current plan. Please upgrade to add more staff.'); redirect('staff.php'); }
                $s=$pdo->prepare('INSERT INTO users(tenant_id,name,email,phone,password_hash,role_id,status,email_verified_at) VALUES(?,?,?,?,?,?,?,NOW())');
                $s->execute([$tid,$name,$email,$phone,password_hash($password,PASSWORD_DEFAULT),$roleId,$status]);
                $nid=(int)$pdo->lastInsertId();
                audit_log($pdo,'staff.created','users',$nid,$name); log_activity($pdo,'Staff added: '.$name); flash('success','Staff member created.');
            }
        } catch (PDOException $ex) {
            flash('error','Could not save staff member — the email may already be in use.');
        }
        redirect('staff.php');
    }
    if ($action==='delete') {
        $id=(int)($_POST['id'] ?? 0);
        if ($id === (int)$user['id']) { flash('error','You cannot delete your own account.'); redirect('staff.php'); }
        $chk=$pdo->prepare('SELECT r.slug AS role_slug FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=? AND u.tenant_id=?'); $chk->execute([$id,$tid]); $existing=$chk->fetch();
        if (!$existing || $existing['role_slug']==='super_admin') { flash('error','Cannot delete this account.'); redirect('staff.php'); }
        $s=$pdo->prepare('DELETE FROM users WHERE id=? AND tenant_id=?'); $s->execute([$id,$tid]);
        audit_log($pdo,'staff.deleted','users',$id); log_activity($pdo,'Staff deleted #'.$id); flash('success','Staff member deleted.'); redirect('staff.php');
    }
    redirect('staff.php');
}

$q=trim((string)($_GET['q'] ?? '')); $roleF=(string)($_GET['role'] ?? ''); $statusF=(string)($_GET['status'] ?? '');
$where='u.tenant_id=?'; $params=[$tid];
if ($q!==''){ $where.=' AND (u.name LIKE ? OR u.email LIKE ?)'; $like='%'.$q.'%'; $params[]=$like; $params[]=$like; }
if ($roleF!==''){ $where.=' AND r.slug=?'; $params[]=$roleF; }
if (in_array($statusF,['active','inactive'],true)){ $where.=' AND u.status=?'; $params[]=$statusF; }
[$limit,$offset,$page]=page_params(15);
$s=$pdo->prepare("SELECT COUNT(*) FROM users u JOIN roles r ON r.id=u.role_id WHERE $where"); $s->execute($params); $total=(int)$s->fetchColumn();
$s=$pdo->prepare("SELECT u.*, r.name AS role_name, r.slug AS role_slug FROM users u JOIN roles r ON r.id=u.role_id WHERE $where ORDER BY u.name ASC LIMIT $limit OFFSET $offset");
$s->execute($params); $staff=$s->fetchAll();
$s=$pdo->prepare('SELECT * FROM roles WHERE scope="tenant" AND slug!="patient" ORDER BY name'); $s->execute(); $roles=$s->fetchAll();
$editUser=null; if(!empty($_GET['edit']) && $canManage){ $eid=(int)$_GET['edit']; if($eid!==(int)$user['id']){ $s=$pdo->prepare('SELECT * FROM users WHERE id=? AND tenant_id=?'); $s->execute([$eid,$tid]); $editUser=$s->fetch() ?: null; } }

$pageTitle='Nurses & Staff'; $base='../'; include __DIR__.'/../includes/header.php';
?>
<div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
  <div><h5 class="mb-0"><?= $total ?> staff members</h5><span class="text-muted small">Tenant users &amp; roles — manage is hospital admin only</span></div>
  <?php if ($canManage): ?><button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#staffModal"><i class="bi bi-plus-lg"></i> Add Staff</button><?php endif; ?>
</div>
<form method="get" class="card card-body mb-3"><div class="row g-2">
  <div class="col-md-5"><input class="form-control" name="q" value="<?= e($q) ?>" placeholder="Search name or email…"></div>
  <div class="col-md-2"><select class="form-select" name="role"><option value="">All roles</option><?php foreach($roles as $r): ?><option value="<?= e((string)$r['slug']) ?>" <?= $roleF===$r['slug']?'selected':'' ?>><?= e((string)$r['name']) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-2"><select class="form-select" name="status"><option value="">All statuses</option><?php foreach(['active','inactive'] as $st): ?><option value="<?= $st ?>" <?= $statusF===$st?'selected':'' ?>><?= ucfirst($st) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-3 d-flex gap-2"><button class="btn btn-primary flex-fill">Filter</button><a class="btn btn-outline-secondary" href="staff.php">Reset</a></div>
</div></form>

<div class="card"><div class="table-responsive"><table class="table"><thead><tr><th>Staff</th><th>Role</th><th>Phone</th><th>Status</th><th>Last Login</th><th class="text-end">Actions</th></tr></thead><tbody>
<?php if (!$staff): ?><tr><td colspan="6"><div class="empty-state"><img src="../assets/img/empty.svg" alt=""><p>No staff found.</p></div></td></tr><?php endif; ?>
<?php foreach($staff as $m): $isSelf=(int)$m['id']===(int)$user['id']; ?><tr>
  <td><span class="avatar-sm me-2"><?= e(initials((string)$m['name'])) ?></span><strong><?= e((string)$m['name']) ?></strong><?php if($isSelf): ?> <span class="chip">You</span><?php endif; ?><div class="text-muted small"><?= e((string)$m['email']) ?></div></td>
  <td><span class="badge text-bg-primary"><?= e((string)$m['role_name']) ?></span></td>
  <td><?= e((string)($m['phone'] ?? '—')) ?></td>
  <td><?= status_badge((string)$m['status']) ?></td>
  <td><?= !empty($m['last_login_at']) ? e(fmt_datetime((string)$m['last_login_at'])) : 'Never' ?></td>
  <td class="text-end"><?php if ($canManage && !$isSelf && $m['role_slug']!=='super_admin'): ?>
    <a class="btn btn-sm btn-outline-secondary" href="?edit=<?= (int)$m['id'] ?>">Edit</a>
    <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>"><button class="btn btn-sm btn-outline-warning"><?= $m['status']==='active' ? 'Deactivate' : 'Activate' ?></button></form>
    <form method="post" class="d-inline" data-confirm="Delete this staff member?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>"><button class="btn btn-sm btn-outline-danger">Delete</button></form>
  <?php elseif ($isSelf): ?><span class="text-muted small">Own account — locked</span><?php endif; ?></td>
</tr><?php endforeach; ?>
</tbody></table></div><div class="card-footer d-flex justify-content-between align-items-center"><span class="text-muted small">Showing <?= count($staff) ?> of <?= $total ?></span><?= pager_html($total,$limit,$page) ?></div></div>

<?php if ($canManage): ?>
<div class="modal fade" id="staffModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><form method="post">
<?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= $editUser ? (int)$editUser['id'] : 0 ?>">
<div class="modal-header"><h5 class="modal-title"><?= $editUser ? 'Edit Staff Member' : 'Add Staff Member' ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
<div class="modal-body"><div class="row g-3">
  <div class="col-12"><label class="form-label">Full Name *</label><input class="form-control" name="name" required value="<?= e((string)($editUser['name'] ?? '')) ?>"></div>
  <div class="col-md-6"><label class="form-label">Email *</label><input type="email" class="form-control" name="email" required value="<?= e((string)($editUser['email'] ?? '')) ?>"></div>
  <div class="col-md-6"><label class="form-label">Phone</label><input class="form-control" name="phone" value="<?= e((string)($editUser['phone'] ?? '')) ?>"></div>
  <div class="col-md-6"><label class="form-label">Role *</label><select class="form-select" name="role_id" required><option value="">Select role…</option><?php foreach($roles as $r): ?><option value="<?= (int)$r['id'] ?>" <?= (($editUser['role_id'] ?? null)==$r['id'])?'selected':'' ?>><?= e((string)$r['name']) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-6"><label class="form-label">Status</label><select class="form-select" name="status"><?php foreach(['active','inactive'] as $st): ?><option value="<?= $st ?>" <?= (($editUser['status'] ?? 'active')===$st)?'selected':'' ?>><?= ucfirst($st) ?></option><?php endforeach; ?></select></div>
  <div class="col-12"><label class="form-label">Password <?= $editUser ? '(leave blank to keep current)' : '*' ?></label><input type="password" class="form-control" name="password" <?= $editUser ? '' : 'required' ?> autocomplete="new-password"></div>
</div></div>
<div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Save Staff</button></div>
</form></div></div></div>
<?php if ($editUser): ?><script>document.addEventListener('DOMContentLoaded',function(){new bootstrap.Modal(document.getElementById('staffModal')).show();});</script><?php endif; ?>
<?php endif; ?>
<?php include __DIR__.'/../includes/footer.php'; ?>
