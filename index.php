<?php
declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';

/* ---- Public data: DB first, curated static fallback when DB is unreachable ---- */
$fallbackPlans = [
    ['name' => 'Free', 'slug' => 'free', 'monthly_price' => 0, 'yearly_price' => 0, 'features' => '["Up to 2 doctors","Patient registration","Appointment booking","Email support"]'],
    ['name' => 'Starter', 'slug' => 'starter', 'monthly_price' => 49, 'yearly_price' => 470, 'features' => '["Everything in Free","Bed management","Laboratory module","Billing & invoices"]'],
    ['name' => 'Professional', 'slug' => 'professional', 'monthly_price' => 99, 'yearly_price' => 950, 'features' => '["Everything in Starter","Pharmacy & prescriptions","Patient portal","Reports & analytics","Priority support"]'],
    ['name' => 'Business', 'slug' => 'business', 'monthly_price' => 199, 'yearly_price' => 1910, 'features' => '["Everything in Professional","Multi-branch ready","API access","Audit logs","Phone support"]'],
    ['name' => 'Enterprise', 'slug' => 'enterprise', 'monthly_price' => 399, 'yearly_price' => 3830, 'features' => '["Everything in Business","Unlimited doctors, staff & patients","Dedicated account manager","Custom integrations","99.9% uptime SLA"]'],
];
$fallbackDoctors = [
    ['name' => 'Dr. Sarah Khan', 'specialization' => 'Interventional Cardiology', 'qualification' => 'MBBS, MD Cardiology', 'consultation_fee' => 80, 'schedule' => 'Mon–Fri · 9:00 AM – 2:00 PM', 'dept_name' => 'Cardiology'],
    ['name' => 'Dr. James Patel', 'specialization' => 'Neurology', 'qualification' => 'MBBS, DM Neurology', 'consultation_fee' => 90, 'schedule' => 'Mon, Wed, Fri · 10:00 AM – 4:00 PM', 'dept_name' => 'Neurology'],
    ['name' => 'Dr. Laura Kim', 'specialization' => 'Pediatrics', 'qualification' => 'MBBS, MD Pediatrics', 'consultation_fee' => 60, 'schedule' => 'Mon–Sat · 9:00 AM – 1:00 PM', 'dept_name' => 'Pediatrics'],
    ['name' => 'Dr. Omar Farooq', 'specialization' => 'Emergency Medicine', 'qualification' => 'MBBS, FCPS', 'consultation_fee' => 100, 'schedule' => '24/7 on-call rotation', 'dept_name' => 'Emergency'],
    ['name' => 'Dr. Nina Rodriguez', 'specialization' => 'Orthopedic Surgery', 'qualification' => 'MBBS, MS Orthopedics', 'consultation_fee' => 85, 'schedule' => 'Tue, Thu, Sat · 11:00 AM – 5:00 PM', 'dept_name' => 'Orthopedics'],
    ['name' => 'Dr. Peter Novak', 'specialization' => 'General Surgery', 'qualification' => 'MBBS, MS Surgery', 'consultation_fee' => 95, 'schedule' => 'Mon–Thu · 8:00 AM – 12:00 PM', 'dept_name' => 'General Surgery'],
];
$fallbackDepts = [
    ['name' => 'Cardiology', 'description' => 'Heart and cardiovascular care', 'floor' => '2nd Floor'],
    ['name' => 'Neurology', 'description' => 'Brain, spine and nervous system', 'floor' => '3rd Floor'],
    ['name' => 'Pediatrics', 'description' => 'Child and adolescent healthcare', 'floor' => '1st Floor'],
    ['name' => 'Emergency', 'description' => '24/7 emergency and trauma care', 'floor' => 'Ground Floor'],
    ['name' => 'Orthopedics', 'description' => 'Bones, joints and sports injuries', 'floor' => '2nd Floor'],
    ['name' => 'General Surgery', 'description' => 'Surgical procedures and recovery', 'floor' => '4th Floor'],
];

$plans = $fallbackPlans;
$doctors = $fallbackDoctors;
$departments = $fallbackDepts;
try {
    require_once __DIR__ . '/config/database.php';
    $pdo = db();
    $rows = $pdo->query('SELECT name, slug, monthly_price, yearly_price, features FROM subscription_plans WHERE is_active = 1 ORDER BY sort_order ASC')->fetchAll();
    if ($rows) { $plans = $rows; }
    $rows = $pdo->query('SELECT d.name, d.specialization, d.qualification, d.consultation_fee, d.schedule, dep.name AS dept_name FROM doctors d LEFT JOIN departments dep ON dep.id = d.department_id WHERE d.tenant_id = 1 AND d.status = "active" ORDER BY d.id ASC LIMIT 6')->fetchAll();
    if ($rows) { $doctors = $rows; }
    $rows = $pdo->query('SELECT name, description, floor FROM departments WHERE tenant_id = 1 AND status = "active" ORDER BY id ASC LIMIT 6')->fetchAll();
    if ($rows) { $departments = $rows; }
} catch (Throwable $ignored) {
    // Static fallback content above is used when the DB is unreachable.
}

