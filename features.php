<?php
declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';
$pageTitle = 'Features';
$base = '';
include __DIR__ . '/includes/site-header.php';

$modules = [
    ['bi-people-fill', 'Patient Management', 'A single, permanent record for every patient.', [
        'Instant registration with auto-generated patient codes (PAT-0001…)',
        'Demographics, blood group, allergies & emergency contacts',
        'Complete timeline: visits, admissions, prescriptions, labs & bills',
        'Patient portal accounts linked securely to the record',
    ], 'PAT-0001 · John Miller · O+ · Penicillin allergy flagged on every screen'],
    ['bi-calendar2-week-fill', 'Doctor Scheduling & Appointments', 'Rosters patients and reception can both trust.', [
        'Per-doctor schedules, slot lengths & on-call rotations',
        'OPD, follow-up, emergency & teleconsult appointment types',
        'Public online booking with real-time slot availability',
        'Statuses from scheduled → confirmed → completed / no-show',
    ], 'Today · 48 appointments · 3 awaiting confirmation'],
    ['bi-hospital', 'Wards, Beds & Admissions', 'Know exactly where every patient is — and every free bed.', [
        'General, ICU, CCU, pediatric, maternity & private wards',
        'Live bed board: available, occupied, reserved, maintenance',
        'Admissions & discharges update beds and billing automatically',
        'Ward and floor organisation that matches your building',
    ], 'General Ward A · 86% occupied · 7 beds available now'],
    ['bi-capsule', 'Pharmacy & Prescriptions', 'From prescription pad to dispensing counter, tracked.', [
        'Medicine catalogue with stock, reorder levels & expiry dates',
        'Low-stock alerts before you run out — e.g. Insulin Glargine',
        'Prescriptions with dosage, frequency, duration & instructions',
        'Dispensing statuses: pending, partial and fully dispensed',
    ], '480 × Paracetamol 500mg in stock · 2 prescriptions pending'],
    ['bi-droplet-half', 'Laboratory', 'Orders, results and reference ranges in the patient record.', [
        'Test catalogue: CBC, lipid profile, HbA1c, ECG, X-ray & more',
        'Result entry with normal ranges and technician remarks',
        'Pending → in-progress → completed workflow with timestamps',
        'Results visible to doctors instantly and to patients in the portal',
    ], 'Lipid Profile · 186 mg/dL · Within acceptable range'],
    ['bi-receipt-cutoff', 'Billing & Payments', 'Every charge captured once, billed once, paid once.', [
        'Itemised invoices: consultation, lab, pharmacy, bed & procedures',
        'Partial payments with running balances and due dates',
        'Payment methods recorded per transaction with cashier audit',
        'Subscription invoices & coupons for your SaaS plan itself',
    ], 'BILL-0002 · $1,250 total · $500 paid · $750 balance'],
    ['bi-person-heart', 'Patient Portal', 'Give patients a reason to stop phoning the front desk.', [
        'Book, view and track their own appointments',
        'Lab results and medical records marked for patient visibility',
        'Bills and payment history, downloadable anytime',
        'Strictly their own data — enforced by tenant + patient scoping',
    ], 'Patient view · Next visit: Cardiology, Today 09:30'],
    ['bi-arrow-repeat', 'Subscriptions & Plans', 'Predictable SaaS billing for your hospital.', [
        'Five plans from Free to Enterprise with clear resource limits',
        '14-day trials, monthly or yearly billing (~20% yearly saving)',
        'Coupons such as WELCOME10 applied transparently at checkout',
        'Usage meters for doctors, staff, patients & departments',
    ], 'Professional plan · 6 of 30 doctors used · Renews in 20 days'],
    ['bi-bar-chart-line-fill', 'Reports, Roles & Security', 'Insight for managers, guardrails for everyone.', [
        'Revenue, occupancy, utilisation & department performance reports',
        'Nine built-in roles — from Super Admin to Patient — with granular permissions',
        'Audit logs and activity history on sensitive actions',
        'Multi-tenant isolation enforced on every single query',
    ], 'Revenue +18% this quarter · No-show rate down 32%'],
];
?>
<section class="page-hero">
  <div class="container">
    <span class="kicker">Features</span>
    <h1 class="display-5 fw-bold mt-2">Every module your hospital needs, <span class="text-primary">connected</span></h1>
    <p class="lead text-muted" style="max-width:720px">Nine modules that share one patient record, one schedule and one bill — explore each one below.</p>
    <a class="btn btn-primary btn-lg" href="auth/register.php">Start Free Trial</a>
    <a class="btn btn-outline-primary btn-lg ms-2" href="pricing.php">See Pricing</a>
  </div>
</section>

<?php foreach ($modules as $i => [$icon, $title, $lead, $points, $stat]): $alt = $i % 2 === 1; ?>
<section class="section<?= $alt ? ' section-alt' : '' ?>" style="padding:56px 0">
  <div class="container">
    <div class="row align-items-center g-5">
      <div class="col-lg-6<?= $alt ? ' order-lg-2' : '' ?>">
        <div class="feature-card" style="max-width:520px">
          <div class="fic"><i class="bi <?= e($icon) ?>"></i></div>
          <h3><?= e($title) ?></h3>
          <p class="text-muted"><?= e($lead) ?></p>
          <ul class="list-unstyled tick mb-0">
            <?php foreach ($points as $pt): ?><li><i class="bi bi-check-circle-fill text-success me-2"></i><?= e($pt) ?></li><?php endforeach; ?>
          </ul>
        </div>
      </div>
      <div class="col-lg-6<?= $alt ? ' order-lg-1' : '' ?>">
        <div class="dash-preview">
          <div class="d-flex align-items-center gap-3">
            <span class="avatar-initials" style="width:52px;height:52px"><i class="bi <?= e($icon) ?>"></i></span>
            <div>
              <strong><?= e($title) ?></strong>
              <div class="text-muted small"><?= e($stat) ?></div>
            </div>
            <span class="badge text-bg-success ms-auto">Live</span>
          </div>
          <hr>
          <div class="row g-2">
            <?php foreach ($points as $j => $pt): ?>
            <div class="col-6"><div class="mini-stat h-100"><div class="small fw-semibold"><?= e(explode('—', $pt)[0]) ?></div></div></div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>
<?php endforeach; ?>

<section class="pb-5 pt-3">
  <div class="container">
    <div class="cta-band text-center">
      <h2 class="mb-2">Try every module free for 14 days</h2>
      <p class="text-white-50 mx-auto mb-4" style="max-width:540px">Demo data is pre-loaded, so you can book appointments, admit patients and run reports from minute one.</p>
      <a class="btn btn-light btn-lg" href="auth/register.php">Start Free Trial</a>
      <a class="btn btn-outline-light btn-lg ms-2" href="book-appointment.php">Book a Demo Appointment</a>
    </div>
  </div>
</section>
<?php include __DIR__ . '/includes/site-footer.php'; ?>
