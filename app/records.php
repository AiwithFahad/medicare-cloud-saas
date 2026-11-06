<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
$pdo = db();
$user = require_role(['hospital_admin', 'doctor', 'nurse']);
$tid = require_tenant();
check_tenant_active($pdo);
$canManage = in_array($user['role'], ['hospital_admin', 'doctor'], true) || has_permission('records.manage');
$managerRoles = in_array($user['role'], ['hospital_admin', 'doctor'], true);

if (is_post()) {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'create' && $canManage) {
        $patientId = (int)($_POST['patient_id'] ?? 0);
        $type = (string)($_POST['record_type'] ?? 'note');
        if (!in_array($type, ['diagnosis', 'observation', 'document', 'note'], true)) $type = 'note';
        $title = trim((string)($_POST['title'] ?? ''));
        $details = trim((string)($_POST['details'] ?? '')) ?: null;
        $visibility = (string)($_POST['visibility'] ?? 'staff');
        if (!in_array($visibility, ['staff', 'patient'], true)) $visibility = 'staff';
        $apptId = (int)($_POST['appointment_id'] ?? 0) ?: null;
        $pv = $pdo->prepare('SELECT id FROM patients WHERE id=? AND tenant_id=?'); $pv->execute([$patientId, $tid]);
        if ($apptId) {
            $av = $pdo->prepare('SELECT id FROM appointments WHERE id=? AND tenant_id=? AND patient_id=?'); $av->execute([$apptId, $tid, $patientId]);
            if (!$av->fetch()) $apptId = null;
        }
        if (!$pv->fetch() || $title === '') { flash('error', 'Choose a patient and give the record a title.'); redirect('records.php'); }
        // Link to a doctor profile when the signed-in user is one
        $doctorId = null;
        $dv = $pdo->prepare('SELECT id FROM doctors WHERE user_id=? AND tenant_id=? LIMIT 1'); $dv->execute([(int)$user['id'], $tid]);
        $doctorId = $dv->fetchColumn() ?: null;
        $ins = $pdo->prepare('INSERT INTO medical_records(tenant_id,patient_id,doctor_id,appointment_id,record_type,title,details,visibility,created_at) VALUES(?,?,?,?,?,?,?,?,NOW())');
        $ins->execute([$tid, $patientId, $doctorId, $apptId, $type, $title, $details, $visibility]);
        $rid = (int)$pdo->lastInsertId();
        audit_log($pdo, 'record.create', 'medical_record', $rid, $title);
        flash('success', 'Medical record added.');
        redirect('records.php?patient=' . $patientId);
    }
    if ($action === 'delete' && $managerRoles) {
        $id = (int)($_POST['id'] ?? 0);
        $patientId = (int)($_POST['patient_id'] ?? 0);
        $d = $pdo->prepare('DELETE FROM medical_records WHERE id=? AND tenant_id=?');
        $d->execute([$id, $tid]);
        audit_log($pdo, 'record.delete', 'medical_record', $id, '');
        flash('success', 'Record deleted.');
        redirect('records.php' . ($patientId ? '?patient=' . $patientId : ''));
    }
    flash('error', 'You do not have permission for that action.');
    redirect('records.php');
}

$ps = $pdo->prepare('SELECT id,name,patient_code FROM patients WHERE tenant_id=? ORDER BY name');
$ps->execute([$tid]);
$patients = $ps->fetchAll();