/* ---- Newsletter / contact mini-forms (self-post) ---- */
if (is_post() && (string)($_POST['form'] ?? '') === 'newsletter') {
    verify_csrf();
    flash('success', 'Thanks for subscribing! You will receive MediCare Cloud product updates and healthcare operations tips.');
    redirect('index.php#newsletter');
}
if (is_post() && (string)($_POST['form'] ?? '') === 'contact') {
    verify_csrf();
    redirect('contact.php');
}

$pageTitle = 'Hospital Management SaaS — Patients, Doctors, Beds, Pharmacy, Lab & Billing';
$base = '';
include __DIR__ . '/includes/site-header.php';
?>

<!-- ============================ (a) HERO ============================ -->
<section class="hero">
  <div class="container">
    <div class="row align-items-center g-5">
      <div class="col-lg-6">
        <span class="pill"><i class="bi bi-stars"></i> New: Patient portal document sharing is live</span>
        <h1 class="mt-3">Run your entire hospital on <span class="grad">one cloud platform</span></h1>
        <p class="lead fs-5 mt-3">MediCare Cloud brings patients, doctors, appointments, beds, pharmacy, laboratory, billing and subscriptions together in a secure, multi-tenant SaaS — so your team spends less time on paperwork and more time on care.</p>
        <div class="d-flex flex-wrap gap-2 mt-4">
          <a class="btn btn-primary btn-lg" href="auth/register.php"><i class="bi bi-rocket-takeoff me-1"></i> Start Free Trial</a>
          <a class="btn btn-success btn-lg" href="book-appointment.php"><i class="bi bi-calendar-check me-1"></i> Book Appointment</a>
        </div>
        <p class="small text-muted mt-2 mb-0">14-day free trial · No credit card required · Cancel anytime</p>
        <div class="row g-3 mt-4">
          <div class="col-4"><div class="mini-stat"><div class="num">850+</div><div class="small text-muted">Hospitals onboard</div></div></div>
          <div class="col-4"><div class="mini-stat"><div class="num">2.4M</div><div class="small text-muted">Patients managed</div></div></div>
          <div class="col-4"><div class="mini-stat"><div class="num">99.9%</div><div class="small text-muted">Uptime SLA</div></div></div>
        </div>
      </div>
      <div class="col-lg-6">
        <div class="dash-preview">
          <div class="d-flex align-items-center justify-content-between mb-3">
            <strong><i class="bi bi-grid-1x2-fill text-primary me-1"></i> City Care Hospital — Today</strong>
            <span class="badge text-bg-success">Live</span>
          </div>
          <div class="row g-2 mb-3">
            <div class="col-3"><div class="mini-stat"><div class="num">48</div><div class="small text-muted">Appointments</div></div></div>
            <div class="col-3"><div class="mini-stat"><div class="num">312</div><div class="small text-muted">Patients</div></div></div>
            <div class="col-3"><div class="mini-stat"><div class="num">86%</div><div class="small text-muted">Beds filled</div></div></div>
            <div class="col-3"><div class="mini-stat"><div class="num">$4.2k</div><div class="small text-muted">Revenue</div></div></div>
          </div>
          <canvas id="heroChart" height="120" aria-label="Weekly appointments chart"></canvas>
          <div class="mt-3">
            <div class="d-flex justify-content-between align-items-center border rounded-3 px-3 py-2 mb-2">
              <span><span class="avatar-sm me-2">JM</span> John Miller — Dr. Sarah Khan</span>
              <span class="badge text-bg-info">09:30 · Confirmed</span>
            </div>
            <div class="d-flex justify-content-between align-items-center border rounded-3 px-3 py-2 mb-2">
              <span><span class="avatar-sm me-2">FN</span> Fatima Noor — Dr. Laura Kim</span>
              <span class="badge text-bg-warning">10:00 · Scheduled</span>
            </div>
            <div class="d-flex justify-content-between align-items-center border rounded-3 px-3 py-2">
              <span><span class="avatar-sm me-2">ML</span> Mei Ling — Dr. James Patel</span>
              <span class="badge text-bg-warning">11:00 · Scheduled</span>
            </div>
          </div>
        </div>
        <div class="text-center mt-3">
          <img src="assets/img/hero.svg" alt="MediCare Cloud dashboard illustration" class="img-fluid rounded-4 border" style="max-height:180px;width:100%;object-fit:cover">
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ============================ (b) TRUSTED BY ============================ -->
<section class="py-4 border-bottom">
  <div class="container text-center">
    <p class="small text-muted fw-bold text-uppercase mb-3" style="letter-spacing:.1em">Trusted by hospitals &amp; clinics of every size</p>
    <div class="d-flex flex-wrap justify-content-center gap-2 logo-strip">
      <span class="logo-chip"><i class="bi bi-hospital"></i> City Care Hospital</span>
      <span class="logo-chip"><i class="bi bi-heart-pulse"></i> Riverside Medical Center</span>
      <span class="logo-chip"><i class="bi bi-balloon-heart"></i> Sunrise Children Clinic</span>
      <span class="logo-chip"><i class="bi bi-plus-circle"></i> MediPlus</span>
      <span class="logo-chip"><i class="bi bi-clipboard2-pulse"></i> CarePoint</span>
      <span class="logo-chip"><i class="bi bi-shield-plus"></i> HealthFirst</span>
    </div>
  </div>
