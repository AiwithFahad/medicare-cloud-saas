<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
$pdo = db();
$user = require_role(['patient']);
$tid = require_tenant();
check_tenant_active($pdo);

/* The portal is keyed off the patients row linked to this login. */
$ps = $pdo->prepare('SELECT * FROM patients WHERE tenant_id=? AND user_id=? LIMIT 1');
$ps->execute([$tid, (int)$user['id']]);
$patient = $ps->fetch() ?: null;

$upcoming = $past = $bills = $records = $prescriptions = [];
if ($patient) {
    $pid = (int)$patient['id'];
    $us = $pdo->prepare('SELECT a.*, d.name AS doctor_name, d.specialization, dep.name AS department_name FROM appointments a JOIN doctors d ON d.id=a.doctor_id LEFT JOIN departments dep ON dep.id=a.department_id WHERE a.tenant_id=? AND a.patient_id=? AND a.appointment_date>=CURDATE() AND a.status IN ("scheduled","confirmed") ORDER BY a.appointment_date ASC, a.appointment_time ASC');
    $us->execute([$tid, $pid]);
    $upcoming = $us->fetchAll();

    $pas = $pdo->prepare('SELECT a.*, d.name AS doctor_name, d.specialization, dep.name AS department_name FROM appointments a JOIN doctors d ON d.id=a.doctor_id LEFT JOIN departments dep ON dep.id=a.department_id WHERE a.tenant_id=? AND a.patient_id=? AND (a.appointment_date<CURDATE() OR a.status IN ("completed","cancelled","no_show")) ORDER BY a.appointment_date DESC, a.appointment_time DESC LIMIT 20');
    $pas->execute([$tid, $pid]);
    $past = $pas->fetchAll();

    $bs = $pdo->prepare('SELECT * FROM patient_invoices WHERE tenant_id=? AND patient_id=? ORDER BY id DESC LIMIT 20');
    $bs->execute([$tid, $pid]);
    $bills = $bs->fetchAll();

    // Only records the care team explicitly shared with the patient
    $rs = $pdo->prepare('SELECT mr.*, d.name AS doctor_name FROM medical_records mr LEFT JOIN doctors d ON d.id=mr.doctor_id WHERE mr.tenant_id=? AND mr.patient_id=? AND mr.visibility="patient" ORDER BY mr.created_at DESC LIMIT 30');
    $rs->execute([$tid, $pid]);
    $records = $rs->fetchAll();

    $xs = $pdo->prepare('SELECT pr.*, d.name AS doctor_name FROM prescriptions pr JOIN doctors d ON d.id=pr.doctor_id WHERE pr.tenant_id=? AND pr.patient_id=? ORDER BY pr.prescription_date DESC, pr.id DESC LIMIT 20');
    $xs->execute([$tid, $pid]);
    $prescriptions = $xs->fetchAll();
    $itemS = $pdo->prepare('SELECT * FROM prescription_items WHERE prescription_id=? ORDER BY id');
    foreach ($prescriptions as $i => $rx) {
        $itemS->execute([(int)$rx['id']]);
        $prescriptions[$i]['items'] = $itemS->fetchAll();
    }
}
$outstanding = 0.0;
foreach ($bills as $b) $outstanding += max(0, (float)$b['total'] - (float)$b['amount_paid']);

$pageTitle = 'My Portal';
$base = '../';
include __DIR__ . '/../includes/header.php';
?>
<?php if (!$patient): ?>
<div class="card"><div class="empty-state">
  <img src="../assets/img/empty.svg" alt="">
  <h5>Welcome, <?= e($user['name']) ?></h5>
  <p>Your login isn't linked to a patient record at this hospital yet, so there's nothing to show here.<br>Please ask the hospital reception to link your account — your appointments, bills, records and prescriptions will then appear in this portal automatically.</p>
