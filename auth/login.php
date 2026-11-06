<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';

$base = '../';

// Already signed in — send the user to their home screen.
if ($u = current_user()) {
    redirect(match ($u['role'] ?? '') {
        'super_admin' => '../super-admin/index.php',
        'patient'     => '../app/portal.php',
        default       => '../app/dashboard.php',
    });
}

$pdo   = db();
$error = '';
$email = '';

if (isset($_GET['error']) && $_GET['error'] === 'suspended') {
    $error = 'Your hospital account has been suspended. Please contact MediCare Cloud support.';
}

if (is_post()) {
    verify_csrf();
    $email    = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $ip       = (string)($_SERVER['REMOTE_ADDR'] ?? '');

    $recordAttempt = static function (bool $success) use ($pdo, $email, $ip): void {
        try {
            $s = $pdo->prepare('INSERT INTO login_attempts (email, ip_address, success, attempted_at) VALUES (?, ?, ?, NOW())');
            $s->execute([$email, $ip !== '' ? $ip : null, $success ? 1 : 0]);
        } catch (Throwable $ignored) {
        }
    };

    if ($email === '' || $password === '') {
        $error = 'Please enter your email and password.';
    } else {
        // Rate limiting: too many recent failures for this email locks login.
        $lockMinutes = (int)LOGIN_LOCK_MINUTES;
        $s = $pdo->prepare(
            "SELECT COUNT(*) FROM login_attempts
              WHERE email = ? AND success = 0
                AND attempted_at >= DATE_SUB(NOW(), INTERVAL {$lockMinutes} MINUTE)"
        );
        $s->execute([$email]);
        $failed = (int)$s->fetchColumn();

        if ($failed >= (int)LOGIN_MAX_ATTEMPTS) {
            $error = 'Too many failed login attempts. Please try again in ' . (int)LOGIN_LOCK_MINUTES . ' minutes.';
        } else {
            $s = $pdo->prepare(
                'SELECT u.*, r.slug AS role_slug
                   FROM users u
                   JOIN roles r ON r.id = u.role_id
                  WHERE u.email = ? LIMIT 1'
            );
            $s->execute([$email]);
            $row = $s->fetch();

            if (!$row || !password_verify($password, (string)$row['password_hash'])) {
                $recordAttempt(false);
                $error = 'Invalid email or password.';
            } elseif (($row['status'] ?? '') !== 'active') {
                $recordAttempt(false);
                $error = 'Your account is not active. Please contact your hospital administrator.';
            } else {
                // Suspended tenants are blocked at the door as well.
                $tenantSuspended = false;
                if (!empty($row['tenant_id'])) {
                    $ts = $pdo->prepare('SELECT status FROM tenants WHERE id = ?');
                    $ts->execute([(int)$row['tenant_id']]);
                    $tenantSuspended = $ts->fetchColumn() === 'suspended';
                }
                if ($tenantSuspended) {
                    $recordAttempt(false);
                    $error = 'Your hospital account has been suspended. Please contact MediCare Cloud support.';
                } else {
                    $recordAttempt(true);

                    $pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')
                        ->execute([(int)$row['id']]);

                    session_regenerate_id(true);
                    establish_session($row, load_user_permissions($pdo, (int)$row['role_id']));

                    if (!empty($_POST['remember'])) {
                        $token = bin2hex(random_bytes(32));
                        $pdo->prepare('UPDATE users SET remember_token = ? WHERE id = ?')
                            ->execute([hash('sha256', $token), (int)$row['id']]);
                        setcookie('remember', (int)$row['id'] . ':' . $token, [
                            'expires'  => time() + 86400 * 30,
                            'path'     => '/',
                            'httponly' => true,
                            'samesite' => 'Lax',
                            'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
                        ]);
                    }

                    audit_log($pdo, 'login', 'users', (int)$row['id'], 'User signed in');
                    log_activity($pdo, 'User signed in: ' . (string)$row['email']);

                    redirect(match ((string)$row['role_slug']) {
                        'super_admin' => '../super-admin/index.php',
                        'patient'     => '../app/portal.php',
                        default       => '../app/dashboard.php',
                    });
                }
            }
        }
    }
}

$pageTitle = 'Login';
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
    <h1 class="h4 text-center mb-1">Welcome back</h1>
    <p class="text-muted text-center small mb-4">Sign in to your <?= e(APP_NAME) ?> account.</p>

    <?php if ($m = flash('success')): ?><div class="alert alert-success"><?= e($m) ?></div><?php endif; ?>
    <?php if ($m = flash('error')): ?><div class="alert alert-danger"><?= e($m) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

    <form method="post" novalidate>
      <?= csrf_field() ?>
      <div class="mb-3">
        <label class="form-label" for="email">Email address</label>
        <input class="form-control" type="email" id="email" name="email" value="<?= e($email) ?>" required autofocus autocomplete="email">
      </div>
      <div class="mb-3">
        <label class="form-label" for="password">Password</label>
        <input class="form-control" type="password" id="password" name="password" required autocomplete="current-password">
      </div>
      <div class="d-flex justify-content-between align-items-center mb-3">
        <div class="form-check mb-0">
          <input class="form-check-input" type="checkbox" name="remember" id="remember" value="1">
          <label class="form-check-label small" for="remember">Remember me</label>
        </div>
        <a class="small" href="forgot-password.php">Forgot password?</a>
      </div>
      <button class="btn btn-primary w-100" type="submit"><i class="bi bi-box-arrow-in-right me-1"></i> Sign in</button>
    </form>

    <p class="small text-center mt-3 mb-0">New to <?= e(APP_NAME) ?>? <a href="register.php">Register your hospital</a></p>

    <div class="demo-creds mt-4">
      <strong class="d-block mb-1"><i class="bi bi-key me-1"></i>Demo accounts</strong>
      All use password <code>password</code>:
      <ul class="mb-0 mt-1 ps-3">
        <li>Super Admin — <code>super@medicare.test</code></li>
        <li>Hospital Admin — <code>admin@demo.com</code></li>
        <li>Doctor — <code>doctor@demo.com</code></li>
        <li>Receptionist — <code>reception@demo.com</code></li>
        <li>Patient — <code>patient@demo.com</code></li>
      </ul>
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