</section>

<!-- ============================ (c) FEATURES ============================ -->
<section class="section">
  <div class="container">
    <div class="text-center mx-auto mb-5" style="max-width:680px">
      <span class="kicker">Everything in one place</span>
      <h2 class="mt-2">Nine modules. Zero spreadsheets.</h2>
      <p class="text-muted">Every department works from the same live record — no double entry, no lost files, no phone-tag between wards.</p>
    </div>
    <div class="row g-4">
      <?php
      $features = [
          ['bi-people-fill', 'Patient Management', 'Registration, patient codes, medical history, allergies and emergency contacts — searchable in seconds.'],
          ['bi-calendar2-week-fill', 'Doctor Scheduling', 'Rosters, consultation slots and on-call rotations that reception and patients always see in sync.'],
          ['bi-hospital', 'Bed Management', 'A live bed board across wards and ICU — available, occupied, reserved and maintenance at a glance.'],
          ['bi-capsule', 'Pharmacy', 'Medicine stock, reorder alerts, prescriptions and dispensing with full audit trails.'],
          ['bi-droplet-half', 'Laboratory', 'Test catalogues, orders, results and reference ranges delivered straight to the patient record.'],
          ['bi-receipt-cutoff', 'Billing', 'Itemised invoices for consultations, labs, pharmacy and beds — with partial payments supported.'],
          ['bi-person-heart', 'Patient Portal', 'Patients book visits, view results, download bills and read their records from any device.'],
          ['bi-arrow-repeat', 'Subscriptions', 'Plans, trials, coupons and invoices for your hospital — predictable SaaS billing, no surprises.'],
          ['bi-bar-chart-line-fill', 'Reports', 'Revenue, occupancy, doctor utilisation and department performance, exportable anytime.'],
      ];
      foreach ($features as [$icon, $title, $text]): ?>
      <div class="col-md-6 col-lg-4">
        <div class="feature-card">
          <div class="fic"><i class="bi <?= e($icon) ?>"></i></div>
          <h5><?= e($title) ?></h5>
          <p class="text-muted mb-0"><?= e($text) ?></p>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <div class="text-center mt-4"><a class="btn btn-outline-primary" href="features.php">Explore all features <i class="bi bi-arrow-right"></i></a></div>
  </div>
</section>

<!-- ============================ (d) PLATFORM STATS ============================ -->
<section class="section-alt section" style="padding:56px 0">
  <div class="container">
    <div class="row g-4 text-center">
      <div class="col-6 col-lg-3"><div class="num display-6 fw-bold text-primary">850+</div><p class="text-muted mb-0">Hospitals &amp; clinics</p></div>
      <div class="col-6 col-lg-3"><div class="num display-6 fw-bold text-primary">2.4M+</div><p class="text-muted mb-0">Patients managed</p></div>
      <div class="col-6 col-lg-3"><div class="num display-6 fw-bold text-primary">380k</div><p class="text-muted mb-0">Appointments / month</p></div>
      <div class="col-6 col-lg-3"><div class="num display-6 fw-bold text-primary">99.9%</div><p class="text-muted mb-0">Platform uptime</p></div>
    </div>
  </div>
</section>

<!-- ============================ (e) DOCTORS CAROUSEL ============================ -->
<section class="section">
  <div class="container">
    <div class="text-center mx-auto mb-5" style="max-width:640px">
      <span class="kicker">Meet the specialists</span>
      <h2 class="mt-2">Doctors your patients can book in one click</h2>
      <p class="text-muted">Live from City Care Hospital — schedules, fees and specialisations stay current automatically.</p>
    </div>
    <div id="doctorCarousel" class="carousel slide" data-bs-ride="carousel">
      <div class="carousel-inner">
        <?php foreach (array_chunk($doctors, 3) as $i => $chunk): ?>
        <div class="carousel-item<?= $i === 0 ? ' active' : '' ?>">
          <div class="row g-4 px-lg-5">
            <?php foreach ($chunk as $d): ?>
            <div class="col-md-4">
              <div class="doctor-card">
                <div class="doc-photo"><i class="bi bi-person-badge"></i></div>
                <div class="p-4">
                  <h5 class="mb-1"><?= e((string)($d['name'] ?? '')) ?></h5>
                  <p class="text-primary fw-semibold mb-1"><?= e((string)($d['specialization'] ?? '')) ?></p>
                  <p class="small text-muted mb-2"><?= e((string)($d['qualification'] ?? '')) ?> · <?= e((string)($d['dept_name'] ?? '')) ?></p>
                  <p class="small mb-2"><i class="bi bi-clock me-1"></i><?= e((string)($d['schedule'] ?? '')) ?></p>
                  <div class="d-flex justify-content-between align-items-center">
                    <strong><?= e(money($d['consultation_fee'] ?? 0)) ?> <span class="small text-muted fw-normal">/ visit</span></strong>
                    <a class="btn btn-sm btn-primary" href="book-appointment.php">Book</a>
                  </div>
                </div>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <button class="carousel-control-prev" type="button" data-bs-target="#doctorCarousel" data-bs-slide="prev" style="width:3rem"><span class="carousel-control-prev-icon bg-dark rounded-circle" aria-hidden="true"></span><span class="visually-hidden">Previous</span></button>
      <button class="carousel-control-next" type="button" data-bs-target="#doctorCarousel" data-bs-slide="next" style="width:3rem"><span class="carousel-control-next-icon bg-dark rounded-circle" aria-hidden="true"></span><span class="visually-hidden">Next</span></button>
    </div>
    <div class="text-center mt-4"><a class="btn btn-outline-primary" href="doctors.php">View all doctors</a></div>
  </div>
