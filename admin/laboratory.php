<?php
declare(strict_types=1);
/* Hospital Admin — Laboratory (Upgrade v2 page tree).
 * Test catalog CRUD, lab requests (orders) with a pending → in progress →
 * completed workflow, result entry, and a printable lab report per result
 * (?print=N) with the catalog's normal range for reference. */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/panel.php';
$pdo = db();
$user = require_role(['hospital_admin']);
$tid = require_tenant();
check_tenant_active($pdo);
if (!has_permission('lab.view')) {
    flash('error', 'You do not have permission to view the laboratory.');
    redirect('dashboard.php');
}
$canManage = has_permission('lab.manage');
$base = '../';
$panelSidebar = 'admin';

/* ---------------- Printable lab report (standalone, exits) ---------------- */
$printId = (int)($_GET['print'] ?? 0);
if ($printId > 0) {
    $rs = $pdo->prepare('SELECT lr.*, lt.name AS test_name, lt.normal_range, lt.unit, lt.category, p.name AS patient_name, p.patient_code, p.dob, p.gender, d.name AS doctor_name, tech.name AS technician_name FROM lab_results lr JOIN lab_tests lt ON lt.id = lr.lab_test_id JOIN patients p ON p.id = lr.patient_id LEFT JOIN doctors d ON d.id = lr.doctor_id LEFT JOIN users tech ON tech.id = lr.technician_id WHERE lr.id = ? AND lr.tenant_id = ?');
    $rs->execute([$printId, $tid]);
    $lab = $rs->fetch();
    if (!$lab) {
        http_response_code(404);
        exit('Lab result not found.');
    }
    $tenant = $pdo->query('SELECT name FROM tenants WHERE id = ' . $tid)->fetchColumn();
    print_page_start('Lab Report — ' . $lab['test_name'], '../');
    ?>
    <div class="d-flex justify-content-between align-items-start mb-4">
      <div><h4 class="mb-1"><?= e((string)$tenant) ?></h4><small class="text-muted">Laboratory report</small></div>
      <div class="text-end"><h4 class="mb-1">LAB-<?= (int)$lab['id'] ?></h4><span class="badge text-bg-<?= $lab['status'] === 'completed' ? 'success' : 'warning' ?>"><?= e(ucfirst(str_replace('_', ' ', (string)$lab['status']))) ?></span></div>
    </div>
    <div class="row mb-4">
      <div class="col-6"><small class="text-muted d-block">Patient</small><strong><?= e($lab['patient_name']) ?></strong> (<?= e($lab['patient_code']) ?>)<br><small class="text-muted"><?= e(patient_age($lab['dob'])) ?> · <?= e(ucfirst((string)($lab['gender'] ?? ''))) ?></small></div>
      <div class="col-6 text-end"><small class="text-muted d-block">Referred by</small><strong><?= e($lab['doctor_name'] ?? '—') ?></strong><br><small class="text-muted">Ordered <?= fmt_datetime($lab['ordered_at']) ?><?= $lab['completed_at'] ? ' · Completed ' . fmt_datetime($lab['completed_at']) : '' ?></small></div>
    </div>
    <table class="table table-bordered align-middle">
      <thead><tr><th>Test</th><th>Category</th><th class="text-center">Result</th><th class="text-center">Unit</th><th class="text-center">Normal range</th></tr></thead>
      <tbody>
        <tr>
          <td><strong><?= e($lab['test_name']) ?></strong></td>
          <td><?= e($lab['category'] ?? '—') ?></td>
          <td class="text-center fs-5"><strong><?= $lab['result_value'] !== null && $lab['result_value'] !== '' ? e($lab['result_value']) : '—' ?></strong></td>
          <td class="text-center"><?= e($lab['unit'] ?? '—') ?></td>
          <td class="text-center"><?= e($lab['normal_range'] ?? '—') ?></td>
        </tr>
      </tbody>
    </table>
    <?php if ($lab['remarks']): ?><p><strong>Remarks:</strong> <?= e($lab['remarks']) ?></p><?php endif; ?>
    <div class="row mt-5">
      <div class="col-6"><small class="text-muted">Lab technician</small><div class="border-bottom mt-4"></div><small><?= e($lab['technician_name'] ?? '') ?></small></div>
      <div class="col-6 text-end"><small class="text-muted">Verified by</small><div class="border-bottom mt-4"></div></div>
    </div>
    <?php
    print_page_end();
    exit;
}

