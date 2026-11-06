<?php
declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';

$fallback = [
    ['id' => 1, 'name' => 'Dr. Sarah Khan', 'specialization' => 'Interventional Cardiology', 'qualification' => 'MBBS, MD Cardiology', 'phone' => '+1 555 010 9002', 'email' => 'doctor@demo.com', 'consultation_fee' => 80, 'schedule' => 'Mon–Fri · 9:00 AM – 2:00 PM', 'dept_name' => 'Cardiology'],
    ['id' => 2, 'name' => 'Dr. James Patel', 'specialization' => 'Neurology', 'qualification' => 'MBBS, DM Neurology', 'phone' => '+1 555 011 2201', 'email' => 'j.patel@citycare.example', 'consultation_fee' => 90, 'schedule' => 'Mon, Wed, Fri · 10:00 AM – 4:00 PM', 'dept_name' => 'Neurology'],
    ['id' => 3, 'name' => 'Dr. Laura Kim', 'specialization' => 'Pediatrics', 'qualification' => 'MBBS, MD Pediatrics', 'phone' => '+1 555 011 2202', 'email' => 'l.kim@citycare.example', 'consultation_fee' => 60, 'schedule' => 'Mon–Sat · 9:00 AM – 1:00 PM', 'dept_name' => 'Pediatrics'],
    ['id' => 4, 'name' => 'Dr. Omar Farooq', 'specialization' => 'Emergency Medicine', 'qualification' => 'MBBS, FCPS', 'phone' => '+1 555 011 2203', 'email' => 'o.farooq@citycare.example', 'consultation_fee' => 100, 'schedule' => '24/7 on-call rotation', 'dept_name' => 'Emergency'],
    ['id' => 5, 'name' => 'Dr. Nina Rodriguez', 'specialization' => 'Orthopedic Surgery', 'qualification' => 'MBBS, MS Orthopedics', 'phone' => '+1 555 011 2204', 'email' => 'n.rodriguez@citycare.example', 'consultation_fee' => 85, 'schedule' => 'Tue, Thu, Sat · 11:00 AM – 5:00 PM', 'dept_name' => 'Orthopedics'],
    ['id' => 6, 'name' => 'Dr. Peter Novak', 'specialization' => 'General Surgery', 'qualification' => 'MBBS, MS Surgery', 'phone' => '+1 555 011 2205', 'email' => 'p.novak@citycare.example', 'consultation_fee' => 95, 'schedule' => 'Mon–Thu · 8:00 AM – 12:00 PM', 'dept_name' => 'General Surgery'],
];
$doctors = $fallback;
try {
    require_once __DIR__ . '/config/database.php';
    $rows = db()->query('SELECT d.id, d.name, d.specialization, d.qualification, d.phone, d.email, d.consultation_fee, d.schedule, dep.name AS dept_name FROM doctors d LEFT JOIN departments dep ON dep.id = d.department_id WHERE d.tenant_id = 1 AND d.status = "active" ORDER BY d.id ASC')->fetchAll();
    if ($rows) { $doctors = $rows; }
} catch (Throwable $ignored) {
}

$pageTitle = 'Doctors';
$base = '';
include __DIR__ . '/includes/site-header.php';
?>
<section class="page-hero">
  <div class="container">
    <span class="kicker">Our Doctors</span>
    <h1 class="display-5 fw-bold mt-2">Meet the doctors at <span class="text-primary">City Care Hospital</span></h1>
    <p class="lead text-muted" style="max-width:700px">Specialisations, consultation fees and live schedules — book directly with the doctor of your choice.</p>
  </div>
</section>

<section class="section">
  <div class="container">
    <div class="row g-4">
      <?php foreach ($doctors as $d): ?>
      <div class="col-md-6 col-lg-4">
        <div class="doctor-card">
          <div class="doc-photo"><i class="bi bi-person-badge"></i></div>
          <div class="p-4">
            <div class="d-flex justify-content-between align-items-start">
              <h5 class="mb-1"><?= e((string)($d['name'] ?? '')) ?></h5>
              <?php if (!empty($d['dept_name'])): ?><span class="badge text-bg-primary"><?= e((string)$d['dept_name']) ?></span><?php endif; ?>
            </div>
            <p class="text-primary fw-semibold mb-1"><?= e((string)($d['specialization'] ?? '')) ?></p>
            <p class="small text-muted mb-3"><?= e((string)($d['qualification'] ?? '')) ?></p>
            <ul class="list-unstyled small mb-3">
              <li class="mb-1"><i class="bi bi-clock text-primary me-2"></i><?= e((string)($d['schedule'] ?? '')) ?></li>
              <?php if (!empty($d['phone'])): ?><li class="mb-1"><i class="bi bi-telephone text-primary me-2"></i><?= e((string)$d['phone']) ?></li><?php endif; ?>
              <?php if (!empty($d['email'])): ?><li><i class="bi bi-envelope text-primary me-2"></i><?= e((string)$d['email']) ?></li><?php endif; ?>
            </ul>
            <div class="d-flex justify-content-between align-items-center border-top pt-3">
              <strong class="fs-5"><?= e(money($d['consultation_fee'] ?? 0)) ?><span class="small text-muted fw-normal"> / visit</span></strong>
              <a class="btn btn-primary" href="book-appointment.php?doctor=<?= e((string)($d['id'] ?? '')) ?>">Book Visit</a>
            </div>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="pb-5">
  <div class="container">
    <div class="cta-band text-center">
      <h2 class="mb-2">Run a hospital? Put your doctors online like this</h2>
      <p class="text-white-50 mx-auto mb-4" style="max-width:540px">MediCare Cloud publishes your live doctor directory and booking automatically from your roster.</p>
      <a class="btn btn-light btn-lg" href="auth/register.php">Start Free Trial</a>
    </div>
  </div>
</section>
<?php include __DIR__ . '/includes/site-footer.php'; ?>