</section>

<!-- ============================ (f) DEPARTMENTS ============================ -->
<section class="section section-alt">
  <div class="container">
    <div class="text-center mx-auto mb-5" style="max-width:640px">
      <span class="kicker">Departments</span>
      <h2 class="mt-2">Specialist care, organised by department</h2>
    </div>
    <div class="row g-4">
      <?php $deptIcons = ['Cardiology' => 'bi-heart-pulse-fill', 'Neurology' => 'bi-diagram-3-fill', 'Pediatrics' => 'bi-balloon-heart-fill', 'Emergency' => 'bi-lightning-charge-fill', 'Orthopedics' => 'bi-bandaid-fill', 'General Surgery' => 'bi-scissors'];
      foreach ($departments as $i => $dep): ?>
      <div class="col-md-6 col-lg-4">
        <div class="dept-card<?= $i % 3 === 1 ? ' g' : ($i % 3 === 2 ? ' d' : '') ?>">
          <div class="fs-2 mb-2"><i class="bi <?= e($deptIcons[(string)($dep['name'] ?? '')] ?? 'bi-hospital') ?>"></i></div>
          <h5 class="mb-1"><?= e((string)($dep['name'] ?? '')) ?></h5>
          <p class="mb-1 text-white-50"><?= e((string)($dep['description'] ?? '')) ?></p>
          <small><i class="bi bi-building me-1"></i><?= e((string)($dep['floor'] ?? '')) ?></small>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <div class="text-center mt-4"><a class="btn btn-outline-primary" href="departments.php">All departments</a></div>
  </div>
</section>

<!-- ============================ (g) BOOKING PREVIEW ============================ -->
<section class="section">
  <div class="container">
    <div class="row align-items-center g-5">
      <div class="col-lg-6">
        <span class="kicker">Online booking</span>
        <h2 class="mt-2">Appointments booked in under a minute</h2>
        <p class="text-muted">Patients pick a hospital, doctor and time — the platform checks the live schedule, reserves the slot and notifies reception instantly.</p>
        <ol class="list-unstyled mt-4">
          <li class="d-flex gap-3 mb-3"><span class="avatar-initials">1</span><div><strong>Choose hospital &amp; doctor</strong><br><span class="text-muted small">Filter by department, specialisation and fee.</span></div></li>
          <li class="d-flex gap-3 mb-3"><span class="avatar-initials">2</span><div><strong>Pick a date &amp; time</strong><br><span class="text-muted small">Only real, available slots are shown.</span></div></li>
          <li class="d-flex gap-3"><span class="avatar-initials">3</span><div><strong>Get confirmed instantly</strong><br><span class="text-muted small">A patient record is created automatically — no forms at the desk.</span></div></li>
        </ol>
        <a class="btn btn-success btn-lg mt-3" href="book-appointment.php"><i class="bi bi-calendar-check me-1"></i> Book an Appointment</a>
      </div>
      <div class="col-lg-6">
        <div class="dash-preview">
          <strong class="d-block mb-3"><i class="bi bi-calendar2-check text-success me-1"></i> Book Appointment</strong>
          <label class="form-label small fw-semibold">Doctor</label>
          <div class="form-control mb-3 bg-light">Dr. Sarah Khan — Cardiology · $80.00</div>
          <div class="row g-2 mb-3">
            <div class="col-6"><label class="form-label small fw-semibold">Date</label><div class="form-control bg-light"><?= e(date('d M Y', strtotime('+1 day'))) ?></div></div>
            <div class="col-6"><label class="form-label small fw-semibold">Time</label><div class="form-control bg-light">09:30 AM</div></div>
          </div>
          <label class="form-label small fw-semibold">Reason</label>
          <div class="form-control mb-3 bg-light">Follow-up for blood pressure management</div>
          <div class="alert alert-success py-2 mb-0 small"><i class="bi bi-check-circle-fill me-1"></i> Slot available — confirmed in real time.</div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ============================ (h) BED MANAGEMENT ============================ -->