/* ---------------- POST actions ---------------- */
if (is_post()) {
    verify_csrf();
    if (!$canManage) {
        flash('error', 'You do not have permission to modify laboratory data.');
        redirect('laboratory.php');
    }
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'save_test') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        if ($name === '') {
            flash('error', 'Test name is required.');
        } elseif ($id > 0) {
            $pdo->prepare('UPDATE lab_tests SET name=?, category=?, price=?, normal_range=?, unit=?, description=? WHERE id=? AND tenant_id=?')
                ->execute([$name, trim((string)($_POST['category'] ?? '')) ?: null, (float)($_POST['price'] ?? 0), trim((string)($_POST['normal_range'] ?? '')) ?: null, trim((string)($_POST['unit'] ?? '')) ?: null, trim((string)($_POST['description'] ?? '')) ?: null, $id, $tid]);
            flash('success', 'Lab test updated.');
        } else {
            $pdo->prepare('INSERT INTO lab_tests (tenant_id, name, category, price, normal_range, unit, description) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([$tid, $name, trim((string)($_POST['category'] ?? '')) ?: null, (float)($_POST['price'] ?? 0), trim((string)($_POST['normal_range'] ?? '')) ?: null, trim((string)($_POST['unit'] ?? '')) ?: null, trim((string)($_POST['description'] ?? '')) ?: null]);
            flash('success', 'Lab test added to the catalog.');
        }
    } elseif ($action === 'delete_test') {
        $pdo->prepare('DELETE FROM lab_tests WHERE id = ? AND tenant_id = ?')->execute([(int)($_POST['id'] ?? 0), $tid]);
        flash('success', 'Lab test removed.');
    } elseif ($action === 'order_test') {
        $patientId = (int)($_POST['patient_id'] ?? 0);
        $testId = (int)($_POST['lab_test_id'] ?? 0);
        $doctorId = (int)($_POST['doctor_id'] ?? 0) ?: null;
        $valid = $pdo->prepare('SELECT (SELECT COUNT(*) FROM patients WHERE id = ? AND tenant_id = ?) + (SELECT COUNT(*) FROM lab_tests WHERE id = ? AND tenant_id = ?)');
        $valid->execute([$patientId, $tid, $testId, $tid]);
        if ((int)$valid->fetchColumn() !== 2) {
            flash('error', 'Pick a valid patient and test.');
        } else {
            $pdo->prepare('INSERT INTO lab_results (tenant_id, patient_id, doctor_id, lab_test_id, status) VALUES (?, ?, ?, ?, "pending")')
                ->execute([$tid, $patientId, $doctorId, $testId]);
            audit_log($pdo, 'lab_test_ordered', 'lab_results', (int)$pdo->lastInsertId(), 'Lab test ordered');
            flash('success', 'Lab test ordered — it is now in the pending queue.');
        }
    } elseif ($action === 'save_result') {
        $id = (int)($_POST['id'] ?? 0);
        $value = trim((string)($_POST['result_value'] ?? '')) ?: null;
        $status = in_array(($_POST['status'] ?? ''), ['pending', 'in_progress', 'completed'], true) ? (string)$_POST['status'] : 'in_progress';
        if ($status === 'completed' && $value === null) {
            flash('error', 'Enter the result value before marking the test completed.');
        } else {
            $pdo->prepare('UPDATE lab_results SET result_value = ?, remarks = ?, status = ?, technician_id = ?, completed_at = IF(? = "completed", NOW(), completed_at) WHERE id = ? AND tenant_id = ?')
                ->execute([$value, trim((string)($_POST['remarks'] ?? '')) ?: null, $status, (int)$user['id'], $status, $id, $tid]);
            audit_log($pdo, 'lab_result_updated', 'lab_results', $id, 'Lab result ' . $status);
            flash('success', 'Lab result saved.');
        }
    }
    redirect('laboratory.php' . (!empty($_POST['tab']) ? '?tab=' . urlencode((string)$_POST['tab']) : ''));
}

