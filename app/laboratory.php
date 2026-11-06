<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
$pdo = db();
$user = require_role(['hospital_admin', 'lab_technician', 'doctor']);
$tid = require_tenant();
check_tenant_active($pdo);
$canManage = in_array($user['role'], ['hospital_admin', 'lab_technician'], true) || has_permission('lab.manage');

if (is_post()) {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'test_save' && $canManage) {
        $id = (int)($_POST['id'] ?? 0);
        $data = [
            trim((string)($_POST['name'] ?? '')),
            trim((string)($_POST['category'] ?? '')) ?: null,
            max(0, (float)($_POST['price'] ?? 0)),
            trim((string)($_POST['normal_range'] ?? '')) ?: null,
            trim((string)($_POST['unit'] ?? '')) ?: null,
            trim((string)($_POST['description'] ?? '')) ?: null,
        ];
        if ($data[0] === '') { flash('error', 'Test name is required.'); redirect('laboratory.php'); }
        if ($id > 0) {
            $u = $pdo->prepare('UPDATE lab_tests SET name=?,category=?,price=?,normal_range=?,unit=?,description=? WHERE id=? AND tenant_id=?');
            $u->execute(array_merge($data, [$id, $tid]));
            audit_log($pdo, 'lab_test.update', 'lab_test', $id, $data[0]);
            flash('success', 'Lab test updated.');
        } else {
            $i = $pdo->prepare('INSERT INTO lab_tests(tenant_id,name,category,price,normal_range,unit,description) VALUES(?,?,?,?,?,?,?)');
            $i->execute(array_merge([$tid], $data));
            $id = (int)$pdo->lastInsertId();
            audit_log($pdo, 'lab_test.create', 'lab_test', $id, $data[0]);
            flash('success', 'Lab test added to the catalog.');
        }
        redirect('laboratory.php');
    }

    if ($action === 'test_delete' && $canManage) {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $d = $pdo->prepare('DELETE FROM lab_tests WHERE id=? AND tenant_id=?');
            $d->execute([$id, $tid]);
            audit_log($pdo, 'lab_test.delete', 'lab_test', $id, '');
            flash('success', 'Lab test deleted.');
        } catch (Throwable $ex) {
            flash('error', 'This test has results on file and cannot be deleted.');
        }
        redirect('laboratory.php');
    }

    if ($action === 'order') {
        $patientId = (int)($_POST['patient_id'] ?? 0);
        $doctorId = (int)($_POST['doctor_id'] ?? 0) ?: null;
        $testId = (int)($_POST['lab_test_id'] ?? 0);
        $pv = $pdo->prepare('SELECT id FROM patients WHERE id=? AND tenant_id=?'); $pv->execute([$patientId, $tid]);
        $tv = $pdo->prepare('SELECT id FROM lab_tests WHERE id=? AND tenant_id=?'); $tv->execute([$testId, $tid]);
        if ($doctorId) { $dv = $pdo->prepare('SELECT id FROM doctors WHERE id=? AND tenant_id=?'); $dv->execute([$doctorId, $tid]); if (!$dv->fetch()) $doctorId = null; }
        if (!$pv->fetch() || !$tv->fetch()) { flash('error', 'Choose a patient and a lab test.'); redirect('laboratory.php#queue'); }
        $ins = $pdo->prepare('INSERT INTO lab_results(tenant_id,patient_id,doctor_id,lab_test_id,status,ordered_at) VALUES(?,?,?,?,"pending",NOW())');
        $ins->execute([$tid, $patientId, $doctorId, $testId]);
        $rid = (int)$pdo->lastInsertId();
        audit_log($pdo, 'lab.order', 'lab_result', $rid, '');
        flash('success', 'Lab test ordered — it is now in the results queue as pending.');
        redirect('laboratory.php#queue');
    }

    if ($action === 'result_update' && $canManage) {
        $id = (int)($_POST['id'] ?? 0);
        $value = trim((string)($_POST['result_value'] ?? '')) ?: null;
        $status = (string)($_POST['status'] ?? 'in_progress');
        if (!in_array($status, ['pending', 'in_progress', 'completed'], true)) $status = 'in_progress';
        $remarks = trim((string)($_POST['remarks'] ?? '')) ?: null;
        if ($status === 'completed') {
            $u = $pdo->prepare('UPDATE lab_results SET result_value=?,status=?,remarks=?,technician_id=?,completed_at=NOW() WHERE id=? AND tenant_id=?');
            $u->execute([$value, $status, $remarks, (int)$user['id'], $id, $tid]);
        } else {
            $u = $pdo->prepare('UPDATE lab_results SET result_value=?,status=?,remarks=?,technician_id=?,completed_at=NULL WHERE id=? AND tenant_id=?');
            $u->execute([$value, $status, $remarks, (int)$user['id'], $id, $tid]);
        }
        if ($u->rowCount() >= 0) audit_log($pdo, 'lab.result_update', 'lab_result', $id, $status);
        flash('success', 'Lab result updated.');
        redirect('laboratory.php#queue');
    }

    flash('error', 'You do not have permission for that action.');
    redirect('laboratory.php');
}