<section class="section section-alt">
  <div class="container">
    <div class="row align-items-center g-5">
      <div class="col-lg-6 order-lg-2">
        <span class="kicker">Bed management</span>
        <h2 class="mt-2">A live bed board for every ward</h2>
        <p class="text-muted">Admissions, transfers and discharges update the board the moment they happen — no more walking the ward to count empty beds.</p>
        <ul class="list-unstyled tick">
          <li><i class="bi bi-check-circle-fill text-success me-2"></i>General, ICU, CCU, pediatric, maternity &amp; private wards</li>
          <li><i class="bi bi-check-circle-fill text-success me-2"></i>Maintenance flags keep broken beds out of rotation</li>
          <li><i class="bi bi-check-circle-fill text-success me-2"></i>Occupancy feeds straight into billing and reports</li>
        </ul>
      </div>
      <div class="col-lg-6 order-lg-1">
        <div class="dash-preview">
          <strong class="d-block mb-3"><i class="bi bi-hospital text-primary me-1"></i> General Ward A — Bed Board</strong>
          <div class="bed-grid">
            <div class="bed-tile bed-occupied"><i class="bi bi-person-fill"></i>A-01<small class="d-block text-muted">Occupied</small></div>
            <div class="bed-tile bed-occupied"><i class="bi bi-person-fill"></i>A-02<small class="d-block text-muted">Occupied</small></div>
            <div class="bed-tile bed-available"><i class="bi bi-check-circle"></i>A-03<small class="d-block text-muted">Available</small></div>
            <div class="bed-tile bed-available"><i class="bi bi-check-circle"></i>A-04<small class="d-block text-muted">Available</small></div>
            <div class="bed-tile bed-reserved"><i class="bi bi-bookmark"></i>ICU-03<small class="d-block text-muted">Reserved</small></div>
            <div class="bed-tile bed-available"><i class="bi bi-check-circle"></i>P-01<small class="d-block text-muted">Available</small></div>
            <div class="bed-tile bed-occupied"><i class="bi bi-person-fill"></i>P-02<small class="d-block text-muted">Occupied</small></div>
            <div class="bed-tile bed-available"><i class="bi bi-check-circle"></i>P-03<small class="d-block text-muted">Available</small></div>
          </div>
          <div class="d-flex gap-3 mt-3 small text-muted">
            <span><span class="badge text-bg-success">●</span> Available</span>
            <span><span class="badge text-bg-danger">●</span> Occupied</span>
            <span><span class="badge text-bg-warning">●</span> Reserved</span>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ============================ (i) PATIENT MANAGEMENT ============================ -->
<section class="section">
  <div class="container">
    <div class="row align-items-center g-5">
      <div class="col-lg-6">
        <span class="kicker">Patient management</span>
        <h2 class="mt-2">One record, from registration to discharge</h2>
        <p class="text-muted">Every patient gets a permanent code (PAT-0001, PAT-0002…) and a single timeline: visits, prescriptions, lab results, admissions and bills.</p>
        <ul class="list-unstyled tick">
          <li><i class="bi bi-check-circle-fill text-success me-2"></i>Allergies &amp; blood group surfaced on every screen</li>
          <li><i class="bi bi-check-circle-fill text-success me-2"></i>Role-based access — patients only ever see their own data</li>
          <li><i class="bi bi-check-circle-fill text-success me-2"></i>Full audit trail on every record view and change</li>
        </ul>
        <a class="btn btn-outline-primary mt-2" href="features.php">See how it works</a>
      </div>
      <div class="col-lg-6">
        <div class="dash-preview">
          <div class="d-flex align-items-center gap-3 mb-3">
            <span class="avatar-initials" style="width:52px;height:52px;font-size:1.1rem">JM</span>
            <div><strong>John Miller</strong> <span class="badge text-bg-primary ms-1">PAT-0001</span><br><span class="small text-muted">Male · O+ · Allergic to Penicillin</span></div>
          </div>
          <div class="list-group list-group-flush small">
            <div class="list-group-item d-flex justify-content-between px-0"><span><i class="bi bi-calendar-check text-primary me-2"></i>Cardiology follow-up — Dr. Sarah Khan</span><span class="text-muted">Today</span></div>
            <div class="list-group-item d-flex justify-content-between px-0"><span><i class="bi bi-droplet text-primary me-2"></i>Lipid profile — 186 mg/dL</span><span class="badge text-bg-success">Completed</span></div>
            <div class="list-group-item d-flex justify-content-between px-0"><span><i class="bi bi-capsule text-primary me-2"></i>Atorvastatin 20mg — once daily</span><span class="badge text-bg-warning">Pending</span></div>
            <div class="list-group-item d-flex justify-content-between px-0"><span><i class="bi bi-receipt text-primary me-2"></i>Invoice BILL-0001 — $115.00</span><span class="badge text-bg-success">Paid</span></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ============================ (j) ANALYTICS ============================ -->
