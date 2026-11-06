<?php
declare(strict_types=1);
/* Hospital Admin — Staff (Upgrade v2 page tree).
 * Staff account management for the hospital tenant: create / edit users in
 * the staff roles (receptionist, nurse, accountant, pharmacist, lab
 * technician, doctor), activate / deactivate, reset passwords. The hospital
 * admin's own account and platform accounts can never be edited here, and
 * the plan's staff limit is enforced on creation. */
require_once __DIR__ . '/../includes/auth.php';
$pdo = db();
$user = require_role(['hospital_admin']);
$tid = require_tenant();
check_tenant_active($pdo);
if (!has_permission('staff.view')) {
    flash('error', 'You do not have permission to view staff.');
    redirect('dashboard.php');
}
$canManage = has_permission('staff.manage');
$base = '../';
$panelSidebar = 'admin';

$roles = $pdo->query("SELECT * FROM roles WHERE slug IN ('receptionist','nurse','accountant','pharmacist','lab_technician','doctor') ORDER BY name")->fetchAll();
$roleIds = array_map(static fn($r) => (int)$r['id'], $roles);

if (is_post()) {
    verify_csrf();
    if (!$canManage) {
        flash('error', 'You do not have permission to modify staff.');
        redirect('staff.php');
    }
    $action = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    if ($action === 'save') {
        $name = trim((string)($_POST['name'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $phone = trim((string)($_POST['phone'] ?? '')) ?: null;
        $roleId = (int)($_POST['role_id'] ?? 0);
        $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
        $password = (string)($_POST['password'] ?? '');
        if ($name === '' || $email === '' || !in_array($roleId, $roleIds, true)) {
            flash('error', 'Name, email and a valid staff role are required.');
        } elseif ($id > 0) {
            if ($id === (int)$user['id']) {
                flash('error', 'You cannot edit your own account here — use Settings for your profile and password.');
            } else {
                $chk = $pdo->prepare('SELECT COUNT(*) FROM users WHERE id = ? AND tenant_id = ? AND role_id IN (' . implode(',', $roleIds) . ')');
                $chk->execute([$id, $tid]);
                if ((int)$chk->fetchColumn() === 0) {
                    flash('error', 'Staff member not found.');
                } elseif ($password !== '') {
                    $pdo->prepare('UPDATE users SET name = ?, email = ?, phone = ?, role_id = ?, status = ?, password_hash = ? WHERE id = ? AND tenant_id = ?')
                        ->execute([$name, $email, $phone, $roleId, $status, password_hash($password, PASSWORD_DEFAULT), $id, $tid]);
                    flash('success', 'Staff member updated and password reset.');
                } else {
                    $pdo->prepare('UPDATE users SET name = ?, email = ?, phone = ?, role_id = ?, status = ? WHERE id = ? AND tenant_id = ?')
                        ->execute([$name, $email, $phone, $roleId, $status, $id, $tid]);
                    flash('success', 'Staff member updated.');
                }
                if ($id > 0) {
                    audit_log($pdo, 'staff_updated', 'users', $id, 'Staff updated: ' . $email);
                }
            }
        } else {
            if (plan_limit_reached($pdo, $tid, 'staff')) {
                flash('error', 'Your plan\'s staff limit has been reached. Upgrade the subscription to add more staff.');
            } elseif ($password === '') {
                flash('error', 'Set an initial password for the new staff member.');
            } else {
                $dupe = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = ?');
                $dupe->execute([$email]);
                if ((int)$dupe->fetchColumn() > 0) {
                    flash('error', 'That email is already registered on the platform.');
                } else {
                    $pdo->prepare('INSERT INTO users (tenant_id, name, email, phone, password_hash, role_id, status, email_verified_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())')
                        ->execute([$tid, $name, $email, $phone, password_hash($password, PASSWORD_DEFAULT), $roleId, $status]);
                    $newId = (int)$pdo->lastInsertId();
                    audit_log($pdo, 'staff_created', 'users', $newId, 'Staff account created: ' . $email);
                    log_activity($pdo, 'Staff account created: ' . $name);
                    flash('success', 'Staff account created.');
                }
            }
        }
    } elseif ($action === 'toggle_status' && $id > 0 && $id !== (int)$user['id']) {
        $pdo->prepare("UPDATE users SET status = IF(status = 'active', 'inactive', 'active') WHERE id = ? AND tenant_id = ? AND role_id IN (" . implode(',', $roleIds) . ")")
            ->execute([$id, $tid]);
        flash('success', 'Staff status updated.');
    }
    redirect('staff.php');
}

$q = trim((string)($_GET['q'] ?? ''));
$roleFilter = (int)($_GET['role'] ?? 0);
$where = 'u.tenant_id = ? AND r.slug <> "super_admin"';
$params = [$tid];
if ($q !== '') {
    $where .= ' AND (u.name LIKE ? OR u.email LIKE ?)';
    array_push($params, '%' . $q . '%', '%' . $q . '%');
}
if ($roleFilter > 0) {
    $where .= ' AND u.role_id = ?';
    $params[] = $roleFilter;
}
$s = $pdo->prepare("SELECT u.*, r.name AS role_name, r.slug AS role_slug FROM users u JOIN roles r ON r.id = u.role_id WHERE {$where} ORDER BY r.name, u.name");
$s->execute($params);
$staff = $s->fetchAll();

$editId = (int)($_GET['edit'] ?? 0);
$editUser = null;
foreach ($staff as $m) {
    if ((int)$m['id'] === $editId) {
        $editUser = $m;
    }
}

$pageTitle = 'Staff';
include __DIR__ . '/../includes/header.php';
?>
<div class="card">
  <div class="card-header">
    <form class="row g-2 align-items-center" method="get">
      <div class="col-md-4"><input class="form-control" type="search" name="q" value="<?= e($q) ?>" placeholder="Search staff…" aria-label="Search staff"></div>
      <div class="col-md-3">
        <select class="form-select" name="role" aria-label="Filter by role">
          <option value="">All roles</option>
          <?php foreach ($roles as $r): ?><option value="<?= (int)$r['id'] ?>" <?= $roleFilter === (int)$r['id'] ? 'selected' : '' ?>><?= e($r['name']) ?></option><?php endforeach; ?>
          <option value="0" disabled>Hosp. Admin (shown in list)</option>
        </select>
      </div>
      <div class="col-md-2"><button class="btn btn-primary w-100" type="submit"><i class="bi bi-funnel"></i> Filter</button></div>
      <div class="col-md-3 text-md-end">
        <?php if ($canManage): ?><button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#staffModal"><i class="bi bi-person-plus"></i> Add Staff</button><?php endif; ?>
      </div>
    </form>
  </div>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead><tr><th>Staff member</th><th>Role</th><th>Phone</th><th>Status</th><th>Last login</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
      <?php if (!$staff): ?><tr><td colspan="6"><div class="empty-state py-4"><i class="bi bi-person-gear"></i><p class="mb-0">No staff match this filter.</p></div></td></tr><?php endif; ?>
      <?php foreach ($staff as $m): $editable = in_array((int)$m['role_id'], $roleIds, true) && (int)$m['id'] !== (int)$user['id']; ?>
        <tr>
          <td><div class="d-flex align-items-center gap-2"><span class="avatar-initials"><?= e(initials($m['name'])) ?></span><span><strong class="d-block"><?= e($m['name']) ?><?= (int)$m['id'] === (int)$user['id'] ? ' <span class="badge text-bg-primary">You</span>' : '' ?></strong><small class="text-muted"><?= e($m['email']) ?></small></span></div></td>
          <td><span class="chip"><?= e($m['role_name']) ?></span></td>
          <td><?= e($m['phone'] ?? '—') ?></td>
          <td><?= status_badge((string)$m['status']) ?></td>
          <td><small><?= $m['last_login_at'] ? fmt_datetime($m['last_login_at']) : 'Never' ?></small></td>
          <td class="text-end">
            <?php if ($canManage && $editable): ?>
              <a class="btn btn-sm btn-outline-secondary" href="staff.php?edit=<?= (int)$m['id'] ?>" title="Edit"><i class="bi bi-pencil"></i></a>
              <form method="post" class="d-inline" data-confirm="<?= $m['status'] === 'active' ? 'Deactivate this account? They will no longer be able to sign in.' : 'Reactivate this account?' ?>">
                <?= csrf_field() ?><input type="hidden" name="action" value="toggle_status"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                <button class="btn btn-sm btn-outline-<?= $m['status'] === 'active' ? 'danger' : 'success' ?>" type="submit" title="<?= $m['status'] === 'active' ? 'Deactivate' : 'Activate' ?>"><i class="bi bi-<?= $m['status'] === 'active' ? 'pause-circle' : 'play-circle' ?>"></i></button>
              </form>
            <?php else: ?><span class="text-muted small">—</span><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($canManage): ?>
<div class="modal fade" id="staffModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" method="post">
      <div class="modal-header"><h5 class="modal-title"><?= $editUser ? 'Edit Staff Member' : 'Add Staff Member' ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body">
        <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)($editUser['id'] ?? 0) ?>">
        <div class="mb-3"><label class="form-label">Full name *</label><input class="form-control" name="name" required maxlength="120" value="<?= e($editUser['name'] ?? '') ?>"></div>
        <div class="mb-3"><label class="form-label">Email (login) *</label><input class="form-control" type="email" name="email" required maxlength="190" value="<?= e($editUser['email'] ?? '') ?>"></div>
        <div class="row g-3">
          <div class="col-md-6"><label class="form-label">Role *</label>
            <select class="form-select" name="role_id" required><option value="">Select role…</option>
              <?php foreach ($roles as $r): ?><option value="<?= (int)$r['id'] ?>" <?= ($editUser['role_id'] ?? null) == $r['id'] ? 'selected' : '' ?>><?= e($r['name']) ?></option><?php endforeach; ?>
            </select></div>
          <div class="col-md-6"><label class="form-label">Phone</label><input class="form-control" name="phone" maxlength="40" value="<?= e($editUser['phone'] ?? '') ?>"></div>
          <div class="col-md-6"><label class="form-label">Status</label>
            <select class="form-select" name="status"><option value="active" <?= ($editUser['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option><option value="inactive" <?= ($editUser['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option></select></div>
          <div class="col-md-6"><label class="form-label"><?= $editUser ? 'New password (leave blank to keep)' : 'Initial password *' ?></label><input class="form-control" type="text" name="password" maxlength="72" <?= $editUser ? '' : 'required' ?>></div>
        </div>
        <p class="text-muted small mt-3 mb-0">To give a doctor portal access, add them from the Doctors page with a portal login — that links the login to the doctor profile automatically.</p>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Save staff member</button></div>
    </form>
  </div>
</div>
<?php if ($editUser): ?>
<script>document.addEventListener('DOMContentLoaded', function () { bootstrap.Modal.getOrCreateInstance(document.getElementById('staffModal')).show(); });</script>
<?php endif; ?>
<?php endif; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
