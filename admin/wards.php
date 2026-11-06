<?php
declare(strict_types=1);
/* Hospital Admin — Wards, Beds & Admissions (Upgrade v2 page tree).
 * Ward + bed management, a live bed board (api/wards.php auto-refresh),
 * and the admission workflow: admitting allocates a free bed and creates
 * the stay; discharging frees the bed and closes the record. Bed status
 * can also be adjusted directly (reserved / maintenance) via the API. */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/panel.php';
$pdo = db();
$user = require_role(['hospital_admin']);
$tid = require_tenant();
check_tenant_active($pdo);
if (!has_permission('wards.view')) {
    flash('error', 'You do not have permission to view wards.');
    redirect('dashboard.php');
}
$canManage = has_permission('wards.manage');
$canAdmit = has_permission('admissions.manage');
$base = '../';
$panelSidebar = 'admin';

if (is_post()) {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'save_ward') {
        if (!$canManage) {
            flash('error', 'You do not have permission to modify wards.');
            redirect('wards.php');
        }
        $id = (int)($_POST['id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $type = in_array(($_POST['type'] ?? ''), ['General', 'ICU', 'CCU', 'Pediatric', 'Maternity', 'Private'], true) ? (string)$_POST['type'] : 'General';
        $floor = trim((string)($_POST['floor'] ?? '')) ?: null;
        if ($name === '') {
            flash('error', 'Ward name is required.');
        } elseif ($id > 0) {
            $pdo->prepare('UPDATE wards SET name = ?, type = ?, floor = ? WHERE id = ? AND tenant_id = ?')->execute([$name, $type, $floor, $id, $tid]);
            flash('success', 'Ward updated.');
        } else {
            $pdo->prepare('INSERT INTO wards (tenant_id, name, type, floor) VALUES (?, ?, ?, ?)')->execute([$tid, $name, $type, $floor]);
            $wardId = (int)$pdo->lastInsertId();
            $bedCount = max(0, min(60, (int)($_POST['bed_count'] ?? 0)));
            $prefix = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $name) ?: 'W', 0, 3));
            $ins = $pdo->prepare('INSERT INTO beds (tenant_id, ward_id, bed_number) VALUES (?, ?, ?)');
            for ($i = 1; $i <= $bedCount; $i++) {
                $ins->execute([$tid, $wardId, $prefix . '-' . str_pad((string)$i, 2, '0', STR_PAD_LEFT)]);
            }
            audit_log($pdo, 'ward_created', 'wards', $wardId, 'Ward created: ' . $name . ' with ' . $bedCount . ' beds');
            flash('success', 'Ward created' . ($bedCount ? ' with ' . $bedCount . ' beds.' : '.'));
        }
    } elseif ($action === 'add_bed') {
        if (!$canManage) {
            flash('error', 'You do not have permission to modify wards.');
            redirect('wards.php');
        }
        $wardId = (int)($_POST['ward_id'] ?? 0);
        $bedNo = trim((string)($_POST['bed_number'] ?? ''));
        if ($wardId > 0 && $bedNo !== '') {
            $pdo->prepare('INSERT INTO beds (tenant_id, ward_id, bed_number) VALUES (?, ?, ?)')->execute([$tid, $wardId, $bedNo]);
            flash('success', 'Bed added.');
        } else {
            flash('error', 'Ward and bed number are required.');
        }
    } elseif ($action === 'admit') {
        if (!$canAdmit) {
            flash('error', 'You do not have permission to admit patients.');
            redirect('wards.php');
        }
        $patientId = (int)($_POST['patient_id'] ?? 0);
        $bedId = (int)($_POST['bed_id'] ?? 0);
        $doctorId = (int)($_POST['doctor_id'] ?? 0) ?: null;
        $reason = trim((string)($_POST['reason'] ?? '')) ?: null;
        $bs = $pdo->prepare('SELECT * FROM beds WHERE id = ? AND tenant_id = ? AND status = "available"');
        $bs->execute([$bedId, $tid]);
        $bed = $bs->fetch();
        $already = $pdo->prepare("SELECT COUNT(*) FROM admissions WHERE tenant_id = ? AND patient_id = ? AND status = 'admitted'");
        $already->execute([$tid, $patientId]);
        if (!$bed) {
            flash('error', 'That bed is not available. Pick a free bed.');
        } elseif ((int)$already->fetchColumn() > 0) {
            flash('error', 'This patient already has an active admission.');
        } else {
            $pdo->prepare('INSERT INTO admissions (tenant_id, patient_id, doctor_id, ward_id, bed_id, reason, status) VALUES (?, ?, ?, ?, ?, ?, "admitted")')
                ->execute([$tid, $patientId, $doctorId, (int)$bed['ward_id'], $bedId, $reason]);
            $admId = (int)$pdo->lastInsertId();
            $pdo->prepare('UPDATE beds SET status = "occupied", current_patient_id = ? WHERE id = ?')->execute([$patientId, $bedId]);
            audit_log($pdo, 'patient_admitted', 'admissions', $admId, 'Patient #' . $patientId . ' admitted to bed ' . $bed['bed_number']);
            flash('success', 'Patient admitted to bed ' . $bed['bed_number'] . '.');
        }
    } elseif ($action === 'discharge') {
        if (!$canAdmit) {
            flash('error', 'You do not have permission to discharge patients.');
            redirect('wards.php');
        }
        $admId = (int)($_POST['admission_id'] ?? 0);
        $as = $pdo->prepare('SELECT * FROM admissions WHERE id = ? AND tenant_id = ? AND status = "admitted"');
        $as->execute([$admId, $tid]);
        $adm = $as->fetch();
        if ($adm) {
            $status = ($_POST['discharge_type'] ?? '') === 'transferred' ? 'transferred' : 'discharged';
            $pdo->prepare('UPDATE admissions SET status = ?, discharge_date = NOW(), notes = ? WHERE id = ?')
                ->execute([$status, trim((string)($_POST['notes'] ?? '')) ?: null, $admId]);
            $pdo->prepare('UPDATE beds SET status = "available", current_patient_id = NULL WHERE id = ? AND tenant_id = ?')->execute([(int)$adm['bed_id'], $tid]);
            audit_log($pdo, 'patient_' . $status, 'admissions', $admId, 'Patient #' . $adm['patient_id'] . ' ' . $status);
            flash('success', 'Patient ' . $status . ' and the bed is now free.');
        } else {
            flash('error', 'Active admission not found.');
        }
    }
    redirect('wards.php');
}