<section class="section section-alt">
  <div class="container">
    <div class="row align-items-center g-5">
      <div class="col-lg-5">
        <span class="kicker">Reports &amp; analytics</span>
        <h2 class="mt-2">Decisions backed by live numbers</h2>
        <p class="text-muted">Appointment volume, revenue, bed occupancy and doctor utilisation — updated in real time and exportable for your board, your accountant or your regulator.</p>
        <div class="row g-3 mt-2">
          <div class="col-6"><div class="mini-stat bg-white"><div class="num text-success">+18%</div><div class="small text-muted">Revenue this quarter</div></div></div>
          <div class="col-6"><div class="mini-stat bg-white"><div class="num text-primary">-32%</div><div class="small text-muted">No-show rate</div></div></div>
        </div>
      </div>
      <div class="col-lg-7">
        <div class="dash-preview">
          <strong class="d-block mb-3"><i class="bi bi-bar-chart-line text-primary me-1"></i> Appointments vs Revenue — last 6 months</strong>
          <canvas id="analyticsChart" height="160" aria-label="Analytics chart"></canvas>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ============================ (k) TESTIMONIALS ============================ -->
<section class="section">
  <div class="container">
    <div class="text-center mx-auto mb-5" style="max-width:640px">
      <span class="kicker">Loved by care teams</span>
      <h2 class="mt-2">What hospital teams say about MediCare Cloud</h2>
    </div>
    <div class="row g-4">
      <div class="col-md-4">
        <div class="testi">
          <div class="stars mb-2">★★★★★</div>
          <p>“We replaced four spreadsheets and a paper bed register in one weekend. Reception now books, bills and discharges from a single screen — queues at our front desk dropped by half.”</p>
          <div class="d-flex align-items-center gap-2 mt-3"><span class="avatar-initials">AR</span><div><strong>Ayesha Rahman</strong><br><small class="text-muted">Hospital Administrator, City Care Hospital</small></div></div>
        </div>
      </div>
      <div class="col-md-4">
        <div class="testi">
          <div class="stars mb-2">★★★★★</div>
          <p>“Lab results land on the patient record before the patient even reaches my room. The patient portal alone has cut our follow-up phone calls dramatically.”</p>
          <div class="d-flex align-items-center gap-2 mt-3"><span class="avatar-initials">SK</span><div><strong>Dr. Sarah Khan</strong><br><small class="text-muted">Interventional Cardiologist</small></div></div>
        </div>
      </div>
      <div class="col-md-4">
        <div class="testi">
          <div class="stars mb-2">★★★★★</div>
          <p>“As a multi-branch group, tenant isolation was non-negotiable. Each hospital sees only its own data, and head office still gets one consolidated report.”</p>
          <div class="d-flex align-items-center gap-2 mt-3"><span class="avatar-initials">DO</span><div><strong>David Osei</strong><br><small class="text-muted">Finance Lead, Riverside Medical Center</small></div></div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ============================ (l) PRICING ============================ -->
<section class="section section-alt" id="pricing">
  <div class="container">
    <div class="text-center mx-auto mb-4" style="max-width:640px">
      <span class="kicker">Pricing</span>
      <h2 class="mt-2">Simple pricing that grows with your hospital</h2>
      <p class="text-muted">Start free for 14 days. Upgrade, downgrade or cancel anytime.</p>
    </div>
    <div class="d-flex justify-content-center align-items-center gap-3 mb-5">
      <span class="fw-semibold">Monthly</span>
      <div class="form-check form-switch m-0"><input class="form-check-input" type="checkbox" id="billingToggle" style="width:3rem;height:1.5rem" aria-label="Toggle yearly billing"></div>
      <span class="fw-semibold">Yearly <span class="badge text-bg-success">Save ~20%</span></span>
    </div>
    <div class="row g-4 justify-content-center">
      <?php foreach ($plans as $p):
          $feats = json_decode((string)($p['features'] ?? '[]'), true);
          if (!is_array($feats)) { $feats = array_filter(array_map('trim', explode(',', (string)($p['features'] ?? '')))); }
          $monthly = (float)($p['monthly_price'] ?? 0);
          $yearlyPerMonth = $monthly > 0 ? (float)($p['yearly_price'] ?? 0) / 12 : 0;
          $popular = ((string)($p['slug'] ?? '') === 'professional');
      ?>
      <div class="col-md-6 col-lg-4 col-xl">
        <div class="price-card<?= $popular ? ' popular' : '' ?>">
          <?php if ($popular): ?><span class="badge text-bg-primary position-absolute top-0 start-50 translate-middle">Most Popular</span><?php endif; ?>
          <h5><?= e((string)($p['name'] ?? '')) ?></h5>
          <div class="amount" data-monthly="<?= e(money($monthly)) ?>" data-yearly="<?= e(money($yearlyPerMonth)) ?>"><?= e(money($monthly)) ?></div>
          <p class="bill-note text-muted small">per month, billed monthly</p>
          <ul class="list-unstyled tick small">
            <?php foreach ($feats as $f): ?><li><i class="bi bi-check-circle-fill text-success me-2"></i><?= e((string)$f) ?></li><?php endforeach; ?>
          </ul>
          <a class="btn <?= $popular ? 'btn-primary' : 'btn-outline-primary' ?> w-100 mt-2" href="auth/register.php">Start Free Trial</a>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <p class="text-center mt-4 mb-0"><a href="pricing.php">Compare all plans in detail <i class="bi bi-arrow-right"></i></a></p>
  </div>