$tab = (string)($_GET['tab'] ?? 'queue');
$statusF = (string)($_GET['status'] ?? '');

$tests = $pdo->prepare('SELECT * FROM lab_tests WHERE tenant_id = ? ORDER BY name');
$tests->execute([$tid]);
$tests = $tests->fetchAll();

$where = 'lr.tenant_id = ?';
$params = [$tid];
if (in_array($statusF, ['pending', 'in_progress', 'completed'], true)) {
    $where .= ' AND lr.status = ?';
    $params[] = $statusF;
}
$queue = $pdo->prepare(
    "SELECT lr.*, lt.name AS test_name, lt.normal_range, lt.unit, p.name AS patient_name, p.patient_code, d.name AS doctor_name
       FROM lab_results lr
       JOIN lab_tests lt ON lt.id = lr.lab_test_id
       JOIN patients p ON p.id = lr.patient_id
       LEFT JOIN doctors d ON d.id = lr.doctor_id
      WHERE {$where} ORDER BY FIELD(lr.status, 'pending','in_progress','completed'), lr.id DESC LIMIT 80"
);
$queue->execute($params);
$queue = $queue->fetchAll();

$patientsList = $pdo->prepare('SELECT id, name, patient_code FROM patients WHERE tenant_id = ? ORDER BY name');
$patientsList->execute([$tid]);
$patientsList = $patientsList->fetchAll();
$doctorsList = $pdo->prepare('SELECT id, name FROM doctors WHERE tenant_id = ? ORDER BY name');
$doctorsList->execute([$tid]);
$doctorsList = $doctorsList->fetchAll();

$pendCount = count(array_filter($queue, static fn($r) => $r['status'] !== 'completed'));
$editTestId = (int)($_GET['edit_test'] ?? 0);
$editTest = null;
foreach ($tests as $t) {
    if ((int)$t['id'] === $editTestId) {
        $editTest = $t;
    }
}

$pageTitle = 'Laboratory';
include __DIR__ . '/../includes/header.php';
?>
<ul class="nav nav-tabs mb-3">
  <li class="nav-item"><a class="nav-link <?= $tab !== 'catalog' ? 'active' : '' ?>" href="laboratory.php?tab=queue"><i class="bi bi-droplet-half"></i> Test Queue <span class="badge text-bg-warning"><?= $pendCount ?></span></a></li>
  <li class="nav-item"><a class="nav-link <?= $tab === 'catalog' ? 'active' : '' ?>" href="laboratory.php?tab=catalog"><i class="bi bi-collection"></i> Test Catalog</a></li>
  <?php if ($canManage): ?><li class="nav-item ms-auto"><button class="btn btn-primary btn-sm mt-1" type="button" data-bs-toggle="modal" data-bs-target="#orderModal"><i class="bi bi-plus-lg"></i> Order Test</button></li><?php endif; ?>
</ul>

<?php if ($tab === 'catalog'): ?>
<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center"><span><?= count($tests) ?> tests in catalog</span>
    <?php if ($canManage): ?><button class="btn btn-primary btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#testModal"><i class="bi bi-plus-lg"></i> Add Test</button><?php endif; ?>
  </div>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead><tr><th>Test</th><th>Category</th><th>Normal range</th><th>Unit</th><th>Price</th><?php if ($canManage): ?><th class="text-end">Actions</th><?php endif; ?></tr></thead>
      <tbody>
      <?php if (!$tests): ?><tr><td colspan="6" class="text-muted text-center py-4">No tests in the catalog yet.</td></tr><?php endif; ?>
      <?php foreach ($tests as $t): ?>
        <tr>
          <td><strong><?= e($t['name']) ?></strong><?php if ($t['description']): ?><br><small class="text-muted"><?= e($t['description']) ?></small><?php endif; ?></td>
          <td><?= $t['category'] ? '<span class="chip">' . e($t['category']) . '</span>' : '—' ?></td>
          <td><?= e($t['normal_range'] ?? '—') ?></td>
          <td><?= e($t['unit'] ?? '—') ?></td>
          <td><?= money((float)$t['price']) ?></td>
          <?php if ($canManage): ?>
          <td class="text-end">
            <a class="btn btn-sm btn-outline-secondary" href="laboratory.php?tab=catalog&edit_test=<?= (int)$t['id'] ?>" title="Edit"><i class="bi bi-pencil"></i></a>
            <form method="post" class="d-inline" data-confirm="Remove this test from the catalog? Existing results stay on record.">
              <?= csrf_field() ?><input type="hidden" name="action" value="delete_test"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><input type="hidden" name="tab" value="catalog">
              <button class="btn btn-sm btn-outline-danger" type="submit" title="Delete"><i class="bi bi-trash"></i></button>
            </form>
          </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php else: ?>
