<?php
declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';

/* ---- Options: DB first, demo fallback ---- */
$fallbackTenants = [
    ['id' => 1, 'name' => 'City Care Hospital'],
    ['id' => 2, 'name' => 'Riverside Medical Center'],
    ['id' => 3, 'name' => 'Sunrise Children Clinic'],
];
$fallbackDoctors = [
    ['id' => 1, 'tenant_id' => 1, 'name' => 'Dr. Sarah Khan', 'specialization' => 'Interventional Cardiology', 'consultation_fee' => 80, 'department_id' => 1],
    ['id' => 2, 'tenant_id' => 1, 'name' => 'Dr. James Patel', 'specialization' => 'Neurology', 'consultation_fee' => 90, 'department_id' => 2],
    ['id' => 3, 'tenant_id' => 1, 'name' => 'Dr. Laura Kim', 'specialization' => 'Pediatrics', 'consultation_fee' => 60, 'department_id' => 3],
    ['id' => 4, 'tenant_id' => 1, 'name' => 'Dr. Omar Farooq', 'specialization' => 'Emergency Medicine', 'consultation_fee' => 100, 'department_id' => 4],
    ['id' => 5, 'tenant_id' => 1, 'name' => 'Dr. Nina Rodriguez', 'specialization' => 'Orthopedic Surgery', 'consultation_fee' => 85, 'department_id' => 5],
    ['id' => 6, 'tenant_id' => 1, 'name' => 'Dr. Peter Novak', 'specialization' => 'General Surgery', 'consultation_fee' => 95, 'department_id' => 6],
];
$tenants = $fallbackTenants;
$doctors = $fallbackDoctors;
$dbOk = false;
$pdo = null;
try {
    require_once __DIR__ . '/config/database.php';
    $pdo = db();
    $t = $pdo->query('SELECT id, name FROM tenants WHERE status = "active" ORDER BY id ASC')->fetchAll();
    if ($t) { $tenants = $t; }
    $d = $pdo->query('SELECT id, tenant_id, name, specialization, consultation_fee, department_id FROM doctors WHERE status = "active" ORDER BY tenant_id ASC, id ASC')->fetchAll();
    if ($d) { $doctors = $d; }
    $dbOk = true;
} catch (Throwable $ignored) {
    $pdo = null;
}

$booking = null;      // success summary
$demoNotice = false;  // DB unavailable on submit
$errors = [];
$old = ['tenant_id' => (string)($_GET['tenant'] ?? '1'), 'doctor_id' => (string)($_GET['doctor'] ?? ''), 'appointment_date' => date('Y-m-d', strtotime('+1 day')), 'appointment_time' => '09:30', 'patient_name' => '', 'patient_phone' => '', 'patient_email' => '', 'reason' => '', 'type' => 'OPD'];

