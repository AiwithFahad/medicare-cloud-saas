<?php
declare(strict_types=1);
/* Doctor — My Patients (Upgrade v2 page tree).
 * "My patients" = patients this doctor has appointments, prescriptions,
 * records or admissions with (derived live from those tables). Opening a
 * patient shows their full medical timeline plus a form to add a record
 * under this doctor's name. */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/panel.php';
$pdo = db();
$user = require_role(['doctor']);
$tid = require_tenant();
check_tenant_active($pdo);
$doctor = panel_doctor($pdo, $tid, (int)$user['id']);
if (!$doctor) {
    flash('error', 'Your login is not linked to a doctor profile yet. Ask your hospital admin to link it from the Doctors page.');
    redirect('../app/dashboard.php');
}
$did = (int)$doctor['id'];
$base = '../';
$panelSidebar = 'doctor';

$profileId = (int)($_GET['id'] ?? 0);
$patient = null;
if ($profileId > 0) {
    $ps = $pdo->prepare('SELECT * FROM patients WHERE id = ? AND tenant_id = ?');
    $ps->execute([$profileId, $tid]);
    $patient = $ps->fetch() ?: null;
    if (!$patient) {
        flash('error', 'Patient not found.');
        redirect('patients.php');
    }
}

if (is_post() && $patient) {
    verify_csrf();
    if (($_POST['action'] ?? '') === 'add_record' && has_permission('records.manage')) {
        $type = in_array(($_POST['record_type'] ?? ''), ['diagnosis', 'observation', 'document', 'note'], true) ? (string)$_POST['record_type'] : 'note';
        $title = trim((string)($_POST['record_title'] ?? ''));
        if ($title === '') {
            flash('error', 'Record title is required.');
        } else {
            $apptId = (int)($_POST['appointment_id'] ?? 0) ?: null;
            $pdo->prepare('INSERT INTO medical_records (tenant_id, patient_id, doctor_id, appointment_id, record_type, title, details, visibility) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$tid, $profileId, $did, $apptId, $type, $title, trim((string)($_POST['record_details'] ?? '')) ?: null, ($_POST['visibility'] ?? 'staff') === 'patient' ? 'patient' : 'staff']);
            audit_log($pdo, 'medical_record_added', 'medical_records', (int)$pdo->lastInsertId(), 'Doctor added record for patient #' . $profileId);
            flash('success', 'Record added to the patient timeline.');
        }
    }
    redirect('patients.php?id=' . $profileId);
}

if ($patient) {
    $pid = (int)$patient['id'];
    $timeline = [];
    $rs = $pdo->prepare('SELECT mr.*, d.name AS doctor_name FROM medical_records mr LEFT JOIN doctors d ON d.id = mr.doctor_id WHERE mr.tenant_id = ? AND mr.patient_id = ? ORDER BY mr.created_at DESC LIMIT 30');
    $rs->execute([$tid, $pid]);
    foreach ($rs->fetchAll() as $r) {
        $timeline[] = ['ts' => $r['created_at'], 'cls' => '', 'icon' => 'bi-file-medical', 'title' => ucfirst((string)$r['record_type']) . ' — ' . $r['title'], 'body' => (string)($r['details'] ?? ''), 'meta' => ($r['doctor_name'] ? 'By ' . $r['doctor_name'] . ' · ' : '') . ($r['visibility'] === 'patient' ? 'Shared with patient' : 'Staff only')];
    }
    $rs = $pdo->prepare('SELECT pr.*, d.name AS doctor_name FROM prescriptions pr JOIN doctors d ON d.id = pr.doctor_id WHERE pr.tenant_id = ? AND pr.patient_id = ? ORDER BY pr.id DESC LIMIT 15');
    $rs->execute([$tid, $pid]);
    foreach ($rs->fetchAll() as $r) {
        $timeline[] = ['ts' => $r['created_at'], 'cls' => 'tl-green', 'icon' => 'bi-prescription2', 'title' => 'Prescription by ' . $r['doctor_name'], 'body' => (string)($r['diagnosis'] ?? ''), 'meta' => 'Status: ' . str_replace('_', ' ', (string)$r['status'])];
    }
    $rs = $pdo->prepare('SELECT lr.*, lt.name AS test_name FROM lab_results lr JOIN lab_tests lt ON lt.id = lr.lab_test_id WHERE lr.tenant_id = ? AND lr.patient_id = ? ORDER BY lr.id DESC LIMIT 15');
    $rs->execute([$tid, $pid]);
    foreach ($rs->fetchAll() as $r) {
        $timeline[] = ['ts' => (string)$r['ordered_at'], 'cls' => 'tl-amber', 'icon' => 'bi-droplet-half', 'title' => 'Lab — ' . $r['test_name'], 'body' => $r['result_value'] ? 'Result: ' . $r['result_value'] : 'Awaiting result', 'meta' => 'Status: ' . str_replace('_', ' ', (string)$r['status'])];
    }
    $rs = $pdo->prepare('SELECT a.*, d.name AS doctor_name FROM appointments a JOIN doctors d ON d.id = a.doctor_id WHERE a.tenant_id = ? AND a.patient_id = ? ORDER BY a.appointment_date DESC LIMIT 10');
    $rs->execute([$tid, $pid]);
    foreach ($rs->fetchAll() as $r) {
        $timeline[] = ['ts' => $r['appointment_date'] . ' ' . $r['appointment_time'], 'cls' => 'tl-green', 'icon' => 'bi-calendar-check', 'title' => 'Appointment — ' . $r['doctor_name'], 'body' => (string)($r['reason'] ?? ''), 'meta' => ucfirst((string)$r['status']) . ' · ' . $r['type']];
    }
    usort($timeline, static fn($a, $b) => strcmp((string)$b['ts'], (string)$a['ts']));

    $docs = $pdo->prepare("SELECT * FROM patient_documents WHERE tenant_id = ? AND patient_id = ? ORDER BY id DESC");
    $docs->execute([$tid, $pid]);
    $docs = $docs->fetchAll();

    $pageTitle = 'Patient — ' . $patient['name'];
    include __DIR__ . '/../includes/header.php';
    ?>
    <p><a href="patients.php" class="small"><i class="bi bi-arrow-left"></i> Back to my patients</a></p>
    <div class="row g-3">
      <div class="col-lg-4">
        <div class="card">
          <div class="card-body">
            <div class="d-flex align-items-center gap-3 mb-3">
              <span class="avatar-initials" style="width:56px;height:56px;font-size:1.2rem"><?= e(initials($patient['name'])) ?></span>
              <span><strong class="d-block fs-5"><?= e($patient['name']) ?></strong><span class="chip"><?= e($patient['patient_code']) ?></span>
              <?php if ($patient['allergies']): ?><span class="badge text-bg-danger ms-1"><i class="bi bi-exclamation-triangle"></i> <?= e($patient['allergies']) ?></span><?php endif; ?></span>
            </div>
            <dl class="row small mb-0">
              <dt class="col-5 text-muted">Age / Gender</dt><dd class="col-7"><?= e(patient_age($patient['dob'])) ?> · <?= e(ucfirst((string)($patient['gender'] ?? '—'))) ?></dd>
              <dt class="col-5 text-muted">Blood group</dt><dd class="col-7"><?= e($patient['blood_group'] ?? '—') ?></dd>
              <dt class="col-5 text-muted">Phone</dt><dd class="col-7"><?= e($patient['phone'] ?? '—') ?></dd>
              <dt class="col-5 text-muted">Email</dt><dd class="col-7"><?= e($patient['email'] ?? '—') ?></dd>
              <dt class="col-5 text-muted">Emergency contact</dt><dd class="col-7"><?= e($patient['emergency_contact'] ?? '—') ?></dd>
            </dl>
            <a class="btn btn-success w-100 mt-3" href="prescriptions.php?patient=<?= $pid ?>"><i class="bi bi-prescription2"></i> Write prescription</a>
            <?php if ($docs): ?>
            <h6 class="mt-4">Documents on file</h6>
            <?php foreach ($docs as $d): ?>
              <div class="d-flex align-items-center gap-2 py-1"><span class="doc-thumb"><i class="bi bi-file-earmark-text"></i></span><span class="small"><?= e($d['title']) ?><br><small class="text-muted"><?= fmt_date($d['created_at']) ?> · <?= $d['visibility'] === 'patient' ? 'Patient can see' : 'Staff only' ?></small></span></div>
            <?php endforeach; ?>
            <p class="text-muted small mt-2 mb-0">Document files are managed (upload/download) by the admin &amp; front desk.</p>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <div class="col-lg-8">
        <?php if (has_permission('records.manage')): ?>
        <div class="card mb-3">
          <div class="card-header"><i class="bi bi-file-medical me-1"></i>Add a record for this patient</div>
          <div class="card-body">
            <form method="post" class="row g-2">
              <?= csrf_field() ?><input type="hidden" name="action" value="add_record">
              <div class="col-md-3"><label class="form-label small">Type</label>
                <select class="form-select" name="record_type"><option value="diagnosis">Diagnosis</option><option value="observation">Observation</option><option value="note">Note</option><option value="document">Document</option></select></div>
              <div class="col-md-5"><label class="form-label small">Title *</label><input class="form-control" name="record_title" required maxlength="200" placeholder="e.g. Hypertension — stage 1"></div>
              <div class="col-md-4"><label class="form-label small">Visibility</label>
                <select class="form-select" name="visibility"><option value="staff">Staff only</option><option value="patient">Share with patient</option></select></div>
              <div class="col-12"><label class="form-label small">Details</label><textarea class="form-control" name="record_details" rows="2" placeholder="Findings, plan, advice…"></textarea></div>
              <div class="col-12"><button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-plus-lg"></i> Add record</button></div>
            </form>
          </div>
        </div>
        <?php endif; ?>
        <div class="card">
          <div class="card-header"><i class="bi bi-clock-history me-1"></i>Medical timeline</div>
          <div class="card-body">
            <?php if (!$timeline): ?><div class="empty-state py-4"><i class="bi bi-clock-history"></i><p class="mb-0">No history yet for this patient.</p></div><?php endif; ?>
            <ul class="timeline">
              <?php foreach ($timeline as $ev): ?>
                <li class="<?= e($ev['cls']) ?>">
                  <strong class="d-block small"><i class="bi <?= e($ev['icon']) ?> me-1"></i><?= e($ev['title']) ?></strong>
                  <?php if ($ev['body'] !== ''): ?><span class="d-block small text-muted"><?= e($ev['body']) ?></span><?php endif; ?>
                  <small class="text-muted"><?= e($ev['meta']) ?> · <?= fmt_datetime($ev['ts']) ?></small>
                </li>
              <?php endforeach; ?>
            </ul>
          </div>
        </div>
      </div>
    </div>
    <?php include __DIR__ . '/../includes/footer.php';
    return;
}

$q = trim((string)($_GET['q'] ?? ''));
$where = 'p.tenant_id = ? AND (
    EXISTS (SELECT 1 FROM appointments a WHERE a.patient_id = p.id AND a.doctor_id = ? AND a.tenant_id = p.tenant_id)
    OR EXISTS (SELECT 1 FROM prescriptions pr WHERE pr.patient_id = p.id AND pr.doctor_id = ? AND pr.tenant_id = p.tenant_id)
    OR EXISTS (SELECT 1 FROM medical_records mr WHERE mr.patient_id = p.id AND mr.doctor_id = ? AND mr.tenant_id = p.tenant_id)
    OR EXISTS (SELECT 1 FROM admissions ad WHERE ad.patient_id = p.id AND ad.doctor_id = ? AND ad.tenant_id = p.tenant_id)
)';
if ($q !== '') {
    $where .= ' AND (p.name LIKE ? OR p.patient_code LIKE ?)';
}
$s = $pdo->prepare(
    "SELECT p.*,
            (SELECT COUNT(*) FROM appointments a WHERE a.patient_id = p.id AND a.doctor_id = ? AND a.tenant_id = p.tenant_id) AS my_visits,
            (SELECT MAX(a.appointment_date) FROM appointments a WHERE a.patient_id = p.id AND a.doctor_id = ? AND a.tenant_id = p.tenant_id) AS last_visit
       FROM patients p WHERE {$where} ORDER BY last_visit DESC, p.name"
);
// Placeholder order: the two SELECT subqueries, then the WHERE clause.
$params = array_merge([$did, $did], [$tid, $did, $did, $did, $did], $q !== '' ? ['%' . $q . '%', '%' . $q . '%'] : []);
$s->execute($params);
$patients = $s->fetchAll();