<div class="card">
  <div class="card-header">
    <form class="row g-2 align-items-center" method="get">
      <input type="hidden" name="tab" value="queue">
      <div class="col-md-3">
        <select class="form-select" name="status" aria-label="Filter by status">
          <option value="">All statuses</option>
          <?php foreach (['pending', 'in_progress', 'completed'] as $st): ?><option value="<?= $st ?>" <?= $statusF === $st ? 'selected' : '' ?>><?= ucfirst(str_replace('_', ' ', $st)) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2"><button class="btn btn-primary w-100" type="submit"><i class="bi bi-funnel"></i> Filter</button></div>
    </form>
  </div>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead><tr><th>Ordered</th><th>Patient</th><th>Test</th><th>Referred by</th><th>Result</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
      <?php if (!$queue): ?><tr><td colspan="7" class="text-muted text-center py-4">No lab requests match this filter.</td></tr><?php endif; ?>
      <?php foreach ($queue as $lr): ?>
        <tr>
          <td><small><?= fmt_datetime($lr['ordered_at']) ?></small></td>
          <td><a href="patients.php?id=<?= (int)$lr['patient_id'] ?>"><?= e($lr['patient_name']) ?></a> <small class="text-muted"><?= e($lr['patient_code']) ?></small></td>
          <td><strong><?= e($lr['test_name']) ?></strong><br><small class="text-muted">Normal: <?= e($lr['normal_range'] ?? '—') ?> <?= e($lr['unit'] ?? '') ?></small></td>
          <td><?= e($lr['doctor_name'] ?? '—') ?></td>
          <td><?= $lr['result_value'] !== null && $lr['result_value'] !== '' ? '<strong>' . e($lr['result_value']) . '</strong> ' . e($lr['unit'] ?? '') : '<span class="text-muted">Awaiting</span>' ?></td>
          <td><?= status_badge((string)$lr['status']) ?></td>
          <td class="text-end">
            <a class="btn btn-sm btn-outline-primary" href="laboratory.php?print=<?= (int)$lr['id'] ?>" target="_blank" rel="noopener" title="Print report"><i class="bi bi-printer"></i></a>
            <?php if ($canManage): ?>
            <button class="btn btn-sm btn-outline-success" type="button" data-bs-toggle="modal" data-bs-target="#resultModal" data-id="<?= (int)$lr['id'] ?>" data-test="<?= e($lr['test_name']) ?>" data-value="<?= e($lr['result_value'] ?? '') ?>" data-remarks="<?= e($lr['remarks'] ?? '') ?>" data-status="<?= e($lr['status']) ?>" title="Enter result"><i class="bi bi-pencil-square"></i> Result</button>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php if ($canManage): ?>