/* ---------------- Data ---------------- */
$ts = $pdo->prepare('SELECT * FROM lab_tests WHERE tenant_id=? ORDER BY name');
$ts->execute([$tid]);
$tests = $ts->fetchAll();

$editTest = null;
if (!empty($_GET['edit_test'])) {
    $es = $pdo->prepare('SELECT * FROM lab_tests WHERE id=? AND tenant_id=?');
    $es->execute([(int)$_GET['edit_test'], $tid]);
    $editTest = $es->fetch() ?: null;
}

$rStatus = trim((string)($_GET['r_status'] ?? ''));
$rwhere = 'lr.tenant_id=?'; $rargs = [$tid];
if (in_array($rStatus, ['pending', 'in_progress', 'completed'], true)) { $rwhere .= ' AND lr.status=?'; $rargs[] = $rStatus; }
$rs = $pdo->prepare("SELECT lr.*, p.name AS patient_name, p.patient_code, d.name AS doctor_name, lt.name AS test_name, lt.normal_range, lt.unit AS test_unit, lt.category AS test_category, u.name AS technician_name FROM lab_results lr JOIN patients p ON p.id=lr.patient_id LEFT JOIN doctors d ON d.id=lr.doctor_id JOIN lab_tests lt ON lt.id=lr.lab_test_id LEFT JOIN users u ON u.id=lr.technician_id WHERE $rwhere ORDER BY CASE lr.status WHEN 'pending' THEN 0 WHEN 'in_progress' THEN 1 ELSE 2 END, lr.ordered_at DESC LIMIT 100");
$rs->execute($rargs);
$results = $rs->fetchAll();
$queue = array_values(array_filter($results, fn($r) => $r['status'] !== 'completed'));
$completed = array_values(array_filter($results, fn($r) => $r['status'] === 'completed'));

$editResult = null;
if (!empty($_GET['result'])) {
    $es = $pdo->prepare('SELECT lr.*, lt.name AS test_name, lt.normal_range, lt.unit AS test_unit, p.name AS patient_name FROM lab_results lr JOIN lab_tests lt ON lt.id=lr.lab_test_id JOIN patients p ON p.id=lr.patient_id WHERE lr.id=? AND lr.tenant_id=?');
    $es->execute([(int)$_GET['result'], $tid]);
    $editResult = $es->fetch() ?: null;
}

$ps = $pdo->prepare('SELECT id,name,patient_code FROM patients WHERE tenant_id=? AND status="active" ORDER BY name'); $ps->execute([$tid]); $patients = $ps->fetchAll();
$ds = $pdo->prepare('SELECT id,name FROM doctors WHERE tenant_id=? AND status="active" ORDER BY name'); $ds->execute([$tid]); $doctors = $ds->fetchAll();

