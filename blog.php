<?php
declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';
$pageTitle = 'Blog';
$base = '';
include __DIR__ . '/includes/site-header.php';

$posts = [
    ['icon' => 'bi-calendar2-heart', 'cat' => 'Operations', 'date' => '28 Oct 2026', 'read' => '6 min read', 'title' => 'Cutting patient no-shows by a third with smarter scheduling', 'text' => 'Reminders, realistic slot lengths and a patient portal — the three operational changes that moved the needle most for hospitals on MediCare Cloud, and the data behind each one.'],
    ['icon' => 'bi-shield-lock', 'cat' => 'Security', 'date' => '21 Oct 2026', 'read' => '8 min read', 'title' => 'Tenant isolation explained: how multi-hospital SaaS stays private', 'text' => 'Why every query is tenant-scoped, what audit logs actually prove, and the five privacy questions every hospital should ask a software vendor before signing.'],
    ['icon' => 'bi-hospital', 'cat' => 'Bed Management', 'date' => '14 Oct 2026', 'read' => '5 min read', 'title' => 'The live bed board: ending the “walk the ward” ritual', 'text' => 'How real-time bed states — available, occupied, reserved, maintenance — shorten admission waits and put an end to double-booked ICU beds.'],
    ['icon' => 'bi-capsule', 'cat' => 'Pharmacy', 'date' => '7 Oct 2026', 'read' => '5 min read', 'title' => 'Never run out of insulin again: pharmacy reorder alerts that work', 'text' => 'Stock levels, reorder points and expiry tracking sound boring — until a critical medicine runs out mid-shift. Here is the setup our pharmacies swear by.'],
    ['icon' => 'bi-droplet-half', 'cat' => 'Laboratory', 'date' => '30 Sep 2026', 'read' => '7 min read', 'title' => 'From sample to result in the record: closing the lab loop', 'text' => 'When lab results land directly on the patient record, doctors stop phoning the lab and patients stop waiting. A look at the workflow, end to end.'],
    ['icon' => 'bi-receipt-cutoff', 'cat' => 'Billing', 'date' => '23 Sep 2026', 'read' => '6 min read', 'title' => 'Itemised hospital billing patients actually understand', 'text' => 'Consultation, lab, pharmacy, bed — one transparent invoice. Hospitals that itemise clearly collect faster and dispute less. Here is how to structure yours.'],
    ['icon' => 'bi-graph-up-arrow', 'cat' => 'Growth', 'date' => '16 Sep 2026', 'read' => '9 min read', 'title' => 'The clinic owner’s guide to choosing hospital software in 2026', 'text' => 'Modules, pricing models, data ownership, migration and the hidden cost of “free” systems — a practical checklist drawn from 850+ hospital onboardings.'],
];
$featured = array_shift($posts);
?>
<section class="page-hero">
  <div class="container">
    <span class="kicker">Blog</span>
    <h1 class="display-5 fw-bold mt-2">Ideas for running a <span class="text-primary">modern hospital</span></h1>
    <p class="lead text-muted" style="max-width:680px">Operations, security, billing and patient experience — practical writing from the MediCare Cloud team and the hospitals we work with.</p>
  </div>
</section>

<section class="section" style="padding-top:36px">
  <div class="container">
    <article class="card overflow-hidden mb-5">
      <div class="row g-0">
        <div class="col-lg-5 d-flex align-items-center justify-content-center text-white" style="background:linear-gradient(135deg,#1d4ed8,#0d9488);min-height:260px">
          <i class="bi <?= e($featured['icon']) ?>" style="font-size:6rem"></i>
        </div>
        <div class="col-lg-7 p-4 p-lg-5">
          <span class="badge text-bg-warning mb-2">Featured</span>
          <span class="badge text-bg-primary mb-2"><?= e($featured['cat']) ?></span>
          <h2><?= e($featured['title']) ?></h2>
          <p class="text-muted"><?= e($featured['text']) ?></p>
          <p class="small text-muted mb-3"><i class="bi bi-calendar3 me-1"></i><?= e($featured['date']) ?> · <i class="bi bi-clock me-1"></i><?= e($featured['read']) ?></p>
          <a class="btn btn-primary" href="contact.php">Discuss this topic with us</a>
        </div>
      </div>
    </article>

    <div class="row g-4">
      <?php foreach ($posts as $p): ?>
      <div class="col-md-6 col-lg-4">
        <article class="blog-card">
          <div class="thumb"><i class="bi <?= e($p['icon']) ?>"></i></div>
          <div class="p-4">
            <span class="badge text-bg-primary mb-2"><?= e($p['cat']) ?></span>
            <h5><?= e($p['title']) ?></h5>
            <p class="text-muted small"><?= e($p['text']) ?></p>
            <p class="small text-muted mb-2"><i class="bi bi-calendar3 me-1"></i><?= e($p['date']) ?> · <?= e($p['read']) ?></p>
            <a href="contact.php">Read more <i class="bi bi-arrow-right"></i></a>
          </div>
        </article>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="pb-5">
  <div class="container">
    <div class="cta-band text-center">
      <h2 class="mb-2">Put these ideas into practice</h2>
      <p class="text-white-50 mx-auto mb-4" style="max-width:540px">Start a free trial and run your hospital the way our best-performing customers do.</p>
      <a class="btn btn-light btn-lg" href="auth/register.php">Start Free Trial</a>
    </div>
  </div>
</section>
<?php include __DIR__ . '/includes/site-footer.php'; ?>
