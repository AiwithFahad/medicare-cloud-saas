<?php
declare(strict_types=1);
/* Hospital Admin — Departments (Upgrade v2 page tree).
 * Department CRUD with head-doctor assignment and live per-department
 * counts (doctors, appointments). Deleting a department that still has
 * doctors is blocked so nobody is silently unassigned. */
require_once __DIR__ . '/../includes/auth.php';
$pdo = db();
$user = require_role(['hospital_admin']);
$tid = require_tenant();
check_tenant_active($pdo);
if (!has_permission('departments.view')) {
    flash('error', 'You do not have permission to view departments.');
    redirect('dashboard.php');
}
$canManage = has_permission('departments.manage');
$base = '../';
$panelSidebar = 'admin';

if (is_post()) {
    verify_csrf();
    if (!$canManage) {
        flash('error', 'You do not have permission to modify departments.');
        redirect('departments.php');
    }
    $action = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    if ($action === 'save') {
        $name = trim((string)($_POST['name'] ?? ''));
        $desc = trim((string)($_POST['description'] ?? '')) ?: null;
        $headId = (int)($_POST['head_doctor_id'] ?? 0) ?: null;
        if ($name === '') {
            flash('error', 'Department name is required.');
        } elseif ($id > 0) {
            $pdo->prepare('UPDATE departments SET name = ?, description = ?, head_doctor_id = ? WHERE id = ? AND tenant_id = ?')
                ->execute([$name, $desc, $headId, $id, $tid]);
            audit_log($pdo, 'department_updated', 'departments', $id, 'Department updated: ' . $name);
            flash('success', 'Department updated.');
        } else {
            if (plan_limit_reached($pdo, $tid, 'departments')) {
                flash('error', 'Your plan\'s department limit has been reached. Upgrade the subscription to add more.');
            } else {
                $pdo->prepare('INSERT INTO departments (tenant_id, name, description, head_doctor_id) VALUES (?, ?, ?, ?)')
                    ->execute([$tid, $name, $desc, $headId]);
                audit_log($pdo, 'department_created', 'departments', (int)$pdo->lastInsertId(), 'Department created: ' . $name);
                flash('success', 'Department created.');
            }
        }
    } elseif ($action === 'delete' && $id > 0) {
        $cnt = $pdo->prepare('SELECT COUNT(*) FROM doctors WHERE department_id = ? AND tenant_id = ?');
        $cnt->execute([$id, $tid]);
        if ((int)$cnt->fetchColumn() > 0) {
            flash('error', 'This department still has doctors assigned. Reassign them before deleting.');
        } else {
            $pdo->prepare('DELETE FROM departments WHERE id = ? AND tenant_id = ?')->execute([$id, $tid]);
            audit_log($pdo, 'department_deleted', 'departments', $id, 'Department deleted');
            flash('success', 'Department deleted.');
        }
    }
    redirect('departments.php');
}

$s = $pdo->prepare(
    "SELECT dep.*, hd.name AS head_name,
            (SELECT COUNT(*) FROM doctors d WHERE d.department_id = dep.id AND d.tenant_id = dep.tenant_id) AS doctor_count,
            (SELECT COUNT(*) FROM appointments a WHERE a.department_id = dep.id AND a.tenant_id = dep.tenant_id) AS appt_count,
            (SELECT COUNT(*) FROM appointments a WHERE a.department_id = dep.id AND a.tenant_id = dep.tenant_id AND a.appointment_date = CURDATE()) AS appt_today
       FROM departments dep
       LEFT JOIN doctors hd ON hd.id = dep.head_doctor_id
      WHERE dep.tenant_id = ? ORDER BY dep.name"
);
$s->execute([$tid]);
$departments = $s->fetchAll();

$doctorsList = $pdo->prepare('SELECT id, name, department_id FROM doctors WHERE tenant_id = ? ORDER BY name');
$doctorsList->execute([$tid]);
$doctorsList = $doctorsList->fetchAll();

$editId = (int)($_GET['edit'] ?? 0);
$editDept = null;
foreach ($departments as $d) {
    if ((int)$d['id'] === $editId) {
        $editDept = $d;
    }
}

$pageTitle = 'Departments';
include __DIR__ . '/../includes/header.php';
?>
<div class="row g-3">
  <?php if (!$departments): ?>
    <div class="col-12"><div class="card"><div class="empty-state py-5"><i class="bi bi-diagram-3"></i><p class="mb-3">No departments yet.</p><?php if ($canManage): ?><button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#deptModal"><i class="bi bi-plus-lg"></i> Add Department</button><?php endif; ?></div></div></div>
  <?php endif; ?>
  <?php foreach ($departments as $d): ?>
  <div class="col-md-6 col-xl-4">
    <div class="card h-100">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-start">
          <h5 class="mb-1"><?= e($d['name']) ?></h5>
          <?php if ($canManage): ?>
          <span class="d-inline-flex gap-1">
            <a class="btn btn-sm btn-outline-secondary" href="departments.php?edit=<?= (int)$d['id'] ?>" title="Edit"><i class="bi bi-pencil"></i></a>
            <form method="post" class="d-inline" data-confirm="Delete department <?= e($d['name']) ?>?">
              <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
              <button class="btn btn-sm btn-outline-danger" type="submit" title="Delete"><i class="bi bi-trash"></i></button>
            </form>
          </span>
          <?php endif; ?>
        </div>
        <p class="text-muted small"><?= e($d['description'] ?? 'No description.') ?></p>
        <dl class="row small mb-0">
          <dt class="col-6 text-muted">Head doctor</dt><dd class="col-6"><?= e($d['head_name'] ?? '—') ?></dd>
          <dt class="col-6 text-muted">Doctors</dt><dd class="col-6"><?= (int)$d['doctor_count'] ?></dd>
          <dt class="col-6 text-muted">Appointments today</dt><dd class="col-6"><?= (int)$d['appt_today'] ?></dd>
          <dt class="col-6 text-muted">Appointments (all time)</dt><dd class="col-6"><?= (int)$d['appt_count'] ?></dd>
        </dl>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php if ($canManage && $departments): ?>
<button class="btn btn-primary mt-3" type="button" data-bs-toggle="modal" data-bs-target="#deptModal"><i class="bi bi-plus-lg"></i> Add Department</button>

<div class="modal fade" id="deptModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" method="post">
      <div class="modal-header"><h5 class="modal-title"><?= $editDept ? 'Edit Department' : 'Add Department' ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body">
        <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)($editDept['id'] ?? 0) ?>">
        <div class="mb-3"><label class="form-label">Name *</label><input class="form-control" name="name" required maxlength="120" value="<?= e($editDept['name'] ?? '') ?>"></div>
        <div class="mb-3"><label class="form-label">Description</label><textarea class="form-control" name="description" rows="2" maxlength="255"><?= e($editDept['description'] ?? '') ?></textarea></div>
        <div><label class="form-label">Head doctor</label>
          <select class="form-select" name="head_doctor_id"><option value="">— None —</option>
            <?php foreach ($doctorsList as $doc): ?><option value="<?= (int)$doc['id'] ?>" <?= ($editDept['head_doctor_id'] ?? null) == $doc['id'] ? 'selected' : '' ?>><?= e($doc['name']) ?></option><?php endforeach; ?>
          </select></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Save department</button></div>
    </form>
  </div>
</div>
<?php if ($editDept): ?>
<script>document.addEventListener('DOMContentLoaded', function () { bootstrap.Modal.getOrCreateInstance(document.getElementById('deptModal')).show(); });</script>
<?php endif; ?>
<?php endif; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
