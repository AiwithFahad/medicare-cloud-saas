<?php
declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';

$errors = [];
$sent = false;
if (is_post()) {
    verify_csrf();
    $name = trim((string)($_POST['name'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $subject = trim((string)($_POST['subject'] ?? ''));
    $message = trim((string)($_POST['message'] ?? ''));
    if ($name === '') { $errors[] = 'Please enter your name.'; }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $errors[] = 'Please enter a valid email address.'; }
    if ($subject === '') { $errors[] = 'Please enter a subject.'; }
    if ($message === '') { $errors[] = 'Please write a message.'; }
    if (!$errors) {
        $description = 'Contact form: ' . $name . ' <' . $email . '> — ' . $subject . ': ' . $message;
        try {
            require_once __DIR__ . '/config/database.php';
            $stmt = db()->prepare('INSERT INTO activity_logs (tenant_id, user_id, description, ip_address, created_at) VALUES (NULL, NULL, ?, ?, NOW())');
            $stmt->execute([$description, (string)($_SERVER['REMOTE_ADDR'] ?? '')]);
        } catch (Throwable $ignored) {
            // Logging is best-effort on the public site; the message is still accepted in demo environments.
        }
        flash('success', 'Thank you, ' . $name . '! Your message has been received — our team replies within one business day.');
        redirect('contact.php');
    }
}

$pageTitle = 'Contact';
$base = '';
include __DIR__ . '/includes/site-header.php';
?>
<section class="page-hero">
  <div class="container">
    <span class="kicker">Contact</span>
    <h1 class="display-5 fw-bold mt-2">Talk to the <span class="text-primary">MediCare Cloud</span> team</h1>
    <p class="lead text-muted" style="max-width:680px">Sales questions, guided demos, migration help or support — send us a message and we will get back within one business day.</p>
  </div>
</section>

<section class="section" style="padding-top:36px">
  <div class="container">
    <div class="row g-4 mb-5">
      <div class="col-md-4"><div class="feature-card text-center"><div class="fic mx-auto"><i class="bi bi-envelope-fill"></i></div><h5>Email us</h5><p class="text-muted mb-0">support@medicare.test<br><small>Replies within one business day</small></p></div></div>
      <div class="col-md-4"><div class="feature-card text-center"><div class="fic mx-auto"><i class="bi bi-telephone-fill"></i></div><h5>Call us</h5><p class="text-muted mb-0">+1 555 000 1111<br><small>Mon–Sat, 9:00 AM – 6:00 PM</small></p></div></div>
      <div class="col-md-4"><div class="feature-card text-center"><div class="fic mx-auto"><i class="bi bi-geo-alt-fill"></i></div><h5>Visit us</h5><p class="text-muted mb-0">MediCare Cloud HQ<br><small>Health Tech District</small></p></div></div>
    </div>

    <div class="row g-5">
      <div class="col-lg-7">
        <?php if ($errors): ?>
        <div class="alert alert-danger"><strong>Please fix the following:</strong><ul class="mb-0 mt-1"><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
        <?php endif; ?>
        <form method="post" class="card p-4">
          <?= csrf_field() ?>
          <div class="row g-3">
            <div class="col-md-6"><label class="form-label fw-semibold" for="name">Your name *</label><input class="form-control" id="name" name="name" value="<?= e((string)($_POST['name'] ?? '')) ?>" required></div>
            <div class="col-md-6"><label class="form-label fw-semibold" for="email">Email *</label><input class="form-control" id="email" type="email" name="email" value="<?= e((string)($_POST['email'] ?? '')) ?>" required></div>
            <div class="col-12"><label class="form-label fw-semibold" for="subject">Subject *</label><input class="form-control" id="subject" name="subject" value="<?= e((string)($_POST['subject'] ?? '')) ?>" placeholder="e.g. Demo for a 120-bed hospital" required></div>
            <div class="col-12"><label class="form-label fw-semibold" for="message">Message *</label><textarea class="form-control" id="message" name="message" rows="6" required><?= e((string)($_POST['message'] ?? '')) ?></textarea></div>
            <div class="col-12"><button class="btn btn-primary btn-lg" type="submit"><i class="bi bi-send me-1"></i> Send Message</button></div>
          </div>
        </form>
      </div>
      <div class="col-lg-5">
        <div class="rounded-4 border d-flex flex-column align-items-center justify-content-center text-center p-4 h-100" style="background:linear-gradient(160deg,#eff6ff,#ecfdf5);min-height:320px" role="img" aria-label="Map placeholder showing MediCare Cloud HQ location">
          <i class="bi bi-map text-primary" style="font-size:3.5rem"></i>
          <h5 class="mt-3">MediCare Cloud HQ</h5>
          <p class="text-muted mb-1">Health Tech District</p>
          <p class="text-muted small mb-3"><i class="bi bi-geo-alt-fill text-danger me-1"></i> Map preview — visit us by appointment</p>
          <div class="d-flex gap-4 text-muted small">
            <span><i class="bi bi-hospital me-1"></i> 850+ hospitals served</span>
            <span><i class="bi bi-globe me-1"></i> 40+ countries</span>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>
<?php include __DIR__ . '/includes/site-footer.php'; ?>
