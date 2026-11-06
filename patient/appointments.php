<?php
declare(strict_types=1);
/* Patient portal — My Appointments (Upgrade v2 page tree).
 * The list comes from api/appointments.php, which restricts rows to this
 * patient server-side. Booking picks a real free slot from the doctor's
 * schedule; patients may cancel or reschedule only their own visits
 * (enforced in the API). */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/panel.php';
$pdo = db();
$user = require_role(['patient']);
$tid = require_tenant();
check_tenant_active($pdo);
$base = '../';
$panelSidebar = 'patient';
$patient = panel_patient($pdo, $tid, (int)$user['id']);

$doctorsList = $pdo->prepare('SELECT id, name, specialization, consultation_fee FROM doctors WHERE tenant_id = ? AND status = "active" ORDER BY name');
$doctorsList->execute([$tid]);
$doctorsList = $doctorsList->fetchAll();

$pageTitle = 'My Appointments';
include __DIR__ . '/../includes/header.php';
if (!$patient) {
    ?>
    <div class="card"><div class="empty-state py-5"><i class="bi bi-person-heart"></i>
      <p class="mb-0"><strong>Your login is not linked to a patient profile yet.</strong><br><span class="text-muted">Please ask the hospital front desk to link your account — then you can book and manage visits here.</span></p>
    </div></div>
    <?php include __DIR__ . '/../includes/footer.php';
    return;
}
?>
<div class="card">
  <div class="card-header">
    <div class="row g-2 align-items-center">
      <div class="col-md-4"><div class="search-wrap w-100"><i class="bi bi-search"></i><input class="form-control" id="apptSearch" type="search" placeholder="Search doctor…" aria-label="Search appointments"></div></div>
      <div class="col-md-3"><input class="form-control" type="date" id="apptDate" aria-label="Filter by date"></div>
      <div class="col-md-2">
        <select class="form-select" id="apptStatus" aria-label="Filter by status">
          <option value="">All statuses</option><option value="scheduled">Scheduled</option><option value="confirmed">Confirmed</option><option value="completed">Completed</option><option value="cancelled">Cancelled</option>
        </select>
      </div>
      <div class="col-md-3 text-md-end"><button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#bookModal"><i class="bi bi-calendar-plus"></i> Book a visit</button></div>
    </div>
  </div>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead><tr><th>When</th><th>Doctor</th><th>Type</th><th>Reason</th><th>Fee</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
      <tbody id="apptTbody"></tbody>
    </table>
  </div>
  <div class="card-body d-flex justify-content-end" id="apptPager"></div>
</div>

<div class="modal fade" id="bookModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <form class="modal-content" id="bookForm" action="../api/appointments.php" method="post">
      <div class="modal-header"><h5 class="modal-title">Book a Visit</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body">
        <input type="hidden" name="action" value="create">
        <div class="row g-3">
          <div class="col-md-6"><label class="form-label">Doctor *</label>
            <select class="form-select" name="doctor_id" id="bkDoctor" required><option value="">Select doctor…</option>
              <?php foreach ($doctorsList as $d): ?><option value="<?= (int)$d['id'] ?>" data-fee="<?= e((string)$d['consultation_fee']) ?>"><?= e($d['name']) ?> — <?= e($d['specialization'] ?? '') ?> (<?= money((float)$d['consultation_fee']) ?>)</option><?php endforeach; ?>
            </select></div>
          <div class="col-md-3"><label class="form-label">Date *</label><input class="form-control" type="date" name="date" id="bkDate" required min="<?= date('Y-m-d') ?>"></div>
          <div class="col-md-3"><label class="form-label">Visit type</label>
            <select class="form-select" name="type"><option>OPD</option><option>Follow-up</option><option>Teleconsult</option></select></div>
          <div class="col-12"><label class="form-label">Free slots *</label><div id="bkSlots" class="border rounded p-2"><span class="text-muted small">Select a doctor and date to see free slots.</span></div>
            <small class="text-muted">Your booking starts as "Scheduled" — the hospital confirms it after review.</small></div>
          <div class="col-12"><label class="form-label">Reason</label><input class="form-control" name="reason" maxlength="255" placeholder="What brings you in?"></div>
        </div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary"><i class="bi bi-calendar-check"></i> Book visit</button></div>
    </form>
  </div>
</div>

<div class="modal fade" id="reschedModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" id="reschedForm" action="../api/appointments.php" method="post">
      <div class="modal-header"><h5 class="modal-title">Reschedule Visit</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
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

<script>
document.addEventListener('DOMContentLoaded', function () {
  function esc(s) { return String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  var badges = { scheduled: 'text-bg-info', confirmed: 'text-bg-success', completed: 'text-bg-primary', cancelled: 'text-bg-danger', no_show: 'text-bg-dark' };
  var table = MediCare.liveTable({
    url: 'appointments.php',
    tbody: '#apptTbody',
    pager: '#apptPager',
    search: '#apptSearch',
    filters: { status: '#apptStatus', date: '#apptDate' },
    columns: 7,
    emptyText: 'You have no appointments matching this filter.',
    renderRow: function (a) {
      var actions = '';
      if (a.status === 'scheduled' || a.status === 'confirmed') {
        actions = '<button type="button" class="btn btn-sm btn-outline-secondary" data-resched="' + a.id + '" data-doctor="' + a.doctor_id + '" data-info="' + esc(a.doctor_name + ' · ' + a.date + ' ' + a.time) + '"><i class="bi bi-calendar-event"></i> Move</button>' +
          ' <button type="button" class="btn btn-sm btn-outline-danger" data-cancel="' + a.id + '"><i class="bi bi-x-lg"></i> Cancel</button>';
      }
      return '<tr><td><strong class="d-block">' + esc(a.date) + '</strong><small class="text-muted">' + esc(a.time) + '</small></td>' +
        '<td>' + esc(a.doctor_name) + '<br><small class="text-muted">' + esc(a.specialization || '') + '</small></td>' +
        '<td>' + esc(a.type) + '</td><td>' + esc(a.reason || '—') + '</td><td>$' + Number(a.fee).toFixed(2) + '</td>' +
        '<td><span class="badge ' + (badges[a.status] || 'text-bg-secondary') + '">' + esc(a.status.replace('_', ' ')) + '</span></td>' +
        '<td class="text-end">' + (actions || '<span class="text-muted small">—</span>') + '</td></tr>';
    }
  });
  document.querySelector('#apptTbody').addEventListener('click', function (ev) {
    var cancelBtn = ev.target.closest('[data-cancel]');
    var reschedBtn = ev.target.closest('[data-resched]');
    if (cancelBtn) {
      MediCare.confirm('Cancel appointment', 'Cancel this appointment? The hospital will be notified through the schedule.').then(function (ok) {
        if (!ok) return;
        var fd = new FormData();
        fd.append('action', 'status'); fd.append('id', cancelBtn.getAttribute('data-cancel')); fd.append('status', 'cancelled');
        MediCare.api('appointments.php', { method: 'POST', body: fd }).then(function (d) { MediCare.toast(d.message); table.reload(); }).catch(function (e) { MediCare.toast(e.message, 'error'); });
      });
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
  MediCare.bindSlots({ doctor: '#bkDoctor', date: '#bkDate', wrap: '#bkSlots', name: 'time' });
  MediCare.bindSlots({ doctor: '#rsDoctor', date: '#rsDate', wrap: '#rsSlots', name: 'time' });
  MediCare.ajaxForm(document.getElementById('bookForm'), { onSuccess: function () { table.reload(); } });
  MediCare.ajaxForm(document.getElementById('reschedForm'), { onSuccess: function () { table.reload(); } });
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
