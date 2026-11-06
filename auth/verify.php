<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';

$base  = '../';
$pdo   = db();
$token = trim((string)($_GET['token'] ?? ''));

$state   = 'invalid'; // invalid | already | success
$message = 'This verification link is invalid or has already been used. Please sign in, or register again if you do not have an account.';

if ($token !== '') {
    $s = $pdo->prepare('SELECT id, email, email_verified_at FROM users WHERE verification_token = ? LIMIT 1');
    $s->execute([$token]);
    $user = $s->fetch();
    if ($user) {
        if (!empty($user['email_verified_at'])) {
            $state   = 'already';
            $message = 'Your email address is already verified. You can sign in now.';
        } else {
            $pdo->prepare('UPDATE users SET email_verified_at = NOW(), verification_token = NULL WHERE id = ?')
                ->execute([(int)$user['id']]);
            audit_log($pdo, 'email_verified', 'users', (int)$user['id'], 'Email verified for ' . (string)$user['email']);
            $state   = 'success';
            $message = 'Your email address has been verified successfully. You can now sign in to ' . APP_NAME . '.';
        }
    }
}

$pageTitle = 'Verify email';
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
  <div class="auth-card text-center">
    <a class="brand justify-content-center d-flex mb-3" href="<?= $base ?>index.php">
      <span class="brand-mark"><i class="bi bi-heart-pulse-fill"></i></span>
      <span class="brand-text">MediCare <em>Cloud</em></span>
    </a>
    <?php if ($state === 'success' || $state === 'already'): ?>
      <div class="display-5 text-success mb-2"><i class="bi bi-patch-check-fill"></i></div>
      <h1 class="h4 mb-2"><?= $state === 'success' ? 'Email verified' : 'Already verified' ?></h1>
      <p class="text-muted small"><?= e($message) ?></p>
      <a class="btn btn-primary w-100" href="login.php">Go to sign in</a>
    <?php else: ?>
      <div class="display-5 text-danger mb-2"><i class="bi bi-exclamation-octagon"></i></div>
      <h1 class="h4 mb-2">Verification failed</h1>
      <p class="text-muted small"><?= e($message) ?></p>
      <a class="btn btn-outline-primary w-100" href="login.php">Back to sign in</a>
      <p class="small mt-3 mb-0">Need an account? <a href="register.php">Register your hospital</a></p>
    <?php endif; ?>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
