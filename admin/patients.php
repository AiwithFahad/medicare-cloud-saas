<?php
declare(strict_types=1);
/* Hospital Admin — Patients (Upgrade v2 page tree).
 * List: live search / filter / pagination through api/patients.php
 * (MediCare.liveTable) with AJAX create/edit/delete in a modal.
 * Profile (?id=N): details, medical timeline (records, prescriptions,
 * labs, admissions, appointments), documents (upload / download / delete
 * through a permission-checked streamer) and an add-record form. */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/panel.php';
$pdo = db();
$user = require_role(['hospital_admin']);
$tid = require_tenant();
check_tenant_active($pdo);
if (!has_permission('patients.view')) {
    flash('error', 'You do not have permission to view patients.');
    redirect('dashboard.php');
}
$canManage = has_permission('patients.manage');
$base = '../';
$panelSidebar = 'admin';

/* ---------------- Document download (streams, then exits) ---------------- */
$docId = (int)($_GET['doc'] ?? 0);
if ($docId > 0) {
    $ds = $pdo->prepare('SELECT * FROM patient_documents WHERE id = ? AND tenant_id = ?');
    $ds->execute([$docId, $tid]);
    $doc = $ds->fetch();
    $abs = $doc ? realpath(__DIR__ . '/../' . $doc['file_path']) : false;
    $root = realpath(__DIR__ . '/../storage/documents');
    if (!$doc || !$abs || !$root || !str_starts_with($abs, $root) || !is_file($abs)) {
        http_response_code(404);
        exit('Document not found.');
    }
    header('Content-Type: ' . ($doc['file_type'] ?: 'application/octet-stream'));
    header('Content-Disposition: attachment; filename="' . basename((string)$doc['file_name']) . '"');
    header('Content-Length: ' . filesize($abs));
    readfile($abs);
    exit;
}

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

/* ---------------- Profile POST actions ---------------- */
if (is_post() && $patient) {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    if (!$canManage) {
        flash('error', 'You do not have permission to modify patients.');
        redirect('patients.php?id=' . $profileId);
    }
    if ($action === 'upload_document') {
        $title = trim((string)($_POST['title'] ?? ''));
        $visibility = ($_POST['visibility'] ?? 'staff') === 'patient' ? 'patient' : 'staff';
        $file = $_FILES['document'] ?? null;
        if ($title === '' || !$file || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            flash('error', 'Choose a file and give it a title.');
        } elseif ((int)$file['size'] > 10 * 1024 * 1024) {
            flash('error', 'File is too large (max 10 MB).');
        } else {
            $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
            $allowed = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'txt', 'doc', 'docx'];
            if (!in_array($ext, $allowed, true)) {
                flash('error', 'That file type is not allowed.');
            } else {
                $stored = 'doc-' . $tid . '-' . $profileId . '-' . bin2hex(random_bytes(6)) . '.' . $ext;
                $dest = __DIR__ . '/../storage/documents/' . $stored;
                if (move_uploaded_file((string)$file['tmp_name'], $dest)) {
                    $pdo->prepare('INSERT INTO patient_documents (tenant_id, patient_id, uploaded_by, title, file_name, file_path, file_type, file_size, visibility) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
                        ->execute([$tid, $profileId, (int)$user['id'], $title, (string)$file['name'], 'storage/documents/' . $stored, (string)($file['type'] ?? ''), (int)$file['size'], $visibility]);
                    audit_log($pdo, 'patient_document_added', 'patient_documents', (int)$pdo->lastInsertId(), 'Document added for patient #' . $profileId);
                    flash('success', 'Document uploaded.');
                } else {
                    flash('error', 'Upload failed on the server.');
                }
            }
        }
    } elseif ($action === 'delete_document') {
        $delId = (int)($_POST['document_id'] ?? 0);
        $ds = $pdo->prepare('SELECT * FROM patient_documents WHERE id = ? AND tenant_id = ? AND patient_id = ?');
        $ds->execute([$delId, $tid, $profileId]);
        $doc = $ds->fetch();
        if ($doc) {
            $abs = __DIR__ . '/../' . $doc['file_path'];
            if (is_file($abs)) {
                unlink($abs);
            }
            $pdo->prepare('DELETE FROM patient_documents WHERE id = ?')->execute([$delId]);
            flash('success', 'Document deleted.');
        }
    } elseif ($action === 'add_record') {
        $type = in_array(($_POST['record_type'] ?? ''), ['diagnosis', 'observation', 'document', 'note'], true) ? (string)$_POST['record_type'] : 'note';
        $title = trim((string)($_POST['record_title'] ?? ''));
        $details = trim((string)($_POST['record_details'] ?? '')) ?: null;
        $visibility = ($_POST['visibility'] ?? 'staff') === 'patient' ? 'patient' : 'staff';
        if ($title === '') {
            flash('error', 'Record title is required.');
        } else {
            $pdo->prepare('INSERT INTO medical_records (tenant_id, patient_id, doctor_id, record_type, title, details, visibility) VALUES (?, ?, NULL, ?, ?, ?, ?)')
                ->execute([$tid, $profileId, $type, $title, $details, $visibility]);
            audit_log($pdo, 'medical_record_added', 'medical_records', (int)$pdo->lastInsertId(), 'Record added for patient #' . $profileId);
            flash('success', 'Medical record added to the timeline.');
        }
    }
    redirect('patients.php?id=' . $profileId);
}