<div class="modal fade" id="orderModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" method="post">
      <div class="modal-header"><h5 class="modal-title">Order Lab Test</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body">
        <?= csrf_field() ?><input type="hidden" name="action" value="order_test">
        <div class="mb-3"><label class="form-label">Patient *</label>
          <select class="form-select" name="patient_id" required><option value="">Select patient…</option>
            <?php foreach ($patientsList as $p): ?><option value="<?= (int)$p['id'] ?>"><?= e($p['name']) ?> (<?= e($p['patient_code']) ?>)</option><?php endforeach; ?>
          </select></div>
        <div class="mb-3"><label class="form-label">Test *</label>
          <select class="form-select" name="lab_test_id" required><option value="">Select test…</option>
            <?php foreach ($tests as $t): ?><option value="<?= (int)$t['id'] ?>"><?= e($t['name']) ?> — <?= money((float)$t['price']) ?></option><?php endforeach; ?>
          </select></div>
        <div><label class="form-label">Referring doctor</label>
          <select class="form-select" name="doctor_id"><option value="">— None —</option>
            <?php foreach ($doctorsList as $d): ?><option value="<?= (int)$d['id'] ?>"><?= e($d['name']) ?></option><?php endforeach; ?>
          </select></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Place order</button></div>
    </form>
  </div>
</div>

<div class="modal fade" id="resultModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" method="post">
      <div class="modal-header"><h5 class="modal-title">Lab Result — <span id="lrTest"></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body">
        <?= csrf_field() ?><input type="hidden" name="action" value="save_result"><input type="hidden" name="id" id="lrId">
        <div class="row g-3">
          <div class="col-md-6"><label class="form-label">Result value</label><input class="form-control" name="result_value" id="lrValue" maxlength="120" placeholder="e.g. 13.5"></div>
          <div class="col-md-6"><label class="form-label">Status</label>
            <select class="form-select" name="status" id="lrStatus"><option value="pending">Pending</option><option value="in_progress">In progress</option><option value="completed">Completed</option></select></div>
          <div class="col-12"><label class="form-label">Remarks</label><input class="form-control" name="remarks" id="lrRemarks" maxlength="255" placeholder="e.g. Within normal limits"></div>
        </div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Save result</button></div>
    </form>
  </div>
</div>

<div class="modal fade" id="testModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" method="post">
      <div class="modal-header"><h5 class="modal-title"><?= $editTest ? 'Edit Lab Test' : 'Add Lab Test' ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body">
        <?= csrf_field() ?><input type="hidden" name="action" value="save_test"><input type="hidden" name="id" value="<?= (int)($editTest['id'] ?? 0) ?>"><input type="hidden" name="tab" value="catalog">
        <div class="row g-3">
          <div class="col-md-6"><label class="form-label">Name *</label><input class="form-control" name="name" required maxlength="120" value="<?= e($editTest['name'] ?? '') ?>"></div>
          <div class="col-md-6"><label class="form-label">Category</label><input class="form-control" name="category" maxlength="80" value="<?= e($editTest['category'] ?? '') ?>" placeholder="Hematology, Biochemistry…"></div>
          <div class="col-md-4"><label class="form-label">Price ($)</label><input class="form-control" type="number" name="price" min="0" step="0.01" value="<?= e((string)($editTest['price'] ?? '0')) ?>"></div>
          <div class="col-md-4"><label class="form-label">Normal range</label><input class="form-control" name="normal_range" maxlength="80" value="<?= e($editTest['normal_range'] ?? '') ?>" placeholder="13.0–17.0"></div>
          <div class="col-md-4"><label class="form-label">Unit</label><input class="form-control" name="unit" maxlength="30" value="<?= e($editTest['unit'] ?? '') ?>" placeholder="g/dL"></div>
          <div class="col-12"><label class="form-label">Description</label><input class="form-control" name="description" maxlength="255" value="<?= e($editTest['description'] ?? '') ?>"></div>
        </div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Save test</button></div>
    </form>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  var rm = document.getElementById('resultModal');
  if (rm) {
    rm.addEventListener('show.bs.modal', function (ev) {
      var b = ev.relatedTarget;
      document.getElementById('lrId').value = b.getAttribute('data-id');
      document.getElementById('lrTest').textContent = b.getAttribute('data-test');
      document.getElementById('lrValue').value = b.getAttribute('data-value') || '';
      document.getElementById('lrRemarks').value = b.getAttribute('data-remarks') || '';
      document.getElementById('lrStatus').value = b.getAttribute('data-status') || 'pending';
    });
  }
  <?php if ($editTest): ?>bootstrap.Modal.getOrCreateInstance(document.getElementById('testModal')).show();<?php endif; ?>
});
</script>
<?php endif; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
