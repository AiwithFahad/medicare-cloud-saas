<?php
declare(strict_types=1);
/* Hospital Admin — Appointments + Emergency board (Upgrade v2 page tree).
 * The appointment list runs on api/appointments.php (live search, filters,
 * pagination); booking and rescheduling use the real slot picker
 * (api/doctor-availability.php via doctor_schedules). Booking creates a
 * front-desk reminder automatically. The emergency board below manages
 * emergency_cases: arrivals, triage, status flow and admission hand-off. */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/panel.php';
$pdo = db();
$user = require_role(['hospital_admin']);
$tid = require_tenant();
check_tenant_active($pdo);
if (!has_permission('appointments.view')) {
    flash('error', 'You do not have permission to view appointments.');
    redirect('dashboard.php');
}
$canManage = has_permission('appointments.manage');
$base = '../';
$panelSidebar = 'admin';

/* ---------------- Emergency board POST ---------------- */
if (is_post()) {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'emergency_add' || $action === 'emergency_status') {
        if (!$canManage) {
            flash('error', 'You do not have permission to manage emergency cases.');
            redirect('appointments.php#emergency');
        }
        if ($action === 'emergency_add') {
            $patientId = (int)($_POST['patient_id'] ?? 0) ?: null;
            $name = trim((string)($_POST['patient_name'] ?? ''));
            if ($patientId) {
                $ps = $pdo->prepare('SELECT name FROM patients WHERE id = ? AND tenant_id = ?');
                $ps->execute([$patientId, $tid]);
                $name = (string)($ps->fetchColumn() ?: $name);
            }
            if ($name === '') {
                flash('error', 'Enter the patient name or pick a registered patient.');
            } else {
                $triage = in_array(($_POST['triage_level'] ?? ''), ['critical', 'urgent', 'non_urgent'], true) ? (string)$_POST['triage_level'] : 'urgent';
                $doctorId = (int)($_POST['doctor_id'] ?? 0) ?: null;
                $deptId = null;
                if ($doctorId) {
                    $ds = $pdo->prepare('SELECT department_id FROM doctors WHERE id = ? AND tenant_id = ?');
                    $ds->execute([$doctorId, $tid]);
                    $deptId = $ds->fetchColumn() ?: null;
                }
                $pdo->prepare('INSERT INTO emergency_cases (tenant_id, patient_id, patient_name, doctor_id, department_id, triage_level, complaint, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, "waiting", ?)')
                    ->execute([$tid, $patientId, $name, $doctorId, $deptId, $triage, trim((string)($_POST['complaint'] ?? '')) ?: null, (int)$user['id']]);
                audit_log($pdo, 'emergency_case_created', 'emergency_cases', (int)$pdo->lastInsertId(), 'Emergency arrival: ' . $name);
                flash('success', 'Emergency case registered on the board.');
            }
        } else {
            $caseId = (int)($_POST['case_id'] ?? 0);
            $status = (string)($_POST['status'] ?? '');
            $disposition = trim((string)($_POST['disposition'] ?? '')) ?: null;
            if (in_array($status, ['waiting', 'in_treatment', 'admitted', 'discharged', 'referred'], true)) {
                $pdo->prepare('UPDATE emergency_cases SET status = ?, disposition = COALESCE(?, disposition) WHERE id = ? AND tenant_id = ?')
                    ->execute([$status, $disposition, $caseId, $tid]);
                flash('success', 'Emergency case updated.');
            }
        }
    }
    redirect('appointments.php#emergency');
}

$patientsList = $pdo->prepare('SELECT id, name, patient_code FROM patients WHERE tenant_id = ? ORDER BY name');
$patientsList->execute([$tid]);
$patientsList = $patientsList->fetchAll();
$doctorsList = $pdo->prepare('SELECT id, name, specialization, consultation_fee FROM doctors WHERE tenant_id = ? AND status = "active" ORDER BY name');
$doctorsList->execute([$tid]);
$doctorsList = $doctorsList->fetchAll();

$emergencies = $pdo->prepare(
    "SELECT ec.*, d.name AS doctor_name, dep.name AS department_name
       FROM emergency_cases ec
       LEFT JOIN doctors d ON d.id = ec.doctor_id
       LEFT JOIN departments dep ON dep.id = ec.department_id
      WHERE ec.tenant_id = ? AND DATE(ec.arrival_at) = CURDATE()
      ORDER BY FIELD(ec.status, 'waiting','in_treatment','admitted','referred','discharged'), FIELD(ec.triage_level, 'critical','urgent','non_urgent'), ec.arrival_at"
);
$emergencies->execute([$tid]);
$emergencies = $emergencies->fetchAll();