$wards = $pdo->prepare('SELECT * FROM wards WHERE tenant_id = ? ORDER BY name');
$wards->execute([$tid]);
$wards = $wards->fetchAll();

$admissions = $pdo->prepare(
    "SELECT ad.*, p.name AS patient_name, p.patient_code, d.name AS doctor_name, w.name AS ward_name, b.bed_number
       FROM admissions ad
       JOIN patients p ON p.id = ad.patient_id
       LEFT JOIN doctors d ON d.id = ad.doctor_id
       JOIN wards w ON w.id = ad.ward_id
       JOIN beds b ON b.id = ad.bed_id
      WHERE ad.tenant_id = ? AND ad.status = 'admitted'
      ORDER BY ad.admission_date"
);
$admissions->execute([$tid]);
$admissions = $admissions->fetchAll();

$patientsList = $pdo->prepare("SELECT p.id, p.name, p.patient_code FROM patients p WHERE p.tenant_id = ? AND NOT EXISTS (SELECT 1 FROM admissions a WHERE a.tenant_id = p.tenant_id AND a.patient_id = p.id AND a.status = 'admitted') ORDER BY p.name");
$patientsList->execute([$tid]);
$patientsList = $patientsList->fetchAll();
$doctorsList = $pdo->prepare('SELECT id, name FROM doctors WHERE tenant_id = ? ORDER BY name');
$doctorsList->execute([$tid]);
$doctorsList = $doctorsList->fetchAll();
$freeBeds = $pdo->prepare("SELECT b.id, b.bed_number, w.name AS ward_name FROM beds b JOIN wards w ON w.id = b.ward_id WHERE b.tenant_id = ? AND b.status = 'available' ORDER BY w.name, b.bed_number");
$freeBeds->execute([$tid]);
$freeBeds = $freeBeds->fetchAll();

$editWardId = (int)($_GET['edit_ward'] ?? 0);
$editWard = null;
foreach ($wards as $w) {
    if ((int)$w['id'] === $editWardId) {
        $editWard = $w;
    }
}

$pageTitle = 'Wards, Beds & Admissions';
include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex flex-wrap gap-2 mb-3">
  <?php if ($canAdmit): ?><button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#admitModal"><i class="bi bi-door-open"></i> Admit Patient</button><?php endif; ?>
  <?php if ($canManage): ?><button class="btn btn-outline-primary" type="button" data-bs-toggle="modal" data-bs-target="#wardModal"><i class="bi bi-plus-lg"></i> New Ward</button><?php endif; ?>
  <span class="ms-auto align-self-center text-muted small"><span id="bedSummary">Loading bed board…</span> · auto-refreshes every 30s</span>