$patientId = (int)($_GET['patient'] ?? 0);
$patient = null; $timeline = []; $patientAppts = [];
if ($patientId) {
    $vs = $pdo->prepare('SELECT * FROM patients WHERE id=? AND tenant_id=?');
    $vs->execute([$patientId, $tid]);
    $patient = $vs->fetch() ?: null;
}
if ($patient) {
    // Appointments for the link dropdown + timeline
    $as = $pdo->prepare('SELECT a.*, d.name AS doctor_name FROM appointments a JOIN doctors d ON d.id=a.doctor_id WHERE a.tenant_id=? AND a.patient_id=? ORDER BY a.appointment_date DESC, a.appointment_time DESC');
    $as->execute([$tid, $patientId]);
    $patientAppts = $as->fetchAll();
    foreach ($patientAppts as $a) {
        $timeline[] = ['when' => $a['appointment_date'] . ' ' . $a['appointment_time'], 'kind' => 'Appointment', 'icon' => 'bi-calendar-check', 'title' => $a['type'] . ' with ' . $a['doctor_name'], 'detail' => trim(($a['reason'] ?? '') . ' · Status: ' . $a['status']), 'badge' => status_badge((string)$a['status']), 'record_id' => null];
    }
    $rs = $pdo->prepare('SELECT mr.*, d.name AS doctor_name FROM medical_records mr LEFT JOIN doctors d ON d.id=mr.doctor_id WHERE mr.tenant_id=? AND mr.patient_id=? ORDER BY mr.created_at DESC');
    $rs->execute([$tid, $patientId]);
    foreach ($rs->fetchAll() as $r) {
        $timeline[] = ['when' => $r['created_at'], 'kind' => ucfirst((string)$r['record_type']), 'icon' => 'bi-file-medical', 'title' => $r['title'], 'detail' => (string)($r['details'] ?? '') . ($r['doctor_name'] ? ' — ' . $r['doctor_name'] : '') . ' · Visible to: ' . $r['visibility'], 'badge' => '', 'record_id' => (int)$r['id']];
    }
    $ls = $pdo->prepare('SELECT lr.*, lt.name AS test_name FROM lab_results lr JOIN lab_tests lt ON lt.id=lr.lab_test_id WHERE lr.tenant_id=? AND lr.patient_id=? ORDER BY lr.ordered_at DESC');
    $ls->execute([$tid, $patientId]);
    foreach ($ls->fetchAll() as $l) {
        $timeline[] = ['when' => $l['ordered_at'], 'kind' => 'Lab result', 'icon' => 'bi-droplet-half', 'title' => $l['test_name'] . ($l['result_value'] ? ': ' . $l['result_value'] : ''), 'detail' => (string)($l['remarks'] ?? ''), 'badge' => status_badge((string)$l['status']), 'record_id' => null];
    }
    $xs = $pdo->prepare('SELECT pr.*, d.name AS doctor_name FROM prescriptions pr JOIN doctors d ON d.id=pr.doctor_id WHERE pr.tenant_id=? AND pr.patient_id=? ORDER BY pr.prescription_date DESC, pr.id DESC');
    $xs->execute([$tid, $patientId]);
    foreach ($xs->fetchAll() as $x) {
        $timeline[] = ['when' => $x['prescription_date'] . ' 00:00:00', 'kind' => 'Prescription', 'icon' => 'bi-capsule', 'title' => 'Prescription by ' . $x['doctor_name'], 'detail' => (string)($x['diagnosis'] ?? ''), 'badge' => status_badge((string)$x['status']), 'record_id' => null];
    }
    usort($timeline, fn($a, $b) => strcmp((string)$b['when'], (string)$a['when']));
}

