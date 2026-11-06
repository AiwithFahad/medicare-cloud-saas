<?php
declare(strict_types=1);
/* Receptionist — Patients (Upgrade v2 page tree).
 * Front-desk patient registry: live search / pagination and AJAX
 * register/edit through api/patients.php (patients.manage enforced by
 * the API). The profile view (?id=N) shows contact + visit basics,
 * upcoming appointments and outstanding bills for the desk. */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/panel.php';
$pdo = db();
$user = require_role(['receptionist']);
$tid = require_tenant();
check_tenant_active($pdo);
if (!has_permission('patients.view')) {
    flash('error', 'You do not have permission to view patients.');
    redirect('dashboard.php');
}
$canManage = has_permission('patients.manage');
$base = '../';
$panelSidebar = 'receptionist';

$profileId = (int)($_GET['id'] ?? 0);
if ($profileId > 0) {
    $ps = $pdo->prepare('SELECT * FROM patients WHERE id = ? AND tenant_id = ?');
    $ps->execute([$profileId, $tid]);
    $patient = $ps->fetch() ?: null;
    if (!$patient) {
        flash('error', 'Patient not found.');
        redirect('patients.php');
    }
    $appts = $pdo->prepare(
        "SELECT a.*, d.name AS doctor_name FROM appointments a JOIN doctors d ON d.id = a.doctor_id
          WHERE a.tenant_id = ? AND a.patient_id = ? AND a.appointment_date >= CURDATE()
          ORDER BY a.appointment_date, a.appointment_time LIMIT 8"
    );
    $appts->execute([$tid, $profileId]);
    $appts = $appts->fetchAll();
    $invs = $pdo->prepare("SELECT * FROM patient_invoices WHERE tenant_id = ? AND patient_id = ? ORDER BY id DESC LIMIT 8");
    $invs->execute([$tid, $profileId]);
    $invs = $invs->fetchAll();

    $pageTitle = 'Patient — ' . $patient['name'];
    include __DIR__ . '/../includes/header.php';
    ?>
    <p><a href="patients.php" class="small"><i class="bi bi-arrow-left"></i> Back to patients</a></p>
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
              <dt class="col-5 text-muted">Address</dt><dd class="col-7"><?= e($patient['address'] ?? '—') ?></dd>
              <dt class="col-5 text-muted">Emergency contact</dt><dd class="col-7"><?= e($patient['emergency_contact'] ?? '—') ?></dd>
              <dt class="col-5 text-muted">Registered</dt><dd class="col-7"><?= fmt_date($patient['created_at']) ?></dd>
            </dl>
            <a class="btn btn-primary w-100 mt-3" href="appointments.php"><i class="bi bi-calendar-plus"></i> Book an appointment</a>
          </div>
        </div>
      </div>
      <div class="col-lg-8">
        <div class="card mb-3">
          <div class="card-header">Upcoming appointments</div>
          <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
              <thead><tr><th>When</th><th>Doctor</th><th>Type</th><th>Status</th></tr></thead>
              <tbody>
              <?php if (!$appts): ?><tr><td colspan="4" class="text-muted text-center py-3">No upcoming appointments.</td></tr><?php endif; ?>
              <?php foreach ($appts as $a): ?>
                <tr><td><?= fmt_date($a['appointment_date']) ?> · <?= e(date('h:i A', strtotime((string)$a['appointment_time']))) ?></td><td><?= e($a['doctor_name']) ?></td><td><?= e($a['type']) ?></td><td><?= status_badge((string)$a['status']) ?></td></tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
        <div class="card">
          <div class="card-header">Bills</div>
          <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
              <thead><tr><th>Invoice</th><th>Total</th><th>Paid</th><th>Balance</th><th>Status</th><th></th></tr></thead>
              <tbody>
              <?php if (!$invs): ?><tr><td colspan="6" class="text-muted text-center py-3">No bills yet.</td></tr><?php endif; ?>
              <?php foreach ($invs as $inv): ?>
                <tr><td><?= e($inv['invoice_no']) ?></td><td><?= money((float)$inv['total']) ?></td><td><?= money((float)$inv['amount_paid']) ?></td><td><?= money((float)$inv['total'] - (float)$inv['amount_paid']) ?></td><td><?= status_badge((string)$inv['status']) ?></td>
                <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="billing.php"><i class="bi bi-cash-coin"></i> Billing</a></td></tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
    <?php include __DIR__ . '/../includes/footer.php';
    return;
}