</section>

<!-- ============================ (m) FAQ ============================ -->
<section class="section">
  <div class="container" style="max-width:860px">
    <div class="text-center mb-5">
      <span class="kicker">FAQ</span>
      <h2 class="mt-2">Frequently asked questions</h2>
    </div>
    <div class="accordion" id="faqAcc">
      <?php
      $faqs = [
          ['How does the free trial work?', 'Every new hospital gets a full-featured 14-day trial (30 days on Enterprise) with demo data pre-loaded. No credit card is required, and your data carries over when you subscribe.'],
          ['Is our patient data isolated from other hospitals?', 'Yes. MediCare Cloud is multi-tenant by design: every record carries your hospital\'s tenant ID and the application enforces it on every query. One hospital can never see another hospital\'s patients, doctors or bills.'],
          ['Can patients book appointments online?', 'Yes — the public booking page lets patients choose a hospital, doctor, date and time. A patient record is created automatically and the appointment appears instantly on the reception schedule.'],
          ['Does it support pharmacy and laboratory workflows?', 'Yes. Pharmacists manage stock and dispense prescriptions, and lab technicians record results against reference ranges — both flow directly into the patient record and the bill.'],
          ['What happens if we exceed our plan limits?', 'You will see a clear notice when a limit (doctors, staff, patients or departments) is reached. Existing data is never locked or deleted — you simply upgrade when you are ready.'],
          ['Can we cancel anytime?', 'Yes. Subscriptions can be cancelled from the billing screen. Your hospital keeps read access through the grace period, and you can export your data before you leave.'],
      ];
      foreach ($faqs as $i => [$q, $a]): ?>
      <div class="accordion-item">
        <h3 class="accordion-header"><button class="accordion-button<?= $i === 0 ? '' : ' collapsed' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#faq<?= $i ?>" aria-expanded="<?= $i === 0 ? 'true' : 'false' ?>"><?= e($q) ?></button></h3>
        <div id="faq<?= $i ?>" class="accordion-collapse collapse<?= $i === 0 ? ' show' : '' ?>" data-bs-parent="#faqAcc">
          <div class="accordion-body text-muted"><?= e($a) ?></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ============================ (n) BLOG ============================ -->
<section class="section section-alt">
  <div class="container">
    <div class="text-center mx-auto mb-5" style="max-width:640px">
      <span class="kicker">From the blog</span>
      <h2 class="mt-2">Ideas for running a modern hospital</h2>
    </div>
    <div class="row g-4">
      <div class="col-md-4">
        <article class="blog-card">
          <div class="thumb"><i class="bi bi-calendar2-heart"></i></div>
          <div class="p-4">
            <span class="badge text-bg-primary mb-2">Operations</span>
            <h5>Cutting patient no-shows by a third with smarter scheduling</h5>
            <p class="text-muted small">Reminders, realistic slot lengths and a patient portal — the three changes that moved the needle for our hospitals.</p>
            <a href="blog.php">Read more <i class="bi bi-arrow-right"></i></a>
          </div>
        </article>
      </div>
      <div class="col-md-4">
        <article class="blog-card">
          <div class="thumb"><i class="bi bi-shield-lock"></i></div>
          <div class="p-4">
            <span class="badge text-bg-success mb-2">Security</span>
            <h5>Tenant isolation explained: how multi-hospital SaaS stays private</h5>
            <p class="text-muted small">Why every query is tenant-scoped, what audit logs prove, and the questions to ask any hospital software vendor.</p>
            <a href="blog.php">Read more <i class="bi bi-arrow-right"></i></a>
          </div>
        </article>
      </div>
      <div class="col-md-4">
        <article class="blog-card">
          <div class="thumb"><i class="bi bi-hospital"></i></div>
          <div class="p-4">
            <span class="badge text-bg-warning mb-2">Bed Management</span>
            <h5>The live bed board: ending the “walk the ward” ritual</h5>
            <p class="text-muted small">How real-time bed states shorten admission waits and stop double-booked ICU beds for good.</p>
            <a href="blog.php">Read more <i class="bi bi-arrow-right"></i></a>
          </div>
        </article>
      </div>
    </div>
  </div>
</section>