</div>

<div id="bedBoard">
  <div class="empty-state py-5"><i class="bi bi-hospital"></i><p class="mb-0">Loading the live bed board…</p></div>
</div>

<div class="card mt-3">
  <div class="card-header"><i class="bi bi-door-open me-1"></i>Current admissions (<?= count($admissions) ?>)</div>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead><tr><th>Patient</th><th>Ward / Bed</th><th>Doctor</th><th>Admitted</th><th>Reason</th><?php if ($canAdmit): ?><th class="text-end">Discharge</th><?php endif; ?></tr></thead>
      <tbody>
      <?php if (!$admissions): ?><tr><td colspan="6" class="text-muted text-center py-4">No patients are currently admitted.</td></tr><?php endif; ?>
      <?php foreach ($admissions as $ad): ?>
        <tr>
          <td><a href="patients.php?id=<?= (int)$ad['patient_id'] ?>"><?= e($ad['patient_name']) ?></a> <small class="text-muted"><?= e($ad['patient_code']) ?></small></td>
          <td><?= e($ad['ward_name']) ?> / <strong><?= e($ad['bed_number']) ?></strong></td>
          <td><?= e($ad['doctor_name'] ?? '—') ?></td>
          <td><?= fmt_datetime($ad['admission_date']) ?></td>
          <td><?= e($ad['reason'] ?? '—') ?></td>
          <?php if ($canAdmit): ?>
          <td class="text-end">
            <form method="post" class="d-inline-flex gap-1" data-confirm="Discharge <?= e($ad['patient_name']) ?>? The bed will be freed immediately.">
              <?= csrf_field() ?><input type="hidden" name="action" value="discharge"><input type="hidden" name="admission_id" value="<?= (int)$ad['id'] ?>">
              <select class="form-select form-select-sm" name="discharge_type" style="width:120px" aria-label="Discharge type"><option value="discharged">Discharged</option><option value="transferred">Transferred</option></select>
              <input class="form-control form-control-sm" name="notes" placeholder="Discharge note" style="width:150px">
              <button class="btn btn-sm btn-outline-success" type="submit"><i class="bi bi-box-arrow-right"></i> End stay</button>
            </form>
          </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($canManage): ?>
<div class="card mt-3">
  <div class="card-header">Add a single bed</div>
  <div class="card-body">
    <form method="post" class="row g-2 align-items-end">
      <?= csrf_field() ?><input type="hidden" name="action" value="add_bed">
      <div class="col-md-4"><label class="form-label small">Ward</label>
        <select class="form-select" name="ward_id" required><option value="">Select ward…</option>
          <?php foreach ($wards as $w): ?><option value="<?= (int)$w['id'] ?>"><?= e($w['name']) ?></option><?php endforeach; ?>
        </select></div>
      <div class="col-md-4"><label class="form-label small">Bed number</label><input class="form-control" name="bed_number" required maxlength="20" placeholder="e.g. G-13"></div>
      <div class="col-md-4"><button class="btn btn-primary" type="submit"><i class="bi bi-plus-lg"></i> Add bed</button></div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php if ($canAdmit): ?>
<div class="modal fade" id="admitModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" method="post">
      <div class="modal-header"><h5 class="modal-title">Admit Patient</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body">
        <?= csrf_field() ?><input type="hidden" name="action" value="admit">
        <div class="mb-3"><label class="form-label">Patient *</label>
          <select class="form-select" name="patient_id" required><option value="">Select patient…</option>
            <?php foreach ($patientsList as $p): ?><option value="<?= (int)$p['id'] ?>"><?= e($p['name']) ?> (<?= e($p['patient_code']) ?>)</option><?php endforeach; ?>
          </select><small class="text-muted">Patients with an active admission are not listed.</small></div>
        <div class="mb-3"><label class="form-label">Free bed *</label>
          <select class="form-select" name="bed_id" required><option value="">Select a free bed…</option>
            <?php foreach ($freeBeds as $fb): ?><option value="<?= (int)$fb['id'] ?>"><?= e($fb['ward_name']) ?> — Bed <?= e($fb['bed_number']) ?></option><?php endforeach; ?>
          </select></div>
        <div class="mb-3"><label class="form-label">Attending doctor</label>
          <select class="form-select" name="doctor_id"><option value="">— None —</option>
            <?php foreach ($doctorsList as $d): ?><option value="<?= (int)$d['id'] ?>"><?= e($d['name']) ?></option><?php endforeach; ?>
          </select></div>
        <div><label class="form-label">Admission reason</label><input class="form-control" name="reason" maxlength="255" placeholder="e.g. Post-operative monitoring"></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary"><i class="bi bi-door-open"></i> Admit patient</button></div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php if ($canManage): ?>
