<?php
declare(strict_types=1);
/* Doctor — My Appointments (Upgrade v2 page tree).
 * The list is powered by api/appointments.php, which forces the doctor
 * filter to the signed-in doctor server-side — another doctor's visits
 * can never appear or be modified here. Status changes and rescheduling
 * (real free-slot picker) go through the same API. */
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

$pageTitle = 'My Appointments';
include __DIR__ . '/../includes/header.php';
?>
<div class="card">
  <div class="card-header">
    <div class="row g-2 align-items-center">
      <div class="col-md-4"><div class="search-wrap w-100"><i class="bi bi-search"></i><input class="form-control" id="apptSearch" type="search" placeholder="Search patient…" aria-label="Search appointments"></div></div>
      <div class="col-md-3"><input class="form-control" type="date" id="apptDate" aria-label="Filter by date"></div>
      <div class="col-md-3">
        <select class="form-select" id="apptStatus" aria-label="Filter by status">
          <option value="">All statuses</option><option value="scheduled">Scheduled</option><option value="confirmed">Confirmed</option><option value="completed">Completed</option><option value="cancelled">Cancelled</option><option value="no_show">No show</option>
        </select>
      </div>
      <div class="col-md-2"><span class="text-muted small" id="apptCount"></span></div>
    </div>
  </div>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead><tr><th>When</th><th>Patient</th><th>Type</th><th>Reason</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
      <tbody id="apptTbody"></tbody>
    </table>
  </div>
  <div class="card-body d-flex justify-content-end" id="apptPager"></div>
</div>

<div class="modal fade" id="reschedModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" id="reschedForm" action="../api/appointments.php" method="post">
      <div class="modal-header"><h5 class="modal-title">Reschedule Appointment</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body">
        <input type="hidden" name="action" value="reschedule">
        <input type="hidden" name="id" id="rsId">
        <input type="hidden" id="rsDoctor" value="<?= $did ?>">
        <p class="small text-muted" id="rsInfo"></p>
        <div class="mb-3"><label class="form-label">New date *</label><input class="form-control" type="date" name="date" id="rsDate" required min="<?= date('Y-m-d') ?>"></div>
        <div><label class="form-label">Free slots *</label><div id="rsSlots" class="border rounded p-2"><span class="text-muted small">Pick a date to see your free slots.</span></div></div>
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
    count: '#apptCount',
    search: '#apptSearch',
    filters: { status: '#apptStatus', date: '#apptDate' },
    columns: 6,
    emptyText: 'No appointments match your filters.',
    renderRow: function (a) {
      var actions = '<a class="btn btn-sm btn-outline-primary" href="patients.php?id=' + a.patient_id + '" title="Patient profile"><i class="bi bi-person"></i></a>' +
        ' <a class="btn btn-sm btn-outline-success" href="prescriptions.php?patient=' + a.patient_id + '" title="Write prescription"><i class="bi bi-prescription2"></i></a>';
      if (a.status === 'scheduled') actions += ' <button type="button" class="btn btn-sm btn-outline-success" data-status="confirmed" data-id="' + a.id + '" title="Confirm"><i class="bi bi-check-lg"></i></button>';
      if (a.status === 'scheduled' || a.status === 'confirmed') {
        actions += ' <button type="button" class="btn btn-sm btn-outline-primary" data-status="completed" data-id="' + a.id + '" title="Mark visit completed"><i class="bi bi-check2-all"></i></button>' +
          ' <button type="button" class="btn btn-sm btn-outline-dark" data-status="no_show" data-id="' + a.id + '" title="Mark no-show"><i class="bi bi-person-x"></i></button>' +
          ' <button type="button" class="btn btn-sm btn-outline-secondary" data-resched="' + a.id + '" data-info="' + esc(a.patient_name + ' · ' + a.date + ' ' + a.time) + '" title="Reschedule"><i class="bi bi-calendar-event"></i></button>' +
          ' <button type="button" class="btn btn-sm btn-outline-danger" data-status="cancelled" data-id="' + a.id + '" title="Cancel"><i class="bi bi-x-lg"></i></button>';
      }
      return '<tr><td><strong class="d-block">' + esc(a.date) + '</strong><small class="text-muted">' + esc(a.time) + '</small></td>' +
        '<td><a href="patients.php?id=' + a.patient_id + '">' + esc(a.patient_name) + '</a><br><small class="text-muted">' + esc(a.patient_code) + '</small></td>' +
        '<td>' + esc(a.type) + '</td><td>' + esc(a.reason || '—') + '</td>' +
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
      document.getElementById('rsInfo').textContent = reschedBtn.getAttribute('data-info');
      document.getElementById('rsDate').value = '';
      document.getElementById('rsSlots').innerHTML = '<span class="text-muted small">Pick a date to see your free slots.</span>';
      bootstrap.Modal.getOrCreateInstance(document.getElementById('reschedModal')).show();
    }
  });
  MediCare.bindSlots({ doctor: '#rsDoctor', date: '#rsDate', wrap: '#rsSlots', name: 'time' });
  MediCare.ajaxForm(document.getElementById('reschedForm'), { onSuccess: function () { table.reload(); } });
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