<!-- ============================ (o) CONTACT ============================ -->
<section class="section">
  <div class="container">
    <div class="row g-5">
      <div class="col-lg-5">
        <span class="kicker">Talk to us</span>
        <h2 class="mt-2">Questions? Book a guided demo</h2>
        <p class="text-muted">Tell us about your hospital and we will walk you through the modules that matter most — usually within one business day.</p>
        <ul class="list-unstyled">
          <li class="mb-2"><i class="bi bi-envelope-fill text-primary me-2"></i> support@medicare.test</li>
          <li class="mb-2"><i class="bi bi-telephone-fill text-primary me-2"></i> +1 555 000 1111</li>
          <li><i class="bi bi-geo-alt-fill text-primary me-2"></i> MediCare Cloud HQ, Health Tech District</li>
        </ul>
      </div>
      <div class="col-lg-7">
        <form method="post" action="contact.php" class="card p-4">
          <?= csrf_field() ?>
          <input type="hidden" name="form" value="contact">
          <div class="row g-3">
            <div class="col-md-6"><label class="form-label fw-semibold" for="cName">Your name</label><input class="form-control" id="cName" name="name" required></div>
            <div class="col-md-6"><label class="form-label fw-semibold" for="cEmail">Email</label><input class="form-control" id="cEmail" type="email" name="email" required></div>
            <div class="col-12"><label class="form-label fw-semibold" for="cSubject">Subject</label><input class="form-control" id="cSubject" name="subject" placeholder="e.g. Demo for a 120-bed hospital" required></div>
            <div class="col-12"><label class="form-label fw-semibold" for="cMsg">Message</label><textarea class="form-control" id="cMsg" name="message" rows="4" required></textarea></div>
            <div class="col-12"><button class="btn btn-primary" type="submit"><i class="bi bi-send me-1"></i> Send Message</button></div>
          </div>
        </form>
      </div>
    </div>
  </div>
</section>

<!-- ============================ (p) NEWSLETTER ============================ -->
<section class="pb-5" id="newsletter">
  <div class="container">
    <div class="cta-band" style="background:linear-gradient(120deg,#0f172a,#1e3a5f)">
      <div class="row align-items-center g-4">
        <div class="col-lg-6">
          <h3 class="mb-1">Hospital operations, in your inbox</h3>
          <p class="text-white-50 mb-0">One practical email a month — workflow tips, product updates and customer stories. No spam, ever.</p>
        </div>
        <div class="col-lg-6">
          <form method="post" action="index.php#newsletter" class="d-flex gap-2">
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="newsletter">
            <input class="form-control form-control-lg" type="email" name="email" placeholder="you@hospital.org" required aria-label="Email address">
            <button class="btn btn-success btn-lg flex-none" type="submit">Subscribe</button>
          </form>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ============================ (q) CTA BAND ============================ -->
<section class="pb-5">
  <div class="container">
    <div class="cta-band text-center">
      <h2 class="mb-2">Give your hospital a single source of truth</h2>
      <p class="text-white-50 mx-auto mb-4" style="max-width:560px">Join 850+ hospitals running patients, doctors, beds, pharmacy, lab and billing on MediCare Cloud.</p>
      <div class="d-flex justify-content-center flex-wrap gap-2">
        <a class="btn btn-light btn-lg" href="auth/register.php">Start Free Trial</a>
        <a class="btn btn-outline-light btn-lg" href="pricing.php">See Pricing</a>
      </div>
    </div>
  </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', function () {
  if (typeof Chart === 'undefined') return;
  var hero = document.getElementById('heroChart');
  if (hero) {
    new Chart(hero, {
      data: {
        labels: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
        datasets: [
          { type: 'bar', label: 'Appointments', data: [32, 41, 38, 47, 52, 29, 18], backgroundColor: 'rgba(37,99,235,.55)', borderRadius: 6 },
          { type: 'line', label: 'Completed', data: [28, 36, 35, 43, 48, 27, 17], borderColor: '#10b981', backgroundColor: '#10b981', tension: .35 }
        ]
      },
      options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, grid: { color: '#eef2f7' } }, x: { grid: { display: false } } } }
    });
  }
  var an = document.getElementById('analyticsChart');
  if (an) {
    new Chart(an, {
      data: {
        labels: ['Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov'],
        datasets: [
          { type: 'bar', label: 'Appointments', data: [820, 940, 1010, 1120, 1240, 1380], backgroundColor: 'rgba(16,185,129,.5)', borderRadius: 6, yAxisID: 'y' },
          { type: 'line', label: 'Revenue ($k)', data: [42, 48, 51, 57, 63, 71], borderColor: '#2563eb', backgroundColor: '#2563eb', tension: .35, yAxisID: 'y1' }
        ]
      },
      options: { plugins: { legend: { position: 'bottom' } }, scales: { y: { beginAtZero: true, grid: { color: '#eef2f7' } }, y1: { beginAtZero: true, position: 'right', grid: { display: false } }, x: { grid: { display: false } } } }
    });
  }
});
</script>

<?php include __DIR__ . '/includes/site-footer.php'; ?>