$pageTitle = 'Patients';
include __DIR__ . '/../includes/header.php';
?>
<div class="card">
  <div class="card-header">
    <div class="row g-2 align-items-center">
      <div class="col-md-5"><div class="search-wrap w-100"><i class="bi bi-search"></i><input class="form-control" id="patSearch" type="search" placeholder="Search name, patient ID, phone…" aria-label="Search patients"></div></div>
      <div class="col-md-2">
        <select class="form-select" id="patGender" aria-label="Filter by gender">
          <option value="">All genders</option><option value="male">Male</option><option value="female">Female</option><option value="other">Other</option>
        </select>
      </div>
      <div class="col-md-2"><span class="text-muted small" id="patCount"></span></div>
      <div class="col-md-3 text-md-end">
        <?php if ($canManage): ?><button class="btn btn-primary" type="button" id="addPatientBtn"><i class="bi bi-person-plus"></i> Register Patient</button><?php endif; ?>
      </div>
    </div>
  </div>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead><tr><th>Patient</th><th>Age / Gender</th><th>Blood</th><th>Contact</th><th>Visits</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
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
    columns: 7,
    emptyText: 'No patients match your search.',
    renderRow: function (p) {
      var status = p.admitted_now ? '<span class="badge text-bg-danger">Admitted</span>' : '<span class="badge text-bg-success">Outpatient</span>';
      var actions = '<a class="btn btn-sm btn-outline-primary" href="patients.php?id=' + p.id + '" title="Open profile"><i class="bi bi-eye"></i></a>';
      if (canManage) {
        actions += ' <button type="button" class="btn btn-sm btn-outline-secondary" data-edit="' + p.id + '" data-code="' + esc(p.patient_code) + '" title="Edit"><i class="bi bi-pencil"></i></button>';
      }
      return '<tr><td><div class="d-flex align-items-center gap-2"><span class="avatar-initials">' + esc(p.name.split(' ').map(function (w) { return w.charAt(0); }).join('').slice(0, 2).toUpperCase()) + '</span><span><strong class="d-block">' + esc(p.name) + '</strong><small class="text-muted">' + esc(p.patient_code) + '</small></span></div></td>' +
        '<td>' + esc(p.age) + ' · ' + esc(p.gender ? p.gender.charAt(0).toUpperCase() + p.gender.slice(1) : '—') + '</td>' +
        '<td>' + esc(p.blood_group || '—') + '</td>' +
        '<td><small class="d-block">' + esc(p.phone || '—') + '</small><small class="text-muted">' + esc(p.email || '') + '</small></td>' +
        '<td>' + p.visits + '</td><td>' + status + '</td><td class="text-end">' + actions + '</td></tr>';
    }
  });
  document.querySelector('#patTbody').addEventListener('click', function (ev) {
    var editBtn = ev.target.closest('[data-edit]');
    if (!editBtn) return;
    var id = parseInt(editBtn.getAttribute('data-edit'), 10);
    MediCare.api('patients.php?q=' + encodeURIComponent(editBtn.getAttribute('data-code')) + '&per_page=100').then(function (data) {
      var p = (data.rows || []).find(function (r) { return r.id === id; });
      if (!p) { MediCare.toast('Could not load patient.', 'error'); return; }
      document.getElementById('patientModalTitle').textContent = 'Edit Patient — ' + p.patient_code;
      document.getElementById('patientAction').value = 'update';
      document.getElementById('patientId').value = p.id;
      [['name', p.name], ['gender', p.gender], ['dob', p.dob], ['blood', p.blood_group], ['phone', p.phone], ['email', p.email], ['emergency', p.emergency_contact], ['allergies', p.allergies], ['address', p.address]].forEach(function (pair) {
        var el = document.getElementById('pf-' + pair[0]);
        if (el) el.value = pair[1] || '';
      });
      bootstrap.Modal.getOrCreateInstance(document.getElementById('patientModal')).show();
    }).catch(function (e) { MediCare.toast(e.message, 'error'); });
  });
  var addBtn = document.getElementById('addPatientBtn');
  var form = document.getElementById('patientForm');
  if (addBtn && form) {
    addBtn.addEventListener('click', function () {
      form.reset();
      document.getElementById('patientModalTitle').textContent = 'Register Patient';
      document.getElementById('patientAction').value = 'create';
      document.getElementById('patientId').value = '';
      bootstrap.Modal.getOrCreateInstance(document.getElementById('patientModal')).show();
    });
    MediCare.ajaxForm(form, { onSuccess: function () { table.reload(); } });
  }
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