$pageTitle = 'My Patients';
include __DIR__ . '/../includes/header.php';
?>
<div class="card">
  <div class="card-header">
    <form class="row g-2 align-items-center" method="get">
      <div class="col-md-5"><input class="form-control" type="search" name="q" value="<?= e($q) ?>" placeholder="Search my patients…" aria-label="Search my patients"></div>
      <div class="col-md-2"><button class="btn btn-primary w-100" type="submit"><i class="bi bi-search"></i> Search</button></div>
      <div class="col-md-5 text-md-end"><span class="text-muted small"><?= count($patients) ?> patients under your care</span></div>
    </form>
  </div>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead><tr><th>Patient</th><th>Age / Gender</th><th>Blood</th><th>Allergies</th><th>My visits</th><th>Last visit</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
      <?php if (!$patients): ?><tr><td colspan="7"><div class="empty-state py-4"><i class="bi bi-people"></i><p class="mb-0">No patients yet — patients appear here after their first appointment, prescription, record or admission with you.</p></div></td></tr><?php endif; ?>
      <?php foreach ($patients as $p): ?>
        <tr>
          <td><div class="d-flex align-items-center gap-2"><span class="avatar-initials"><?= e(initials($p['name'])) ?></span><span><strong class="d-block"><?= e($p['name']) ?></strong><small class="text-muted"><?= e($p['patient_code']) ?></small></span></div></td>
          <td><?= e(patient_age($p['dob'])) ?> · <?= e(ucfirst((string)($p['gender'] ?? '—'))) ?></td>
          <td><?= e($p['blood_group'] ?? '—') ?></td>
          <td><?= $p['allergies'] ? '<span class="badge text-bg-danger">' . e($p['allergies']) . '</span>' : '—' ?></td>
          <td><?= (int)$p['my_visits'] ?></td>
          <td><?= $p['last_visit'] ? fmt_date($p['last_visit']) : '—' ?></td>
          <td class="text-end">
            <a class="btn btn-sm btn-outline-primary" href="patients.php?id=<?= (int)$p['id'] ?>"><i class="bi bi-eye"></i> Open</a>
            <a class="btn btn-sm btn-outline-success" href="prescriptions.php?patient=<?= (int)$p['id'] ?>"><i class="bi bi-prescription2"></i></a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
