<?php
declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';

$fallback = [
    ['name' => 'Cardiology', 'description' => 'Heart and cardiovascular care', 'floor' => '2nd Floor', 'phone' => 'ext. 201', 'head_name' => 'Dr. Sarah Khan'],
    ['name' => 'Neurology', 'description' => 'Brain, spine and nervous system', 'floor' => '3rd Floor', 'phone' => 'ext. 301', 'head_name' => 'Dr. James Patel'],
    ['name' => 'Pediatrics', 'description' => 'Child and adolescent healthcare', 'floor' => '1st Floor', 'phone' => 'ext. 101', 'head_name' => 'Dr. Laura Kim'],
    ['name' => 'Emergency', 'description' => '24/7 emergency and trauma care', 'floor' => 'Ground Floor', 'phone' => 'ext. 911', 'head_name' => 'Dr. Omar Farooq'],
    ['name' => 'Orthopedics', 'description' => 'Bones, joints and sports injuries', 'floor' => '2nd Floor', 'phone' => 'ext. 210', 'head_name' => 'Dr. Nina Rodriguez'],
    ['name' => 'General Surgery', 'description' => 'Surgical procedures and recovery', 'floor' => '4th Floor', 'phone' => 'ext. 401', 'head_name' => 'Dr. Peter Novak'],
];
$departments = $fallback;
try {
    require_once __DIR__ . '/config/database.php';
    $rows = db()->query('SELECT d.name, d.description, d.floor, d.phone, doc.name AS head_name FROM departments d LEFT JOIN doctors doc ON doc.id = d.head_doctor_id WHERE d.tenant_id = 1 AND d.status = "active" ORDER BY d.id ASC')->fetchAll();
    if ($rows) { $departments = $rows; }
} catch (Throwable $ignored) {
}

$details = [
    'Cardiology' => ['icon' => 'bi-heart-pulse-fill', 'services' => 'ECG, echocardiography, stress testing, hypertension & heart-failure clinics, interventional procedures.'],
    'Neurology' => ['icon' => 'bi-diagram-3-fill', 'services' => 'Stroke care, epilepsy, migraine and movement-disorder clinics, nerve conduction studies.'],
    'Pediatrics' => ['icon' => 'bi-balloon-heart-fill', 'services' => 'Well-child visits, immunisations, developmental screening and adolescent health.'],
    'Emergency' => ['icon' => 'bi-lightning-charge-fill', 'services' => '24/7 triage, trauma resuscitation, acute medical and surgical emergencies — walk-ins always welcome.'],
    'Orthopedics' => ['icon' => 'bi-bandaid-fill', 'services' => 'Fracture care, joint replacement, sports injuries, arthroscopy and physiotherapy referrals.'],
    'General Surgery' => ['icon' => 'bi-scissors', 'services' => 'Appendectomy, hernia repair, gallbladder surgery and post-operative recovery wards.'],
];

$pageTitle = 'Departments';
$base = '';
include __DIR__ . '/includes/site-header.php';
?>
<section class="page-hero">
  <div class="container">
    <span class="kicker">Departments</span>
    <h1 class="display-5 fw-bold mt-2">Departments at <span class="text-primary">City Care Hospital</span></h1>
    <p class="lead text-muted" style="max-width:700px">Six specialist departments, one shared patient record. Every department below runs on MediCare Cloud — appointments, labs, pharmacy and billing stay in sync across all of them.</p>
  </div>
</section>

<section class="section">
  <div class="container">
    <div class="row g-4">
      <?php foreach ($departments as $i => $d):
          $name = (string)($d['name'] ?? '');
          $meta = $details[$name] ?? ['icon' => 'bi-hospital', 'services' => 'Consultations, diagnostics and follow-up care.'];
      ?>
      <div class="col-md-6 col-lg-4">
        <div class="dept-card<?= $i % 3 === 1 ? ' g' : ($i % 3 === 2 ? ' d' : '') ?> mb-3">
          <div class="fs-2 mb-2"><i class="bi <?= e($meta['icon']) ?>"></i></div>
          <h5 class="mb-1"><?= e($name) ?></h5>
          <p class="mb-0 text-white-50"><?= e((string)($d['description'] ?? '')) ?></p>
        </div>
        <div class="feature-card">
          <p class="text-muted small"><?= e($meta['services']) ?></p>
          <ul class="list-unstyled small mb-3">
            <li class="mb-1"><i class="bi bi-building text-primary me-2"></i><strong>Location:</strong> <?= e((string)($d['floor'] ?? '—')) ?></li>
            <li class="mb-1"><i class="bi bi-telephone text-primary me-2"></i><strong>Phone:</strong> <?= e((string)($d['phone'] ?? '—')) ?></li>
            <?php if (!empty($d['head_name'])): ?><li><i class="bi bi-person-badge text-primary me-2"></i><strong>Head:</strong> <?= e((string)$d['head_name']) ?></li><?php endif; ?>
          </ul>
          <a class="btn btn-sm btn-outline-primary" href="book-appointment.php">Book in this department</a>
          <a class="btn btn-sm btn-link" href="doctors.php">Meet the doctors</a>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="pb-5">
  <div class="container">
    <div class="cta-band text-center">
      <h2 class="mb-2">Not sure which department you need?</h2>
      <p class="text-white-50 mx-auto mb-4" style="max-width:540px">Book a general appointment and our team will route you to the right specialist.</p>
      <a class="btn btn-light btn-lg" href="book-appointment.php">Book Appointment</a>
    </div>
  </div>
</section>
<?php include __DIR__ . '/includes/site-footer.php'; ?>