$pageTitle = 'Laboratory';
$base = '../';
include __DIR__ . '/../includes/header.php';
?>
<div class="row g-4 mb-4">
  <div class="col-lg-<?= $canManage ? '8' : '12' ?>">
    <div class="card"><div class="card-header">Lab test catalog</div>
      <div class="table-responsive"><table class="table"><thead><tr><th>Test</th><th>Category</th><th>Normal range</th><th class="text-end">Price</th><?php if ($canManage): ?><th class="text-end">Actions</th><?php endif; ?></tr></thead>
      <tbody>
      <?php if (!$tests): ?><tr><td colspan="5"><div class="empty-state">No lab tests in the catalog yet.</div></td></tr><?php endif; ?>
      <?php foreach ($tests as $t): ?>
        <tr><td><strong><?= e($t['name']) ?></strong><?php if ($t['description']): ?><br><small class="text-muted"><?= e((string)$t['description']) ?></small><?php endif; ?></td>
        <td><?= e((string)($t['category'] ?? '—')) ?></td>
        <td><?= e((string)($t['normal_range'] ?? '—')) ?><?= $t['unit'] ? ' ' . e((string)$t['unit']) : '' ?></td>
        <td class="text-end"><?= money($t['price']) ?></td>
        <?php if ($canManage): ?><td class="text-end"><a class="btn btn-sm btn-outline-primary" href="laboratory.php?edit_test=<?= (int)$t['id'] ?>">Edit</a>
        <form method="post" class="d-inline" data-confirm="Delete this lab test?"><?= csrf_field() ?><input type="hidden" name="action" value="test_delete"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><button class="btn btn-sm btn-outline-danger">Delete</button></form></td><?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody></table></div>
    </div>
  </div>
  <?php if ($canManage): ?>
  <div class="col-lg-4">
    <div class="card"><div class="card-header"><?= $editTest ? 'Edit lab test' : 'Add lab test' ?></div><div class="card-body">
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="test_save"><input type="hidden" name="id" value="<?= (int)($editTest['id'] ?? 0) ?>">
        <div class="mb-2"><label class="form-label">Name *</label><input name="name" class="form-control" required value="<?= e((string)($editTest['name'] ?? '')) ?>"></div>
        <div class="mb-2"><label class="form-label">Category</label><input name="category" class="form-control" value="<?= e((string)($editTest['category'] ?? '')) ?>"></div>
        <div class="row g-2">
          <div class="col-6 mb-2"><label class="form-label">Price ($)</label><input type="number" step="0.01" min="0" name="price" class="form-control" value="<?= e((string)($editTest['price'] ?? '0')) ?>"></div>
          <div class="col-6 mb-2"><label class="form-label">Unit</label><input name="unit" class="form-control" value="<?= e((string)($editTest['unit'] ?? '')) ?>"></div>
        </div>
        <div class="mb-2"><label class="form-label">Normal range</label><input name="normal_range" class="form-control" value="<?= e((string)($editTest['normal_range'] ?? '')) ?>" placeholder="e.g. 0.4 – 4.2"></div>
        <div class="mb-3"><label class="form-label">Description</label><input name="description" class="form-control" value="<?= e((string)($editTest['description'] ?? '')) ?>"></div>
        <button class="btn btn-primary w-100"><?= $editTest ? 'Save changes' : 'Add test' ?></button>
        <?php if ($editTest): ?><a class="btn btn-link w-100" href="laboratory.php">Cancel edit</a><?php endif; ?>
      </form>
    </div></div>
  </div>
  <?php endif; ?>
</div>