<div class="modal fade" id="wardModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" method="post">
      <div class="modal-header"><h5 class="modal-title"><?= $editWard ? 'Edit Ward' : 'New Ward' ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body">
        <?= csrf_field() ?><input type="hidden" name="action" value="save_ward"><input type="hidden" name="id" value="<?= (int)($editWard['id'] ?? 0) ?>">
        <div class="row g-3">
          <div class="col-md-6"><label class="form-label">Name *</label><input class="form-control" name="name" required maxlength="80" value="<?= e($editWard['name'] ?? '') ?>"></div>
          <div class="col-md-6"><label class="form-label">Type</label>
            <select class="form-select" name="type"><?php foreach (['General', 'ICU', 'CCU', 'Pediatric', 'Maternity', 'Private'] as $t): ?><option <?= ($editWard['type'] ?? 'General') === $t ? 'selected' : '' ?>><?= $t ?></option><?php endforeach; ?></select></div>
          <div class="col-md-6"><label class="form-label">Floor</label><input class="form-control" name="floor" maxlength="30" value="<?= e($editWard['floor'] ?? '') ?>"></div>
          <?php if (!$editWard): ?><div class="col-md-6"><label class="form-label">Create beds</label><input class="form-control" type="number" name="bed_count" min="0" max="60" value="0"><small class="text-muted">Beds are numbered automatically (e.g. GEN-01).</small></div><?php endif; ?>
        </div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Save ward</button></div>
    </form>
  </div>
</div>
<?php if ($editWard): ?>
<script>document.addEventListener('DOMContentLoaded', function () { bootstrap.Modal.getOrCreateInstance(document.getElementById('wardModal')).show(); });</script>
<?php endif; ?>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
  var canManage = <?= $canManage ? 'true' : 'false' ?>;
  function esc(s) { return String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  var board = document.getElementById('bedBoard');
  var summary = document.getElementById('bedSummary');
  var statusCls = { available: 'bed-available', occupied: 'occupied', reserved: 'bed-reserved', maintenance: 'bed-maintenance' };
  function loadBoard() {
    MediCare.api('wards.php').then(function (data) {
      var total = 0, occ = 0;
      var html = '<div class="row g-3">';
      (data.wards || []).forEach(function (w) {
        total += w.total_beds; occ += w.occupied;
        html += '<div class="col-lg-6"><div class="card h-100"><div class="card-header d-flex justify-content-between"><span>' + esc(w.name) + ' <small class="text-muted">' + esc(w.type) + '</small></span><span class="badge text-bg-primary">' + w.occupied + ' / ' + w.total_beds + ' occupied</span></div><div class="card-body"><div class="bed-grid">';
        w.beds.forEach(function (b) {
          html += '<div class="bed-tile ' + (statusCls[b.status] || '') + '"><strong class="d-block">' + esc(b.bed_number) + '</strong><small>' + esc(b.status) + (b.patient_name ? '<br>' + esc(b.patient_name) : '') + '</small>' +
            (canManage ? '<div class="mt-1"><select class="form-select form-select-sm bed-status" data-bed="' + b.id + '" aria-label="Set bed status"><option value="">Set status…</option><option value="available">Available</option><option value="reserved">Reserved</option><option value="maintenance">Maintenance</option></select></div>' : '') + '</div>';
        });
        html += '</div></div></div></div>';
      });
      html += '</div>';
      board.innerHTML = html || '<div class="card"><div class="empty-state py-5"><i class="bi bi-hospital"></i><p class="mb-0">No wards yet — create your first ward.</p></div></div>';
      summary.textContent = occ + ' of ' + total + ' beds occupied';
      board.querySelectorAll('.bed-status').forEach(function (sel) {
        sel.addEventListener('change', function () {
          if (!sel.value) return;
          var fd = new FormData();
          fd.append('action', 'bed_status'); fd.append('bed_id', sel.getAttribute('data-bed')); fd.append('status', sel.value);
          MediCare.api('wards.php', { method: 'POST', body: fd }).then(function (d) { MediCare.toast(d.message); loadBoard(); }).catch(function (e) { MediCare.toast(e.message, 'error'); });
        });
      });
    }).catch(function (err) { summary.textContent = 'Bed board failed to load'; MediCare.toast(err.message, 'error'); });
  }
  loadBoard();
  setInterval(loadBoard, 30000);
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