</div></div>
<?php else: ?>
<div class="row g-4 mb-4">
  <div class="col-lg-4">
    <div class="card h-100"><div class="card-body text-center">
      <span class="avatar-initials mx-auto mb-2" style="width:64px;height:64px;font-size:1.4rem"><?= e(initials((string)$patient['name'])) ?></span>
      <h4 class="mb-0"><?= e($patient['name']) ?></h4>
      <div class="text-muted small mb-3"><?= e($patient['patient_code']) ?> · Patient</div>
      <div class="text-start small">
        <?php if ($patient['dob']): ?><div class="mb-1"><i class="bi bi-cake2 me-2 text-muted"></i>Date of birth: <?= fmt_date($patient['dob']) ?></div><?php endif; ?>
        <?php if ($patient['blood_group']): ?><div class="mb-1"><i class="bi bi-droplet me-2 text-muted"></i>Blood group: <?= e((string)$patient['blood_group']) ?></div><?php endif; ?>
        <?php if ($patient['phone']): ?><div class="mb-1"><i class="bi bi-telephone me-2 text-muted"></i><?= e((string)$patient['phone']) ?></div><?php endif; ?>
        <?php if ($patient['email']): ?><div class="mb-1"><i class="bi bi-envelope me-2 text-muted"></i><?= e((string)$patient['email']) ?></div><?php endif; ?>
        <?php if ($patient['address']): ?><div class="mb-1"><i class="bi bi-geo-alt me-2 text-muted"></i><?= e((string)$patient['address']) ?></div><?php endif; ?>
        <?php if ($patient['emergency_contact']): ?><div class="mb-1"><i class="bi bi-person-exclamation me-2 text-muted"></i>Emergency: <?= e((string)$patient['emergency_contact']) ?></div><?php endif; ?>
        <?php if ($patient['allergies']): ?><div class="mt-2"><span class="badge text-bg-danger">Allergies: <?= e((string)$patient['allergies']) ?></span></div><?php endif; ?>
      </div>
    </div></div>
  </div>
  <div class="col-lg-8">
    <div class="row g-3 h-100">
      <div class="col-md-4"><div class="card stat-card h-100"><span class="stat-ic ic-blue"><i class="bi bi-calendar-check"></i></span><div><div class="num"><?= count($upcoming) ?></div><div class="lbl">Upcoming appointments</div></div></div></div>
      <div class="col-md-4"><div class="card stat-card h-100"><span class="stat-ic ic-amber"><i class="bi bi-receipt"></i></span><div><div class="num"><?= money($outstanding) ?></div><div class="lbl">Outstanding balance</div></div></div></div>
      <div class="col-md-4"><div class="card stat-card h-100"><span class="stat-ic ic-green"><i class="bi bi-capsule"></i></span><div><div class="num"><?= count($prescriptions) ?></div><div class="lbl">Prescriptions</div></div></div></div>
      <div class="col-12"><div class="card h-100"><div class="card-body">
        <h6>Your next appointment</h6>
        <?php if ($upcoming): $n = $upcoming[0]; ?>
        <p class="mb-0"><strong><?= fmt_date($n['appointment_date']) ?> at <?= e(date('h:i A', strtotime((string)$n['appointment_time']))) ?></strong> — <?= e($n['doctor_name']) ?><?= $n['department_name'] ? ' (' . e((string)$n['department_name']) . ')' : '' ?><?= $n['reason'] ? '<br><span class="text-muted small">' . e((string)$n['reason']) . '</span>' : '' ?></p>
        <?php else: ?><p class="text-muted mb-0">You have no upcoming appointments. Contact the hospital reception to book one.</p><?php endif; ?>
      </div></div></div>
    </div>
  </div>
</div>

<div class="card mb-4" id="appointments"><div class="card-header">My appointments</div>
  <div class="card-body">
    <h6>Upcoming</h6>
    <?php if (!$upcoming): ?><p class="text-muted small">No upcoming appointments.</p><?php else: ?>
    <div class="table-responsive mb-4"><table class="table table-sm"><thead><tr><th>Date &amp; time</th><th>Doctor</th><th>Department</th><th>Type</th><th>Reason</th><th>Status</th></tr></thead>
    <tbody><?php foreach ($upcoming as $a): ?><tr><td><?= fmt_date($a['appointment_date']) ?> · <?= e(date('h:i A', strtotime((string)$a['appointment_time']))) ?></td><td><?= e($a['doctor_name']) ?><?= $a['specialization'] ? '<br><small class="text-muted">' . e((string)$a['specialization']) . '</small>' : '' ?></td><td><?= e((string)($a['department_name'] ?? '—')) ?></td><td><?= e($a['type']) ?></td><td><?= e((string)($a['reason'] ?? '—')) ?></td><td><?= status_badge((string)$a['status']) ?></td></tr><?php endforeach; ?></tbody></table></div>
    <?php endif; ?>
    <h6>Past</h6>
    <?php if (!$past): ?><p class="text-muted small mb-0">No past appointments yet.</p><?php else: ?>
    <div class="table-responsive"><table class="table table-sm"><thead><tr><th>Date &amp; time</th><th>Doctor</th><th>Department</th><th>Type</th><th>Status</th></tr></thead>
    <tbody><?php foreach ($past as $a): ?><tr><td><?= fmt_date($a['appointment_date']) ?> · <?= e(date('h:i A', strtotime((string)$a['appointment_time']))) ?></td><td><?= e($a['doctor_name']) ?></td><td><?= e((string)($a['department_name'] ?? '—')) ?></td><td><?= e($a['type']) ?></td><td><?= status_badge((string)$a['status']) ?></td></tr><?php endforeach; ?></tbody></table></div>
    <?php endif; ?>
  </div>