/* ============================ PROFILE VIEW ============================ */
if ($patient) {
    $pid = (int)$patient['id'];
    $docs = $pdo->prepare('SELECT * FROM patient_documents WHERE tenant_id = ? AND patient_id = ? ORDER BY id DESC');
    $docs->execute([$tid, $pid]);
    $docs = $docs->fetchAll();

    $timeline = [];
    $rs = $pdo->prepare('SELECT * FROM medical_records WHERE tenant_id = ? AND patient_id = ? ORDER BY created_at DESC LIMIT 30');
    $rs->execute([$tid, $pid]);
    foreach ($rs->fetchAll() as $r) {
        $timeline[] = ['ts' => $r['created_at'], 'cls' => '', 'icon' => 'bi-file-medical', 'title' => ucfirst((string)$r['record_type']) . ' — ' . $r['title'], 'body' => (string)($r['details'] ?? ''), 'meta' => 'Medical record · ' . ($r['visibility'] === 'patient' ? 'shared with patient' : 'staff only')];
    }
    $rs = $pdo->prepare('SELECT pr.*, d.name AS doctor_name FROM prescriptions pr JOIN doctors d ON d.id = pr.doctor_id WHERE pr.tenant_id = ? AND pr.patient_id = ? ORDER BY pr.id DESC LIMIT 20');
    $rs->execute([$tid, $pid]);
    foreach ($rs->fetchAll() as $r) {
        $timeline[] = ['ts' => $r['created_at'], 'cls' => 'tl-green', 'icon' => 'bi-prescription2', 'title' => 'Prescription by ' . $r['doctor_name'], 'body' => (string)($r['diagnosis'] ?? ''), 'meta' => 'Status: ' . str_replace('_', ' ', (string)$r['status'])];
    }
    $rs = $pdo->prepare('SELECT lr.*, lt.name AS test_name FROM lab_results lr JOIN lab_tests lt ON lt.id = lr.lab_test_id WHERE lr.tenant_id = ? AND lr.patient_id = ? ORDER BY lr.id DESC LIMIT 20');
    $rs->execute([$tid, $pid]);
    foreach ($rs->fetchAll() as $r) {
        $timeline[] = ['ts' => (string)$r['ordered_at'], 'cls' => 'tl-amber', 'icon' => 'bi-droplet-half', 'title' => 'Lab — ' . $r['test_name'], 'body' => $r['result_value'] ? 'Result: ' . $r['result_value'] : 'Awaiting result', 'meta' => 'Status: ' . str_replace('_', ' ', (string)$r['status'])];
    }
    $rs = $pdo->prepare('SELECT ad.*, w.name AS ward_name, b.bed_number FROM admissions ad JOIN wards w ON w.id = ad.ward_id JOIN beds b ON b.id = ad.bed_id WHERE ad.tenant_id = ? AND ad.patient_id = ? ORDER BY ad.id DESC LIMIT 10');
    $rs->execute([$tid, $pid]);
    foreach ($rs->fetchAll() as $r) {
        $timeline[] = ['ts' => (string)$r['admission_date'], 'cls' => '', 'icon' => 'bi-hospital', 'title' => 'Admission — ' . $r['ward_name'] . ' / Bed ' . $r['bed_number'], 'body' => (string)($r['reason'] ?? ''), 'meta' => 'Status: ' . $r['status'] . ($r['discharge_date'] ? ' · discharged ' . fmt_date($r['discharge_date']) : '')];
    }
    $rs = $pdo->prepare('SELECT a.*, d.name AS doctor_name FROM appointments a JOIN doctors d ON d.id = a.doctor_id WHERE a.tenant_id = ? AND a.patient_id = ? ORDER BY a.appointment_date DESC, a.appointment_time DESC LIMIT 10');
    $rs->execute([$tid, $pid]);
    $patientAppts = $rs->fetchAll();
    foreach ($patientAppts as $r) {
        $timeline[] = ['ts' => $r['appointment_date'] . ' ' . $r['appointment_time'], 'cls' => 'tl-green', 'icon' => 'bi-calendar-check', 'title' => 'Appointment — ' . $r['doctor_name'], 'body' => (string)($r['reason'] ?? ''), 'meta' => ucfirst((string)$r['status']) . ' · ' . $r['type']];
    }
    usort($timeline, static fn($a, $b) => strcmp((string)$b['ts'], (string)$a['ts']));

    $invs = $pdo->prepare('SELECT * FROM patient_invoices WHERE tenant_id = ? AND patient_id = ? ORDER BY id DESC LIMIT 8');
    $invs->execute([$tid, $pid]);
    $invs = $invs->fetchAll();

    $pageTitle = 'Patient — ' . $patient['name'];
    include __DIR__ . '/../includes/header.php';
    ?>
    <p><a href="patients.php" class="small"><i class="bi bi-arrow-left"></i> Back to patients</a></p>
    <div class="row g-3">
      <div class="col-lg-4">
        <div class="card mb-3">
          <div class="card-body">
            <div class="d-flex align-items-center gap-3 mb-3">
              <span class="avatar-initials" style="width:56px;height:56px;font-size:1.2rem"><?= e(initials($patient['name'])) ?></span>
              <span>
                <strong class="d-block fs-5"><?= e($patient['name']) ?></strong>
                <span class="chip"><?= e($patient['patient_code']) ?></span>
                <?php if ($patient['allergies']): ?><span class="badge text-bg-danger ms-1" title="Allergies"><i class="bi bi-exclamation-triangle"></i> <?= e($patient['allergies']) ?></span><?php endif; ?>
              </span>
            </div>
            <dl class="row small mb-0">
              <dt class="col-5 text-muted">Age / Gender</dt><dd class="col-7"><?= e(patient_age($patient['dob'])) ?> · <?= e(ucfirst((string)($patient['gender'] ?? '—'))) ?></dd>
              <dt class="col-5 text-muted">Date of birth</dt><dd class="col-7"><?= $patient['dob'] ? fmt_date($patient['dob']) : '—' ?></dd>
              <dt class="col-5 text-muted">Blood group</dt><dd class="col-7"><?= e($patient['blood_group'] ?? '—') ?></dd>
              <dt class="col-5 text-muted">Phone</dt><dd class="col-7"><?= e($patient['phone'] ?? '—') ?></dd>
              <dt class="col-5 text-muted">Email</dt><dd class="col-7"><?= e($patient['email'] ?? '—') ?></dd>
              <dt class="col-5 text-muted">Address</dt><dd class="col-7"><?= e($patient['address'] ?? '—') ?></dd>
              <dt class="col-5 text-muted">Emergency contact</dt><dd class="col-7"><?= e($patient['emergency_contact'] ?? '—') ?></dd>
              <dt class="col-5 text-muted">Registered</dt><dd class="col-7"><?= fmt_date($patient['created_at']) ?></dd>
            </dl>
          </div>
        </div>

        <div class="card mb-3">
          <div class="card-header"><i class="bi bi-folder2-open me-1"></i>Documents</div>
          <div class="card-body">
            <?php if (!$docs): ?><p class="text-muted small mb-3">No documents uploaded yet.</p><?php endif; ?>
            <?php foreach ($docs as $d): ?>
              <div class="d-flex align-items-center gap-2 py-2 border-bottom">
                <span class="doc-thumb"><i class="bi bi-file-earmark-text"></i></span>
                <span class="flex-grow-1">
                  <strong class="d-block small"><?= e($d['title']) ?></strong>
                  <small class="text-muted"><?= e($d['file_name']) ?> · <?= number_format(((int)$d['file_size']) / 1024, 1) ?> KB · <?= fmt_date($d['created_at']) ?> · <?= $d['visibility'] === 'patient' ? 'Patient can see' : 'Staff only' ?></small>
                </span>
                <a class="btn btn-sm btn-outline-primary" href="patients.php?doc=<?= (int)$d['id'] ?>" title="Download"><i class="bi bi-download"></i></a>
                <?php if ($canManage): ?>
                <form method="post" class="d-inline" data-confirm="Delete this document permanently?">
                  <?= csrf_field() ?><input type="hidden" name="action" value="delete_document"><input type="hidden" name="document_id" value="<?= (int)$d['id'] ?>">
                  <button class="btn btn-sm btn-outline-danger" type="submit" title="Delete"><i class="bi bi-trash"></i></button>
                </form>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
            <?php if ($canManage): ?>
            <form method="post" enctype="multipart/form-data" class="mt-3">
              <?= csrf_field() ?><input type="hidden" name="action" value="upload_document">
              <div class="mb-2"><label class="form-label small">Document title *</label><input class="form-control form-control-sm" name="title" required maxlength="200" placeholder="e.g. Insurance card, X-ray report"></div>
              <div class="mb-2"><label class="form-label small">File * (PDF/image/text, max 10 MB)</label><input class="form-control form-control-sm" type="file" name="document" required></div>
              <div class="mb-2"><label class="form-label small">Visibility</label>
                <select class="form-select form-select-sm" name="visibility"><option value="staff">Staff only</option><option value="patient">Patient can see it</option></select></div>
              <button class="btn btn-primary btn-sm w-100" type="submit"><i class="bi bi-upload"></i> Upload document</button>
            </form>
            <?php endif; ?>
          </div>
        </div>

        <div class="card">
          <div class="card-header"><i class="bi bi-receipt me-1"></i>Invoices</div>
          <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
              <thead><tr><th>Invoice</th><th>Total</th><th>Status</th></tr></thead>
              <tbody>
              <?php if (!$invs): ?><tr><td colspan="3" class="text-muted text-center py-3">No invoices yet.</td></tr><?php endif; ?>
              <?php foreach ($invs as $inv): ?>
                <tr><td><a href="billing.php?print=<?= (int)$inv['id'] ?>" target="_blank" rel="noopener"><?= e($inv['invoice_no']) ?></a></td><td><?= money((float)$inv['total']) ?></td><td><?= status_badge((string)$inv['status']) ?></td></tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <div class="col-lg-8">
        <?php if ($canManage): ?>
        <div class="card mb-3">
          <div class="card-header"><i class="bi bi-file-medical me-1"></i>Add medical record</div>
          <div class="card-body">
            <form method="post" class="row g-2">
              <?= csrf_field() ?><input type="hidden" name="action" value="add_record">
              <div class="col-md-3">
                <label class="form-label small">Type</label>
                <select class="form-select" name="record_type"><option value="note">Note</option><option value="diagnosis">Diagnosis</option><option value="observation">Observation</option><option value="document">Document</option></select>
              </div>
              <div class="col-md-5"><label class="form-label small">Title *</label><input class="form-control" name="record_title" required maxlength="200" placeholder="e.g. Type 2 Diabetes — review"></div>
              <div class="col-md-4">
                <label class="form-label small">Visibility</label>
                <select class="form-select" name="visibility"><option value="staff">Staff only</option><option value="patient">Share with patient</option></select>
              </div>
              <div class="col-12"><label class="form-label small">Details</label><textarea class="form-control" name="record_details" rows="2" placeholder="Clinical notes, findings, advice given…"></textarea></div>
              <div class="col-12"><button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-plus-lg"></i> Add to timeline</button></div>
            </form>
          </div>
        </div>
        <?php endif; ?>
        <div class="card">
          <div class="card-header"><i class="bi bi-clock-history me-1"></i>Medical timeline</div>
          <div class="card-body">
            <?php if (!$timeline): ?><div class="empty-state py-4"><i class="bi bi-clock-history"></i><p class="mb-0">No history yet — records, prescriptions, labs and visits will appear here.</p></div><?php endif; ?>
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

/* ============================ LIST VIEW ============================ */
$pageTitle = 'Patients';
include __DIR__ . '/../includes/header.php';
?>
<div class="card">
  <div class="card-header">
    <div class="row g-2 align-items-center">
      <div class="col-md-4"><div class="search-wrap w-100"><i class="bi bi-search"></i><input class="form-control" id="patSearch" type="search" placeholder="Search name, ID, phone, email…" aria-label="Search patients"></div></div>
      <div class="col-md-2">
        <select class="form-select" id="patGender" aria-label="Filter by gender">
          <option value="">All genders</option><option value="male">Male</option><option value="female">Female</option><option value="other">Other</option>
        </select>
      </div>
      <div class="col-md-3"><span class="text-muted small" id="patCount"></span></div>
      <div class="col-md-3 text-md-end">
        <?php if ($canManage): ?><button class="btn btn-primary" type="button" id="addPatientBtn"><i class="bi bi-person-plus"></i> Register Patient</button><?php endif; ?>
      </div>
    </div>
  </div>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead><tr><th>Patient</th><th>Age / Gender</th><th>Blood</th><th>Contact</th><th>Visits</th><th>Status</th><th>Registered</th><th class="text-end">Actions</th></tr></thead>
      <tbody id="patTbody"></tbody>
    </table>
  </div>
  <div class="card-body d-flex justify-content-end" id="patPager"></div>
</div>

<?php if ($canManage): ?>
<div class="modal fade" id="patientModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <form class="modal-content" id="patientForm" action="../api/patients.php" method="post" novalidate>
      <div class="modal-header"><h5 class="modal-title" id="patientModalTitle">Register Patient</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body">
        <input type="hidden" name="action" id="patientAction" value="create">
        <input type="hidden" name="id" id="patientId" value="">
        <div class="row g-3">
          <div class="col-md-6"><label class="form-label">Full name *</label><input class="form-control" name="name" id="pf-name" required maxlength="120"></div>
          <div class="col-md-3"><label class="form-label">Gender</label><select class="form-select" name="gender" id="pf-gender"><option value="">—</option><option value="male">Male</option><option value="female">Female</option><option value="other">Other</option></select></div>
          <div class="col-md-3"><label class="form-label">Date of birth</label><input class="form-control" type="date" name="dob" id="pf-dob"></div>
          <div class="col-md-3"><label class="form-label">Blood group</label><input class="form-control" name="blood_group" id="pf-blood" maxlength="5" placeholder="O+"></div>
          <div class="col-md-4"><label class="form-label">Phone</label><input class="form-control" name="phone" id="pf-phone" maxlength="40"></div>
          <div class="col-md-5"><label class="form-label">Email</label><input class="form-control" type="email" name="email" id="pf-email" maxlength="190"></div>
          <div class="col-md-6"><label class="form-label">Emergency contact</label><input class="form-control" name="emergency_contact" id="pf-emergency" maxlength="120"></div>
          <div class="col-md-6"><label class="form-label">Allergies</label><input class="form-control" name="allergies" id="pf-allergies" maxlength="255" placeholder="e.g. Penicillin"></div>
          <div class="col-12"><label class="form-label">Address</label><textarea class="form-control" name="address" id="pf-address" rows="2" maxlength="255"></textarea></div>
        </div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Save patient</button></div>
    </form>
  </div>
</div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
  var canManage = <?= $canManage ? 'true' : 'false' ?>;
  function esc(s) { return String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  var table = MediCare.liveTable({
    url: 'patients.php',
    tbody: '#patTbody',
    pager: '#patPager',
    count: '#patCount',
    search: '#patSearch',
    filters: { gender: '#patGender' },
    columns: 8,
    emptyText: 'No patients match your search.',
    renderRow: function (p) {
      var status = p.admitted_now ? '<span class="badge text-bg-danger">Admitted</span>' : '<span class="badge text-bg-success">Outpatient</span>';
      var actions = '<a class="btn btn-sm btn-outline-primary" href="patients.php?id=' + p.id + '" title="Open profile"><i class="bi bi-eye"></i></a>';
      if (canManage) {
        actions += ' <button type="button" class="btn btn-sm btn-outline-secondary" data-edit="' + p.id + '" title="Edit"><i class="bi bi-pencil"></i></button>' +
          ' <button type="button" class="btn btn-sm btn-outline-danger" data-del="' + p.id + '" data-name="' + esc(p.name) + '" title="Delete"><i class="bi bi-trash"></i></button>';
      }
      return '<tr data-row="' + p.id + '">' +
        '<td><div class="d-flex align-items-center gap-2"><span class="avatar-initials">' + esc(p.name.split(' ').map(function (w) { return w.charAt(0); }).join('').slice(0, 2).toUpperCase()) + '</span><span><strong class="d-block">' + esc(p.name) + '</strong><small class="text-muted">' + esc(p.patient_code) + '</small></span></div></td>' +
        '<td>' + esc(p.age) + ' · ' + esc(p.gender ? p.gender.charAt(0).toUpperCase() + p.gender.slice(1) : '—') + '</td>' +
        '<td>' + esc(p.blood_group || '—') + '</td>' +
        '<td><small class="d-block">' + esc(p.phone || '—') + '</small><small class="text-muted">' + esc(p.email || '') + '</small></td>' +
        '<td>' + p.visits + '</td><td>' + status + '</td>' +
        '<td><small>' + esc((p.created_at || '').slice(0, 10)) + '</small></td>' +
        '<td class="text-end">' + actions + '</td></tr>';
    }
  });

  var rowsCache = {};
  var origReload = table.reload;
  table.reload = function () { origReload(); };
  document.querySelector('#patTbody').addEventListener('click', function (ev) {
    var editBtn = ev.target.closest('[data-edit]');
    var delBtn = ev.target.closest('[data-del]');
    if (editBtn) {
      var tr = editBtn.closest('tr');
      openEdit(parseInt(editBtn.getAttribute('data-edit'), 10), tr);
    }
    if (delBtn) {
      var id = delBtn.getAttribute('data-del');
      MediCare.confirm('Delete patient', 'Delete ' + delBtn.getAttribute('data-name') + ' and all of their linked records? This cannot be undone.').then(function (ok) {
        if (!ok) return;
        var fd = new FormData();
        fd.append('action', 'delete'); fd.append('id', id);
        MediCare.api('patients.php', { method: 'POST', body: fd }).then(function (d) { MediCare.toast(d.message); table.reload(); }).catch(function (e) { MediCare.toast(e.message, 'error'); });
      });
    }
  });

  var modal = document.getElementById('patientModal');
  var form = document.getElementById('patientForm');
  function fill(field, val) { var el = document.getElementById('pf-' + field); if (el) el.value = val || ''; }
  function openEdit(id, tr) {
    // Re-fetch the row from the API to get every field (address, allergies…).
    MediCare.api('patients.php?q=' + encodeURIComponent(tr.querySelector('small').textContent) + '&per_page=100').then(function (data) {
      var p = (data.rows || []).find(function (r) { return r.id === id; });
      if (!p) { MediCare.toast('Could not load patient.', 'error'); return; }
      document.getElementById('patientModalTitle').textContent = 'Edit Patient — ' + p.patient_code;
      document.getElementById('patientAction').value = 'update';
      document.getElementById('patientId').value = p.id;
      fill('name', p.name); fill('gender', p.gender); fill('dob', p.dob); fill('blood', p.blood_group);
      fill('phone', p.phone); fill('email', p.email); fill('emergency', p.emergency_contact);
      fill('allergies', p.allergies); fill('address', p.address);
      bootstrap.Modal.getOrCreateInstance(modal).show();
    }).catch(function (e) { MediCare.toast(e.message, 'error'); });
  }
  var addBtn = document.getElementById('addPatientBtn');
  if (addBtn && form) {
    addBtn.addEventListener('click', function () {
      form.reset();
      document.getElementById('patientModalTitle').textContent = 'Register Patient';
      document.getElementById('patientAction').value = 'create';
      document.getElementById('patientId').value = '';
      bootstrap.Modal.getOrCreateInstance(modal).show();
    });
    MediCare.ajaxForm(form, { onSuccess: function () { table.reload(); } });
  }
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