$pageTitle = 'Appointments';
include __DIR__ . '/../includes/header.php';
?>
<div class="card mb-3">
  <div class="card-header">
    <div class="row g-2 align-items-center">
      <div class="col-md-3"><div class="search-wrap w-100"><i class="bi bi-search"></i><input class="form-control" id="apptSearch" type="search" placeholder="Search patient or doctor…" aria-label="Search appointments"></div></div>
      <div class="col-md-2"><input class="form-control" type="date" id="apptDate" aria-label="Filter by date"></div>
      <div class="col-md-2">
        <select class="form-select" id="apptStatus" aria-label="Filter by status">
          <option value="">All statuses</option><option value="scheduled">Scheduled</option><option value="confirmed">Confirmed</option><option value="completed">Completed</option><option value="cancelled">Cancelled</option><option value="no_show">No show</option>
        </select>
      </div>
      <div class="col-md-2">
        <select class="form-select" id="apptDoctor" aria-label="Filter by doctor">
          <option value="">All doctors</option>
          <?php foreach ($doctorsList as $d): ?><option value="<?= (int)$d['id'] ?>"><?= e($d['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-1"><span class="text-muted small" id="apptCount"></span></div>
      <div class="col-md-2 text-md-end">
        <?php if ($canManage): ?><button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#bookModal"><i class="bi bi-calendar-plus"></i> Book</button><?php endif; ?>
      </div>
    </div>
  </div>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead><tr><th>When</th><th>Patient</th><th>Doctor</th><th>Type</th><th>Fee</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
      <tbody id="apptTbody"></tbody>
    </table>
  </div>
  <div class="card-body d-flex justify-content-end" id="apptPager"></div>
</div>

<!-- ================= Emergency board ================= -->
<div class="card" id="emergency">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span><i class="bi bi-heart-pulse me-1 text-danger"></i>Emergency board — today</span>
    <?php if ($canManage): ?><button class="btn btn-danger btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#emgModal"><i class="bi bi-plus-lg"></i> New arrival</button><?php endif; ?>
  </div>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead><tr><th>Arrived</th><th>Patient</th><th>Triage</th><th>Complaint</th><th>Doctor</th><th>Status</th><th>Disposition</th><?php if ($canManage): ?><th class="text-end">Update</th><?php endif; ?></tr></thead>
      <tbody>
      <?php if (!$emergencies): ?><tr><td colspan="<?= $canManage ? 8 : 7 ?>" class="text-muted text-center py-4">No emergency arrivals recorded today.</td></tr><?php endif; ?>
      <?php foreach ($emergencies as $ec): ?>
        <tr>
          <td><?= fmt_datetime($ec['arrival_at']) ?></td>
          <td><strong><?= e($ec['patient_name']) ?></strong><?php if ($ec['patient_id']): ?> <a href="patients.php?id=<?= (int)$ec['patient_id'] ?>" class="small">profile</a><?php endif; ?></td>
          <td><?= status_badge((string)$ec['triage_level']) ?></td>
          <td><?= e($ec['complaint'] ?? '—') ?></td>
          <td><?= e($ec['doctor_name'] ?? '—') ?></td>
          <td><?= status_badge((string)$ec['status']) ?></td>
          <td><small><?= e($ec['disposition'] ?? '—') ?></small></td>
          <?php if ($canManage): ?>
          <td class="text-end">
            <form method="post" class="d-inline-flex gap-1">
              <?= csrf_field() ?><input type="hidden" name="action" value="emergency_status"><input type="hidden" name="case_id" value="<?= (int)$ec['id'] ?>">
              <select class="form-select form-select-sm" name="status" style="width:130px" aria-label="Set emergency status">
                <?php foreach (['waiting', 'in_treatment', 'admitted', 'discharged', 'referred'] as $st): ?><option value="<?= $st ?>" <?= $ec['status'] === $st ? 'selected' : '' ?>><?= ucfirst(str_replace('_', ' ', $st)) ?></option><?php endforeach; ?>
              </select>
              <input class="form-control form-control-sm" name="disposition" placeholder="Disposition note" style="width:150px">
              <button class="btn btn-sm btn-outline-primary" type="submit"><i class="bi bi-check-lg"></i></button>
            </form>
          </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card-body small text-muted">To admit an emergency patient into a ward, use <a href="wards.php">Wards, Beds &amp; Admissions</a> — admission also allocates the bed and starts the stay record.</div>
</div>

<?php if ($canManage): ?>
<!-- Book appointment modal -->
<div class="modal fade" id="bookModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <form class="modal-content" id="bookForm" action="../api/appointments.php" method="post">
      <div class="modal-header"><h5 class="modal-title">Book Appointment</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body">
        <input type="hidden" name="action" value="create">
        <div class="row g-3">
          <div class="col-md-6"><label class="form-label">Patient *</label>
            <select class="form-select" name="patient_id" required><option value="">Select patient…</option>
              <?php foreach ($patientsList as $p): ?><option value="<?= (int)$p['id'] ?>"><?= e($p['name']) ?> (<?= e($p['patient_code']) ?>)</option><?php endforeach; ?>
            </select></div>
          <div class="col-md-6"><label class="form-label">Doctor *</label>
            <select class="form-select" name="doctor_id" id="bkDoctor" required><option value="">Select doctor…</option>
              <?php foreach ($doctorsList as $d): ?><option value="<?= (int)$d['id'] ?>" data-fee="<?= e((string)$d['consultation_fee']) ?>"><?= e($d['name']) ?> — <?= e($d['specialization'] ?? '') ?></option><?php endforeach; ?>
            </select></div>
          <div class="col-md-4"><label class="form-label">Date *</label><input class="form-control" type="date" name="date" id="bkDate" required min="<?= date('Y-m-d') ?>"></div>
          <div class="col-md-4"><label class="form-label">Type</label>
            <select class="form-select" name="type"><option>OPD</option><option>Follow-up</option><option>Teleconsult</option><option>Emergency</option></select></div>
          <div class="col-md-4"><label class="form-label">Fee ($)</label><input class="form-control" type="number" name="fee" id="bkFee" min="0" step="0.01" placeholder="Doctor's fee"></div>
          <div class="col-12"><label class="form-label">Free slots *</label><div id="bkSlots" class="border rounded p-2"><span class="text-muted small">Select a doctor and date to see free slots.</span></div>
            <small class="text-muted">Emergency visits skip the slot check. A front-desk reminder is created automatically 1 hour before the visit.</small></div>
          <div class="col-12"><label class="form-label">Reason</label><input class="form-control" name="reason" maxlength="255" placeholder="Reason for visit"></div>
        </div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary"><i class="bi bi-calendar-check"></i> Book appointment</button></div>
    </form>
  </div>
</div>

<!-- Reschedule modal -->
<div class="modal fade" id="reschedModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" id="reschedForm" action="../api/appointments.php" method="post">
      <div class="modal-header"><h5 class="modal-title">Reschedule Appointment</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body">
        <input type="hidden" name="action" value="reschedule">
        <input type="hidden" name="id" id="rsId">
        <input type="hidden" id="rsDoctor">
        <p class="small text-muted" id="rsInfo"></p>
        <div class="mb-3"><label class="form-label">New date *</label><input class="form-control" type="date" name="date" id="rsDate" required min="<?= date('Y-m-d') ?>"></div>
        <div><label class="form-label">Free slots *</label><div id="rsSlots" class="border rounded p-2"></div></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary"><i class="bi bi-calendar-event"></i> Reschedule</button></div>
    </form>
  </div>
</div>

<!-- Emergency arrival modal -->
<div class="modal fade" id="emgModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" method="post">
      <div class="modal-header"><h5 class="modal-title">Register Emergency Arrival</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body">
        <?= csrf_field() ?><input type="hidden" name="action" value="emergency_add">
        <div class="mb-3"><label class="form-label">Registered patient</label>
          <select class="form-select" name="patient_id"><option value="">— Walk-in / not registered —</option>
            <?php foreach ($patientsList as $p): ?><option value="<?= (int)$p['id'] ?>"><?= e($p['name']) ?> (<?= e($p['patient_code']) ?>)</option><?php endforeach; ?>
          </select></div>
        <div class="mb-3"><label class="form-label">Patient name (walk-in)</label><input class="form-control" name="patient_name" maxlength="120" placeholder="Name if known"></div>
        <div class="row g-3">
          <div class="col-md-6"><label class="form-label">Triage level</label>
            <select class="form-select" name="triage_level"><option value="critical">Critical</option><option value="urgent" selected>Urgent</option><option value="non_urgent">Non-urgent</option></select></div>
          <div class="col-md-6"><label class="form-label">Attending doctor</label>
            <select class="form-select" name="doctor_id"><option value="">— Unassigned —</option>
              <?php foreach ($doctorsList as $d): ?><option value="<?= (int)$d['id'] ?>"><?= e($d['name']) ?></option><?php endforeach; ?>
            </select></div>
        </div>
        <div class="mt-3"><label class="form-label">Chief complaint</label><input class="form-control" name="complaint" maxlength="255" placeholder="e.g. Chest pain, breathing difficulty"></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-danger"><i class="bi bi-heart-pulse"></i> Add to board</button></div>
    </form>
  </div>
</div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
  var canManage = <?= $canManage ? 'true' : 'false' ?>;
  function esc(s) { return String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  var badges = { scheduled: 'text-bg-info', confirmed: 'text-bg-success', completed: 'text-bg-primary', cancelled: 'text-bg-danger', no_show: 'text-bg-dark' };
  var table = MediCare.liveTable({
    url: 'appointments.php',
    tbody: '#apptTbody',
    pager: '#apptPager',
    count: '#apptCount',
    search: '#apptSearch',
    filters: { status: '#apptStatus', doctor_id: '#apptDoctor', date: '#apptDate' },
    columns: 7,
    emptyText: 'No appointments match your filters.',
    renderRow: function (a) {
      var actions = '<a class="btn btn-sm btn-outline-primary" href="patients.php?id=' + a.patient_id + '" title="Patient profile"><i class="bi bi-person"></i></a>';
      if (canManage) {
        if (a.status === 'scheduled') actions += ' <button type="button" class="btn btn-sm btn-outline-success" data-status="confirmed" data-id="' + a.id + '" title="Confirm"><i class="bi bi-check-lg"></i></button>';
        if (a.status === 'scheduled' || a.status === 'confirmed') actions += ' <button type="button" class="btn btn-sm btn-outline-primary" data-status="completed" data-id="' + a.id + '" title="Mark completed"><i class="bi bi-check2-all"></i></button>';
        if (['scheduled', 'confirmed', 'cancelled'].indexOf(a.status) > -1) actions += ' <button type="button" class="btn btn-sm btn-outline-secondary" data-resched="' + a.id + '" data-doctor="' + a.doctor_id + '" data-info="' + esc(a.patient_name + ' · ' + a.date + ' ' + a.time) + '" title="Reschedule"><i class="bi bi-calendar-event"></i></button>';
        if (a.status === 'scheduled' || a.status === 'confirmed') actions += ' <button type="button" class="btn btn-sm btn-outline-danger" data-status="cancelled" data-id="' + a.id + '" title="Cancel"><i class="bi bi-x-lg"></i></button>';
      }
      return '<tr><td><strong class="d-block">' + esc(a.date) + '</strong><small class="text-muted">' + esc(a.time) + '</small></td>' +
        '<td><a href="patients.php?id=' + a.patient_id + '">' + esc(a.patient_name) + '</a><br><small class="text-muted">' + esc(a.patient_code) + '</small></td>' +
        '<td>' + esc(a.doctor_name) + '<br><small class="text-muted">' + esc(a.specialization || '') + '</small></td>' +
        '<td>' + esc(a.type) + '</td><td>$' + Number(a.fee).toFixed(2) + '</td>' +
        '<td><span class="badge ' + (badges[a.status] || 'text-bg-secondary') + '">' + esc(a.status.replace('_', ' ')) + '</span></td>' +
        '<td class="text-end">' + actions + '</td></tr>';
    }
  });

  document.querySelector('#apptTbody').addEventListener('click', function (ev) {
    var statusBtn = ev.target.closest('[data-status]');
    var reschedBtn = ev.target.closest('[data-resched]');
    if (statusBtn) {
      var fd = new FormData();
      fd.append('action', 'status'); fd.append('id', statusBtn.getAttribute('data-id')); fd.append('status', statusBtn.getAttribute('data-status'));
      MediCare.api('appointments.php', { method: 'POST', body: fd }).then(function (d) { MediCare.toast(d.message); table.reload(); }).catch(function (e) { MediCare.toast(e.message, 'error'); });
    }
    if (reschedBtn) {
      document.getElementById('rsId').value = reschedBtn.getAttribute('data-resched');
      document.getElementById('rsDoctor').value = reschedBtn.getAttribute('data-doctor');
      document.getElementById('rsInfo').textContent = reschedBtn.getAttribute('data-info');
      document.getElementById('rsDate').value = '';
      document.getElementById('rsSlots').innerHTML = '<span class="text-muted small">Pick a new date to see free slots.</span>';
      bootstrap.Modal.getOrCreateInstance(document.getElementById('reschedModal')).show();
    }
  });

  if (canManage) {
    MediCare.bindSlots({ doctor: '#bkDoctor', date: '#bkDate', wrap: '#bkSlots', name: 'time', fee: '#bkFee' });
    MediCare.bindSlots({ doctor: '#rsDoctor', date: '#rsDate', wrap: '#rsSlots', name: 'time' });
    MediCare.ajaxForm(document.getElementById('bookForm'), { onSuccess: function () { table.reload(); } });
    MediCare.ajaxForm(document.getElementById('reschedForm'), { onSuccess: function () { table.reload(); } });
  }
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