<div class="card mb-4" id="queue"><div class="card-header d-flex flex-wrap gap-2 justify-content-between align-items-center"><span>Results queue</span>
  <form class="d-flex gap-2" method="get"><select name="r_status" class="form-select form-select-sm"><option value="">All statuses</option>
  <?php foreach (['pending', 'in_progress', 'completed'] as $s): ?><option value="<?= $s ?>" <?= $rStatus === $s ? 'selected' : '' ?>><?= ucwords(str_replace('_', ' ', $s)) ?></option><?php endforeach; ?>
  </select><button class="btn btn-sm btn-primary">Filter</button></form></div>
  <div class="card-body border-bottom">
    <form method="post" class="row g-2 align-items-end"><?= csrf_field() ?><input type="hidden" name="action" value="order">
      <div class="col-md-4"><label class="form-label">Patient *</label><select name="patient_id" class="form-select" required><option value="">Select patient…</option><?php foreach ($patients as $p): ?><option value="<?= (int)$p['id'] ?>"><?= e($p['name']) ?> (<?= e($p['patient_code']) ?>)</option><?php endforeach; ?></select></div>
      <div class="col-md-3"><label class="form-label">Ordering doctor</label><select name="doctor_id" class="form-select"><option value="">—</option><?php foreach ($doctors as $d): ?><option value="<?= (int)$d['id'] ?>"><?= e($d['name']) ?></option><?php endforeach; ?></select></div>
      <div class="col-md-3"><label class="form-label">Test *</label><select name="lab_test_id" class="form-select" required><option value="">Select test…</option><?php foreach ($tests as $t): ?><option value="<?= (int)$t['id'] ?>"><?= e($t['name']) ?> — <?= money($t['price']) ?></option><?php endforeach; ?></select></div>
      <div class="col-md-2"><button class="btn btn-primary w-100"><i class="bi bi-droplet-half me-1"></i>Order test</button></div>
    </form>
  </div>
  <?php if ($editResult && $canManage): ?>
  <div class="card-body border-bottom bg-light">
    <h6>Update result — <?= e($editResult['test_name']) ?> for <?= e($editResult['patient_name']) ?> <small class="text-muted">(normal: <?= e((string)($editResult['normal_range'] ?? '—')) ?> <?= e((string)($editResult['test_unit'] ?? '')) ?>)</small></h6>
    <form method="post" class="row g-2 align-items-end"><?= csrf_field() ?><input type="hidden" name="action" value="result_update"><input type="hidden" name="id" value="<?= (int)$editResult['id'] ?>">
      <div class="col-md-3"><label class="form-label">Result value</label><input name="result_value" class="form-control" value="<?= e((string)($editResult['result_value'] ?? '')) ?>"></div>
      <div class="col-md-3"><label class="form-label">Status</label><select name="status" class="form-select"><?php foreach (['pending', 'in_progress', 'completed'] as $s): ?><option value="<?= $s ?>" <?= $editResult['status'] === $s ? 'selected' : '' ?>><?= ucwords(str_replace('_', ' ', $s)) ?></option><?php endforeach; ?></select></div>
      <div class="col-md-4"><label class="form-label">Remarks</label><input name="remarks" class="form-control" maxlength="255" value="<?= e((string)($editResult['remarks'] ?? '')) ?>"></div>
      <div class="col-md-2"><button class="btn btn-success w-100">Save result</button></div>
    </form>
  </div>
  <?php endif; ?>
  <div class="table-responsive"><table class="table"><thead><tr><th>Ordered</th><th>Patient</th><th>Test</th><th>Doctor</th><th>Status</th><?php if ($canManage): ?><th class="text-end">Action</th><?php endif; ?></tr></thead>
  <tbody>
  <?php if (!$queue): ?><tr><td colspan="6"><div class="empty-state">The queue is clear — no pending or in-progress results.</div></td></tr><?php endif; ?>
  <?php foreach ($queue as $r): ?>
    <tr><td><?= fmt_datetime($r['ordered_at']) ?></td><td><?= e($r['patient_name']) ?> <small class="text-muted"><?= e($r['patient_code']) ?></small></td><td><?= e($r['test_name']) ?></td><td><?= e((string)($r['doctor_name'] ?? '—')) ?></td><td><?= status_badge((string)$r['status']) ?></td>
    <?php if ($canManage): ?><td class="text-end"><a class="btn btn-sm btn-outline-primary" href="laboratory.php?result=<?= (int)$r['id'] ?>#queue">Update</a></td><?php endif; ?></tr>
  <?php endforeach; ?>
  </tbody></table></div>
</div>

<div class="card"><div class="card-header">Completed results</div>
  <div class="table-responsive"><table class="table"><thead><tr><th>Completed</th><th>Patient</th><th>Test</th><th>Result</th><th>Normal range</th><th>Technician</th><th>Remarks</th></tr></thead>
  <tbody>
  <?php if (!$completed): ?><tr><td colspan="7"><div class="empty-state">No completed results yet.</div></td></tr><?php endif; ?>
  <?php foreach ($completed as $r): ?>
    <tr><td><?= fmt_datetime($r['completed_at']) ?></td><td><?= e($r['patient_name']) ?></td><td><?= e($r['test_name']) ?></td>
    <td><strong><?= e((string)($r['result_value'] ?? '—')) ?></strong><?= $r['test_unit'] ? ' ' . e((string)$r['test_unit']) : '' ?></td>
    <td><?= e((string)($r['normal_range'] ?? '—')) ?><?= $r['test_unit'] ? ' ' . e((string)$r['test_unit']) : '' ?></td>
    <td><?= e((string)($r['technician_name'] ?? '—')) ?></td><td><?= e((string)($r['remarks'] ?? '—')) ?></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
