<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';

$base = '../';
$pdo  = db();

$submitted = false;
$resetLink = '';
$email     = '';

if (is_post()) {
    verify_csrf();
    $email     = trim((string)($_POST['email'] ?? ''));
    $submitted = true;

    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $s = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $s->execute([$email]);
        if ($s->fetch()) {
            $token = bin2hex(random_bytes(24)); // 48 chars
            $pdo->prepare('DELETE FROM password_resets WHERE email = ?')->execute([$email]);
            $pdo->prepare('INSERT INTO password_resets (email, token, created_at) VALUES (?, ?, NOW())')
                ->execute([$email, $token]);
            // Demo only (no mail server): surface the link so the flow is completable.
            $resetLink = 'reset-password.php?token=' . urlencode($token);
        }
    }
}

$pageTitle = 'Forgot password';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?> | <?= e(APP_NAME) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="<?= $base ?>assets/css/style.css" rel="stylesheet">
<script>(function(){try{if(localStorage.getItem('medicare-theme')==='dark')document.documentElement.setAttribute('data-theme','dark');}catch(e){}})();</script>
</head>
<body>
<div class="auth-wrap">
  <div class="auth-card">
    <a class="brand justify-content-center d-flex mb-2" href="<?= $base ?>index.php">
      <span class="brand-mark"><i class="bi bi-heart-pulse-fill"></i></span>
      <span class="brand-text">MediCare <em>Cloud</em></span>
    </a>
    <h1 class="h4 text-center mb-1">Forgot your password?</h1>
    <p class="text-muted text-center small mb-4">Enter your account email and we will create a password reset link. Links expire after 60 minutes.</p>

    <?php if ($submitted): ?>
      <div class="alert alert-success">If an account exists for that email address, a password reset link has been created. Please check your inbox (in this demo, use the link below).</div>
      <?php if ($resetLink !== ''): ?>
        <div class="alert alert-info text-break small">
          <strong>Demo reset link</strong> (shown because this demo has no mail server):<br>
          <a href="<?= e($resetLink) ?>"><?= e($resetLink) ?></a>
        </div>
      <?php endif; ?>
      <a class="btn btn-outline-primary w-100" href="login.php">Back to sign in</a>
    <?php else: ?>
      <form method="post" novalidate>
        <?= csrf_field() ?>
        <div class="mb-3">
          <label class="form-label" for="email">Email address</label>
          <input class="form-control" type="email" id="email" name="email" value="<?= e($email) ?>" required autofocus autocomplete="email">
        </div>
        <button class="btn btn-primary w-100" type="submit"><i class="bi bi-envelope me-1"></i> Send reset link</button>
      </form>
      <p class="small text-center mt-3 mb-0">Remembered it? <a href="login.php">Sign in</a></p>
    <?php endif; ?>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