if (is_post()) {
    verify_csrf();
    foreach (array_keys($old) as $k) {
        $old[$k] = trim((string)($_POST[$k] ?? $old[$k]));
    }
    if ($old['patient_name'] === '') { $errors[] = 'Please enter the patient name.'; }
    if ($old['patient_phone'] === '') { $errors[] = 'Please enter a contact phone number.'; }
    if ($old['patient_email'] !== '' && !filter_var($old['patient_email'], FILTER_VALIDATE_EMAIL)) { $errors[] = 'Please enter a valid email address.'; }
    if ($old['appointment_date'] === '' || strtotime($old['appointment_date']) === false) { $errors[] = 'Please choose an appointment date.'; }
    if ($old['appointment_time'] === '') { $errors[] = 'Please choose an appointment time.'; }
    $doctorRow = null;
    foreach ($doctors as $d) {
        if ((string)$d['id'] === $old['doctor_id']) { $doctorRow = $d; break; }
    }
    if (!$doctorRow) { $errors[] = 'Please choose a doctor.'; }
    $tenantRow = null;
    foreach ($tenants as $t) {
        if ((string)$t['id'] === $old['tenant_id']) { $tenantRow = $t; break; }
    }
    if (!$tenantRow) { $errors[] = 'Please choose a hospital.'; }

    if (!$errors) {
        if (!$dbOk || $pdo === null) {
            $demoNotice = true;
        } else {
            try {
                $pdo->beginTransaction();
                $tenantId = (int)$old['tenant_id'];
                $doctorId = (int)$old['doctor_id'];

                // Fresh doctor data (fee + department) from the DB.
                $ds = $pdo->prepare('SELECT id, name, specialization, consultation_fee, department_id FROM doctors WHERE id = ? AND tenant_id = ? AND status = "active"');
                $ds->execute([$doctorId, $tenantId]);
                $doc = $ds->fetch();
                if (!$doc) {
                    // Doctor may belong to the selected hospital's roster in demo data; fall back to doctor lookup by id only.
                    $ds = $pdo->prepare('SELECT id, name, specialization, consultation_fee, department_id, tenant_id FROM doctors WHERE id = ? AND status = "active"');
                    $ds->execute([$doctorId]);
                    $doc = $ds->fetch();
                    if ($doc) { $tenantId = (int)$doc['tenant_id']; }
                }
                if (!$doc) { throw new RuntimeException('Doctor not found'); }

                // Find-or-create the patient under the chosen tenant (match on phone, then email).
                $patientId = null;
                $patientCode = null;
                $ps = $pdo->prepare('SELECT id, patient_code FROM patients WHERE tenant_id = ? AND phone = ? LIMIT 1');
                $ps->execute([$tenantId, $old['patient_phone']]);
                $existing = $ps->fetch();
                if (!$existing && $old['patient_email'] !== '') {
                    $ps = $pdo->prepare('SELECT id, patient_code FROM patients WHERE tenant_id = ? AND email = ? LIMIT 1');
                    $ps->execute([$tenantId, $old['patient_email']]);
                    $existing = $ps->fetch();
                }
                if ($existing) {
                    $patientId = (int)$existing['id'];
                    $patientCode = (string)$existing['patient_code'];
                } else {
                    $tmpCode = 'TMP-' . strtoupper(bin2hex(random_bytes(4)));
                    $ins = $pdo->prepare('INSERT INTO patients (tenant_id, patient_code, name, phone, email, status, created_at) VALUES (?, ?, ?, ?, ?, "active", NOW())');
                    $ins->execute([$tenantId, $tmpCode, $old['patient_name'], $old['patient_phone'], $old['patient_email'] !== '' ? $old['patient_email'] : null]);
                    $patientId = (int)$pdo->lastInsertId();
                    $patientCode = 'PAT-' . str_pad((string)$patientId, 4, '0', STR_PAD_LEFT);
                    $upd = $pdo->prepare('UPDATE patients SET patient_code = ? WHERE id = ?');
                    $upd->execute([$patientCode, $patientId]);
                }

                $types = ['OPD', 'Follow-up', 'Emergency', 'Teleconsult'];
                $type = in_array($old['type'], $types, true) ? $old['type'] : 'OPD';
                $as = $pdo->prepare('INSERT INTO appointments (tenant_id, patient_id, doctor_id, department_id, appointment_date, appointment_time, type, reason, status, fee, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, "scheduled", ?, NOW())');
                $as->execute([
                    $tenantId, $patientId, (int)$doc['id'], $doc['department_id'] !== null ? (int)$doc['department_id'] : null,
                    $old['appointment_date'], $old['appointment_time'] . (strlen($old['appointment_time']) === 5 ? ':00' : ''),
                    $type, $old['reason'] !== '' ? $old['reason'] : null, (float)$doc['consultation_fee'],
                ]);
                $appointmentId = (int)$pdo->lastInsertId();
                $pdo->commit();

                $ts = $pdo->prepare('SELECT name FROM tenants WHERE id = ?');
                $ts->execute([$tenantId]);
                $booking = [
                    'id' => $appointmentId,
                    'hospital' => (string)($ts->fetchColumn() ?: ($tenantRow['name'] ?? '')),
                    'doctor' => (string)$doc['name'],
                    'specialization' => (string)($doc['specialization'] ?? ''),
                    'patient' => $old['patient_name'],
                    'patient_code' => $patientCode,
                    'date' => $old['appointment_date'],
                    'time' => $old['appointment_time'],
                    'type' => $type,
                    'fee' => (float)$doc['consultation_fee'],
                ];
            } catch (Throwable $ex) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                $demoNotice = true;
            }
        }
    }
}

