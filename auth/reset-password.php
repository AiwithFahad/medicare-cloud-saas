<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';

$base  = '../';
$pdo   = db();
$token = trim((string)($_REQUEST['token'] ?? ''));

$findValidReset = static function (string $tok) use ($pdo): ?array {
    if ($tok === '') {
        return null;
    }
    $s = $pdo->prepare(
        'SELECT email, token, created_at FROM password_resets
          WHERE token = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 60 MINUTE)
          ORDER BY created_at DESC LIMIT 1'
    );
    $s->execute([$tok]);
    $row = $s->fetch();
    return $row ?: null;
};

$reset = $findValidReset($token);
$error = '';

if (is_post()) {
    verify_csrf();
    $token    = trim((string)($_POST['token'] ?? ''));
    $reset    = $findValidReset($token);
    $password = (string)($_POST['password'] ?? '');
    $confirm  = (string)($_POST['password_confirm'] ?? '');

    if (!$reset) {
        $error = 'This reset link is invalid or has expired. Please request a new one.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Password and confirmation do not match.';
    } else {
        $email = (string)$reset['email'];
        $pdo->prepare('UPDATE users SET password_hash = ?, remember_token = NULL WHERE email = ?')
            ->execute([password_hash($password, PASSWORD_DEFAULT), $email]);
        $pdo->prepare('DELETE FROM password_resets WHERE email = ?')->execute([$email]);

        $s = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $s->execute([$email]);
        $uid = $s->fetchColumn();
        audit_log($pdo, 'password_reset', 'users', $uid !== false ? (int)$uid : null, 'Password reset via email link for ' . $email);

        flash('success', 'Your password has been updated. Please sign in with your new password.');
        redirect('login.php');
    }
}

$pageTitle = 'Reset password';
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

    <?php if (!$reset && !is_post()): ?>
      <div class="text-center">
        <div class="display-5 text-danger mb-2"><i class="bi bi-clock-history"></i></div>
        <h1 class="h4 mb-2">Link invalid or expired</h1>
        <p class="text-muted small">This password reset link is invalid or older than 60 minutes. Please request a new reset link.</p>
        <a class="btn btn-primary w-100" href="forgot-password.php">Request a new link</a>
        <p class="small mt-3 mb-0"><a href="login.php">Back to sign in</a></p>
      </div>
    <?php else: ?>
      <h1 class="h4 text-center mb-1">Set a new password</h1>
      <p class="text-muted text-center small mb-4">Choose a new password for your <?= e(APP_NAME) ?> account.</p>
      <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
      <?php if ($reset): ?>
      <form method="post" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <div class="mb-3">
          <label class="form-label" for="password">New password</label>
          <input class="form-control" type="password" id="password" name="password" required minlength="8" autocomplete="new-password" autofocus>
          <div class="form-text">At least 8 characters.</div>
        </div>
        <div class="mb-3">
          <label class="form-label" for="password_confirm">Confirm new password</label>
          <input class="form-control" type="password" id="password_confirm" name="password_confirm" required autocomplete="new-password">
        </div>
        <button class="btn btn-primary w-100" type="submit"><i class="bi bi-shield-lock me-1"></i> Update password</button>
      </form>
      <?php endif; ?>
      <p class="small text-center mt-3 mb-0"><a href="login.php">Back to sign in</a></p>
    <?php endif; ?>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