</div>

<div class="row g-4">
  <div class="col-lg-6">
    <div class="card mb-4" id="bills"><div class="card-header">My bills</div>
      <div class="table-responsive"><table class="table table-sm"><thead><tr><th>Invoice</th><th>Date</th><th class="text-end">Total</th><th class="text-end">Paid</th><th class="text-end">Balance</th><th>Status</th></tr></thead>
      <tbody>
      <?php if (!$bills): ?><tr><td colspan="6" class="text-muted text-center py-3">No bills yet — you're all clear.</td></tr><?php endif; ?>
      <?php foreach ($bills as $bill): ?><tr><td><?= e($bill['invoice_no']) ?></td><td><?= fmt_date($bill['created_at']) ?></td><td class="text-end"><?= money($bill['total']) ?></td><td class="text-end"><?= money($bill['amount_paid']) ?></td><td class="text-end fw-semibold"><?= money((float)$bill['total'] - (float)$bill['amount_paid']) ?></td><td><?= status_badge((string)$bill['status']) ?></td></tr><?php endforeach; ?>
      </tbody></table></div>
      <?php if ($outstanding > 0): ?><div class="card-body small text-muted pt-2">You have an outstanding balance of <strong><?= money($outstanding) ?></strong>. Please settle it at the hospital cashier or ask reception about payment options.</div><?php endif; ?>
    </div>

    <div class="card" id="records"><div class="card-header">My medical records <small class="text-muted">(shared with you)</small></div>
      <div class="card-body">
      <?php if (!$records): ?><p class="text-muted mb-0">No records have been shared with you yet. Your care team can share diagnoses and reports here.</p><?php endif; ?>
      <?php foreach ($records as $r): ?>
        <div class="pb-3 mb-3 border-bottom">
          <div class="d-flex justify-content-between gap-2"><strong><?= e($r['title']) ?></strong><small class="text-muted"><?= fmt_date($r['created_at']) ?></small></div>
          <div class="small text-muted text-capitalize"><?= e($r['record_type']) ?><?= $r['doctor_name'] ? ' · ' . e((string)$r['doctor_name']) : '' ?></div>
          <?php if ($r['details']): ?><p class="small mb-0 mt-1"><?= e((string)$r['details']) ?></p><?php endif; ?>
        </div>
      <?php endforeach; ?>
      </div>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="card"><div class="card-header">My prescriptions</div>
      <div class="card-body">
      <?php if (!$prescriptions): ?><p class="text-muted mb-0">No prescriptions yet.</p><?php endif; ?>
      <?php foreach ($prescriptions as $rx): ?>
        <div class="pb-3 mb-3 border-bottom">
          <div class="d-flex justify-content-between gap-2"><strong><?= fmt_date($rx['prescription_date']) ?> — <?= e($rx['doctor_name']) ?></strong><?= status_badge((string)$rx['status']) ?></div>
          <?php if ($rx['diagnosis']): ?><div class="small text-muted"><?= e((string)$rx['diagnosis']) ?></div><?php endif; ?>
          <ul class="small mt-2 mb-0">
            <?php foreach ($rx['items'] as $it): ?><li><strong><?= e($it['medicine_name']) ?></strong><?= $it['dosage'] ? ' — ' . e((string)$it['dosage']) : '' ?><?= $it['frequency'] ? ', ' . e((string)$it['frequency']) : '' ?><?= $it['duration'] ? ', for ' . e((string)$it['duration']) : '' ?> (qty <?= (int)$it['quantity'] ?>)<?= $it['instructions'] ? '<br><span class="text-muted">' . e((string)$it['instructions']) . '</span>' : '' ?></li><?php endforeach; ?>
          </ul>
        </div>
      <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