$pageTitle = 'Book Appointment';
$base = '';
include __DIR__ . '/includes/site-header.php';
?>
<section class="page-hero">
  <div class="container">
    <span class="kicker">Online Booking</span>
    <h1 class="display-5 fw-bold mt-2">Book an <span class="text-primary">appointment</span></h1>
    <p class="lead text-muted" style="max-width:680px">Choose a hospital and doctor, pick a time that suits you, and we will reserve your slot instantly. A patient record is created for you automatically.</p>
  </div>
</section>

<section class="section" style="padding-top:36px">
  <div class="container" style="max-width:980px">
    <div class="row g-5">
      <div class="col-lg-7">
        <?php if ($booking): ?>
        <div class="card p-4 border-success">
          <div class="text-center mb-3">
            <span class="avatar-initials bg-success mx-auto" style="width:64px;height:64px;font-size:1.6rem;background:linear-gradient(135deg,#10b981,#059669)"><i class="bi bi-check-lg"></i></span>
            <h3 class="mt-3">Appointment booked!</h3>
            <p class="text-muted mb-0">Your appointment is <strong>scheduled</strong>. Please arrive 15 minutes early and bring your patient code.</p>
          </div>
          <dl class="row mb-0">
            <dt class="col-sm-4 text-muted">Booking reference</dt><dd class="col-sm-8 fw-semibold">#<?= e((string)$booking['id']) ?></dd>
            <dt class="col-sm-4 text-muted">Patient</dt><dd class="col-sm-8"><?= e($booking['patient']) ?> <span class="badge text-bg-primary"><?= e($booking['patient_code']) ?></span></dd>
            <dt class="col-sm-4 text-muted">Hospital</dt><dd class="col-sm-8"><?= e($booking['hospital']) ?></dd>
            <dt class="col-sm-4 text-muted">Doctor</dt><dd class="col-sm-8"><?= e($booking['doctor']) ?><?= $booking['specialization'] !== '' ? ' — ' . e($booking['specialization']) : '' ?></dd>
            <dt class="col-sm-4 text-muted">Date &amp; time</dt><dd class="col-sm-8"><?= e(fmt_date($booking['date'])) ?> · <?= e(date('h:i A', strtotime($booking['time']))) ?></dd>
            <dt class="col-sm-4 text-muted">Visit type</dt><dd class="col-sm-8"><?= e($booking['type']) ?></dd>
            <dt class="col-sm-4 text-muted">Consultation fee</dt><dd class="col-sm-8 fw-semibold"><?= e(money($booking['fee'])) ?> <span class="small text-muted fw-normal">(payable at the hospital)</span></dd>
          </dl>
          <div class="d-flex gap-2 mt-4">
            <a class="btn btn-primary" href="book-appointment.php">Book another</a>
            <a class="btn btn-outline-primary" href="doctors.php">Browse doctors</a>
          </div>
        </div>
        <?php else: ?>
        <?php if ($demoNotice): ?>
        <div class="alert alert-info"><i class="bi bi-info-circle-fill me-1"></i> <strong>Demo mode:</strong> the live database is not reachable from this preview, so your booking was not saved. On a production deployment with PHP 8 + MySQL, this form creates the patient record and reserves the slot instantly. Your details were received — thank you!</div>
        <?php endif; ?>
        <?php if ($errors): ?>
        <div class="alert alert-danger"><strong>Please fix the following:</strong><ul class="mb-0 mt-1"><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
        <?php endif; ?>
        <form method="post" class="card p-4">
          <?= csrf_field() ?>
          <h4 class="mb-3"><i class="bi bi-calendar2-plus text-primary me-1"></i> Appointment details</h4>
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold" for="tenant">Hospital *</label>
              <select class="form-select" id="tenant" name="tenant_id" required>
                <?php foreach ($tenants as $t): ?><option value="<?= e((string)$t['id']) ?>"<?= $old['tenant_id'] === (string)$t['id'] ? ' selected' : '' ?>><?= e((string)$t['name']) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold" for="doctor">Doctor *</label>
              <select class="form-select" id="doctor" name="doctor_id" required>
                <option value="">— Choose a doctor —</option>
                <?php foreach ($doctors as $d): ?>
                <option value="<?= e((string)$d['id']) ?>" data-tenant="<?= e((string)($d['tenant_id'] ?? '')) ?>"<?= $old['doctor_id'] === (string)$d['id'] ? ' selected' : '' ?>><?= e((string)$d['name']) ?> — <?= e((string)($d['specialization'] ?? '')) ?> (<?= e(money($d['consultation_fee'] ?? 0)) ?>)</option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold" for="date">Date *</label>
              <input class="form-control" id="date" type="date" name="appointment_date" min="<?= e(date('Y-m-d')) ?>" value="<?= e($old['appointment_date']) ?>" required>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold" for="time">Time *</label>
              <input class="form-control" id="time" type="time" name="appointment_time" value="<?= e($old['appointment_time']) ?>" required>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold" for="type">Visit type</label>
              <select class="form-select" id="type" name="type">
                <?php foreach (['OPD', 'Follow-up', 'Emergency', 'Teleconsult'] as $ty): ?><option<?= $old['type'] === $ty ? ' selected' : '' ?>><?= e($ty) ?></option><?php endforeach; ?>
              </select>
            </div>
          </div>
          <h4 class="mt-4 mb-3"><i class="bi bi-person text-primary me-1"></i> Patient details</h4>
          <div class="row g-3">
            <div class="col-md-6"><label class="form-label fw-semibold" for="pname">Full name *</label><input class="form-control" id="pname" name="patient_name" value="<?= e($old['patient_name']) ?>" required></div>
            <div class="col-md-6"><label class="form-label fw-semibold" for="pphone">Phone *</label><input class="form-control" id="pphone" name="patient_phone" value="<?= e($old['patient_phone']) ?>" placeholder="+1 555 000 0000" required></div>
            <div class="col-12"><label class="form-label fw-semibold" for="pemail">Email</label><input class="form-control" id="pemail" type="email" name="patient_email" value="<?= e($old['patient_email']) ?>"></div>
            <div class="col-12"><label class="form-label fw-semibold" for="reason">Reason for visit</label><textarea class="form-control" id="reason" name="reason" rows="3" placeholder="Briefly describe your symptoms or reason"><?= e($old['reason']) ?></textarea></div>
            <div class="col-12"><button class="btn btn-success btn-lg" type="submit"><i class="bi bi-calendar-check me-1"></i> Confirm Booking</button></div>
          </div>
        </form>
        <?php endif; ?>
      </div>
      <div class="col-lg-5">
        <div class="feature-card mb-4">
          <h5><i class="bi bi-lightning-charge-fill text-success me-1"></i> How it works</h5>
          <ol class="mb-0 text-muted">
            <li class="mb-2">Pick your hospital, doctor, date and time.</li>
            <li class="mb-2">We find or create your patient record automatically — no forms at the desk.</li>
            <li class="mb-2">Your slot is reserved instantly with status <strong>scheduled</strong>.</li>
            <li>Pay the consultation fee at the hospital on arrival.</li>
          </ol>
        </div>
        <div class="feature-card">
          <h5><i class="bi bi-shield-lock-fill text-primary me-1"></i> Your privacy</h5>
          <p class="text-muted small mb-0">Your details are stored only under the hospital you chose (tenant-isolated) and are never shared with other hospitals on the platform. See our <a href="privacy.php">Privacy Policy</a>.</p>
        </div>
      </div>
    </div>
  </div>
</section>
<script>
(function () {
  var tenant = document.getElementById('tenant');
  var doctor = document.getElementById('doctor');
  if (!tenant || !doctor) return;
  function filterDoctors() {
    var t = tenant.value;
    var hasForTenant = false;
    Array.prototype.forEach.call(doctor.options, function (opt) {
      if (opt.value && opt.getAttribute('data-tenant') === t) hasForTenant = true;
    });
    Array.prototype.forEach.call(doctor.options, function (opt) {
      if (!opt.value) return;
      opt.hidden = hasForTenant && opt.getAttribute('data-tenant') && opt.getAttribute('data-tenant') !== t;
    });
    if (doctor.selectedOptions.length && doctor.selectedOptions[0].hidden) doctor.value = '';
  }
  tenant.addEventListener('change', filterDoctors);
  filterDoctors();
})();
</script>
<?php include __DIR__ . '/includes/site-footer.php'; ?>