$pageTitle = 'Medical Records';
$base = '../';
include __DIR__ . '/../includes/header.php';
?>
<div class="row g-4">
  <div class="col-lg-4">
    <div class="card mb-4"><div class="card-header">Browse by patient</div><div class="card-body">
      <form method="get"><select name="patient" class="form-select" onchange="this.form.submit()"><option value="">Select patient…</option>
      <?php foreach ($patients as $p): ?><option value="<?= (int)$p['id'] ?>" <?= $patientId === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?> (<?= e($p['patient_code']) ?>)</option><?php endforeach; ?>
      </select></form>
      <?php if ($patient): ?>
      <hr>
      <h5 class="mb-1"><?= e($patient['name']) ?></h5>
      <div class="small text-muted">
        <?= e($patient['patient_code']) ?> · <?= e(ucfirst((string)$patient['gender'])) ?><?= $patient['dob'] ? ' · DOB ' . fmt_date($patient['dob']) : '' ?><?= $patient['blood_group'] ? ' · Blood ' . e((string)$patient['blood_group']) : '' ?><br>
        <?= $patient['phone'] ? e((string)$patient['phone']) : '' ?><?= $patient['email'] ? ' · ' . e((string)$patient['email']) : '' ?>
        <?php if ($patient['allergies']): ?><br><span class="badge text-bg-danger mt-1">Allergies: <?= e((string)$patient['allergies']) ?></span><?php endif; ?>
      </div>
      <?php endif; ?>
    </div></div>

    <?php if ($canManage): ?>
    <div class="card"><div class="card-header">Add medical record</div><div class="card-body">
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="create">
        <div class="mb-2"><label class="form-label">Patient *</label><select name="patient_id" class="form-select" required><option value="">Select patient…</option><?php foreach ($patients as $p): ?><option value="<?= (int)$p['id'] ?>" <?= $patientId === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?> (<?= e($p['patient_code']) ?>)</option><?php endforeach; ?></select></div>
        <div class="mb-2"><label class="form-label">Type</label><select name="record_type" class="form-select"><option value="diagnosis">Diagnosis</option><option value="observation">Observation</option><option value="document">Document</option><option value="note" selected>Note</option></select></div>
        <div class="mb-2"><label class="form-label">Title *</label><input name="title" class="form-control" required maxlength="200"></div>
        <div class="mb-2"><label class="form-label">Details</label><textarea name="details" class="form-control" rows="4"></textarea></div>
        <div class="mb-2"><label class="form-label">Visibility</label><select name="visibility" class="form-select"><option value="staff">Staff only</option><option value="patient">Shared with patient</option></select></div>
        <?php if ($patient && $patientAppts): ?>
        <div class="mb-3"><label class="form-label">Link to appointment (optional)</label><select name="appointment_id" class="form-select"><option value="">— None —</option><?php foreach ($patientAppts as $a): ?><option value="<?= (int)$a['id'] ?>"><?= fmt_date($a['appointment_date']) ?> — <?= e($a['type']) ?> with <?= e($a['doctor_name']) ?></option><?php endforeach; ?></select></div>
        <?php endif; ?>
        <button class="btn btn-primary w-100">Add record</button>
      </form>
    </div></div>
    <?php endif; ?>
  </div>

  <div class="col-lg-8">
    <div class="card"><div class="card-header">Patient history<?= $patient ? ' — ' . e($patient['name']) : '' ?></div>
      <div class="card-body">
      <?php if (!$patient): ?>
        <div class="empty-state"><img src="../assets/img/empty.svg" alt=""><p>Select a patient to see their full history timeline — records, appointments, lab results and prescriptions in date order.</p></div>
      <?php elseif (!$timeline): ?>
        <div class="empty-state">No history yet for this patient.</div>
      <?php else: ?>
        <?php foreach ($timeline as $ev): ?>
        <div class="d-flex gap-3 pb-3 mb-3 border-bottom">
          <span class="stat-ic ic-blue" style="width:42px;height:42px;font-size:1.05rem"><i class="bi <?= e($ev['icon']) ?>"></i></span>
          <div class="flex-grow-1">
            <div class="d-flex flex-wrap gap-2 justify-content-between"><strong><?= e($ev['title']) ?></strong><small class="text-muted"><?= fmt_datetime($ev['when']) ?></small></div>
            <div class="small text-muted"><?= e($ev['kind']) ?><?= $ev['badge'] ? ' · ' . $ev['badge'] : '' ?></div>
            <?php if (trim((string)$ev['detail']) !== ''): ?><p class="small mb-0 mt-1"><?= e((string)$ev['detail']) ?></p><?php endif; ?>
          </div>
          <?php if ($ev['record_id'] && $managerRoles): ?>
          <form method="post" data-confirm="Delete this record?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$ev['record_id'] ?>"><input type="hidden" name="patient_id" value="<?= $patientId ?>"><button class="btn btn-sm btn-outline-danger align-self-start">Delete</button></form>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
