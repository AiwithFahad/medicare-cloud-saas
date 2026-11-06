<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';

$base = '../';
$pdo  = db();

if (current_user()) {
    redirect('../app/dashboard.php');
}

// Plans for the select box.
$ps = $pdo->prepare('SELECT id, name, slug, monthly_price, yearly_price, trial_days FROM subscription_plans WHERE is_active = 1 ORDER BY sort_order ASC, id ASC');
$ps->execute();
$plans = $ps->fetchAll();

$errors  = [];
$success = false;
$verifyLink = '';
$old = [
    'hospital_name'  => '',
    'slug'           => '',
    'hospital_email' => '',
    'phone'          => '',
    'city'           => '',
    'country'        => '',
    'admin_name'     => '',
    'admin_email'    => '',
    'plan_id'        => '',
];

if (is_post()) {
    verify_csrf();

    foreach (array_keys($old) as $k) {
        $old[$k] = trim((string)($_POST[$k] ?? ''));
    }
    $password        = (string)($_POST['password'] ?? '');
    $passwordConfirm = (string)($_POST['password_confirm'] ?? '');
    if ($old['slug'] === '' && $old['hospital_name'] !== '') {
        $old['slug'] = slugify($old['hospital_name']);
    }
    $old['slug'] = slugify($old['slug']);

    // ---- Validation ----
    if ($old['hospital_name'] === '') {
        $errors[] = 'Hospital name is required.';
    }
    if ($old['hospital_email'] === '' || !filter_var($old['hospital_email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'A valid hospital email is required.';
    }
    if ($old['admin_name'] === '') {
        $errors[] = 'Administrator name is required.';
    }
    if ($old['admin_email'] === '' || !filter_var($old['admin_email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'A valid administrator email is required.';
    }
    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    } elseif ($password !== $passwordConfirm) {
        $errors[] = 'Password and confirmation do not match.';
    }

    $plan = null;
    if ($old['plan_id'] === '' || !ctype_digit($old['plan_id'])) {
        $errors[] = 'Please choose a subscription plan.';
    } else {
        $s = $pdo->prepare('SELECT * FROM subscription_plans WHERE id = ? AND is_active = 1 LIMIT 1');
        $s->execute([(int)$old['plan_id']]);
        $plan = $s->fetch() ?: null;
        if (!$plan) {
            $errors[] = 'Please choose a valid subscription plan.';
        }
    }

    if (!$errors) {
        $s = $pdo->prepare('SELECT COUNT(*) FROM tenants WHERE slug = ?');
        $s->execute([$old['slug']]);
        if ((int)$s->fetchColumn() > 0) {
            $errors[] = 'That hospital slug is already taken — please choose a different one.';
        }
        $s = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = ?');
        $s->execute([$old['admin_email']]);
        if ((int)$s->fetchColumn() > 0) {
            $errors[] = 'That administrator email is already registered — please sign in instead.';
        }
    }

    if (!$errors && $plan) {
        $verificationToken = bin2hex(random_bytes(20)); // 40 chars
        $trialDays = (int)($plan['trial_days'] ?? TRIAL_DAYS);
        if ($trialDays <= 0) {
            $trialDays = (int)TRIAL_DAYS;
        }
        try {
            $pdo->beginTransaction();

            $s = $pdo->prepare(
                'INSERT INTO tenants (name, slug, email, phone, city, country, status, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, "active", NOW(), NOW())'
            );
            $s->execute([
                $old['hospital_name'],
                $old['slug'],
                $old['hospital_email'],
                $old['phone'] !== '' ? $old['phone'] : null,
                $old['city'] !== '' ? $old['city'] : null,
                $old['country'] !== '' ? $old['country'] : null,
            ]);
            $tenantId = (int)$pdo->lastInsertId();

            $s = $pdo->prepare('INSERT INTO tenant_settings (tenant_id, skey, svalue) VALUES (?, "hospital_name", ?)');
            $s->execute([$tenantId, $old['hospital_name']]);

            $s = $pdo->prepare(
                'INSERT INTO users (tenant_id, name, email, password_hash, role_id, status, email_verified_at, verification_token, created_at, updated_at)
                 VALUES (?, ?, ?, ?, (SELECT id FROM roles WHERE slug = "hospital_admin"), "active", NULL, ?, NOW(), NOW())'
            );
            $s->execute([
                $tenantId,
                $old['admin_name'],
                $old['admin_email'],
                password_hash($password, PASSWORD_DEFAULT),
                $verificationToken,
            ]);
            $adminId = (int)$pdo->lastInsertId();

            $s = $pdo->prepare(
                "INSERT INTO subscriptions (tenant_id, plan_id, billing_cycle, status, starts_at, trial_ends_at, created_at)
                 VALUES (?, ?, \"monthly\", \"trial\", CURDATE(), DATE_ADD(CURDATE(), INTERVAL {$trialDays} DAY), NOW())"
            );
            $s->execute([$tenantId, (int)$plan['id']]);

            $pdo->commit();

            audit_log($pdo, 'tenant_registered', 'tenants', $tenantId, 'Hospital registered: ' . $old['hospital_name'] . ' on plan ' . (string)$plan['name']);
            log_activity($pdo, 'New hospital registered: ' . $old['hospital_name'] . ' (' . $old['slug'] . ')');
            notify_user($pdo, $tenantId, $adminId, 'Welcome to ' . APP_NAME, 'Your ' . $trialDays . '-day free trial has started. Please verify your email to secure your account.', 'success');

            $success    = true;
            $verifyLink = 'verify.php?token=' . urlencode($verificationToken);
            flash('success', 'Hospital registered successfully. Please verify your email address using the verification link, then sign in to start your ' . $trialDays . '-day free trial.');
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = 'Registration failed. The slug or administrator email may already be in use — please try again.';
        }
    }
}

$pageTitle = 'Register your hospital';
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
  <div class="auth-card wide">
    <a class="brand justify-content-center d-flex mb-2" href="<?= $base ?>index.php">
      <span class="brand-mark"><i class="bi bi-heart-pulse-fill"></i></span>
      <span class="brand-text">MediCare <em>Cloud</em></span>
    </a>

    <?php if ($success): ?>
      <div class="text-center">
        <div class="display-5 text-success mb-2"><i class="bi bi-patch-check-fill"></i></div>
        <h1 class="h4 mb-2">Hospital registered</h1>
        <p class="text-muted small">Your hospital workspace and administrator account were created, and your free trial has started. Normally we would email you a verification link — this demo has no mail server, so please use the link below to verify your administrator email and complete setup:</p>
        <div class="alert alert-info text-break small">
          <strong>Verify your email:</strong><br>
          <a href="<?= e($verifyLink) ?>"><?= e($verifyLink) ?></a>
        </div>
        <a class="btn btn-primary w-100" href="login.php">Continue to sign in</a>
        <p class="small text-muted mt-3 mb-0">You can sign in right away; verification simply confirms ownership of the admin email.</p>
      </div>
    <?php else: ?>
      <h1 class="h4 text-center mb-1">Register your hospital</h1>
      <p class="text-muted text-center small mb-4">Start your free trial of <?= e(APP_NAME) ?> — no card required.</p>

      <?php if ($errors): ?>
        <div class="alert alert-danger"><ul class="mb-0 ps-3"><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
      <?php endif; ?>

      <form method="post" novalidate>
        <?= csrf_field() ?>
        <h2 class="h6 text-muted text-uppercase mb-3">Hospital details</h2>
        <div class="row g-3">
          <div class="col-md-7">
            <label class="form-label" for="hospital_name">Hospital name</label>
            <input class="form-control" id="hospital_name" name="hospital_name" value="<?= e($old['hospital_name']) ?>" required>
          </div>
          <div class="col-md-5">
            <label class="form-label" for="slug">Slug</label>
            <input class="form-control" id="slug" name="slug" value="<?= e($old['slug']) ?>" required>
            <div class="form-text">Used in your hospital URL.</div>
          </div>
          <div class="col-md-6">
            <label class="form-label" for="hospital_email">Hospital email</label>
            <input class="form-control" type="email" id="hospital_email" name="hospital_email" value="<?= e($old['hospital_email']) ?>" required>
          </div>
          <div class="col-md-6">
            <label class="form-label" for="phone">Phone</label>
            <input class="form-control" id="phone" name="phone" value="<?= e($old['phone']) ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label" for="city">City</label>
            <input class="form-control" id="city" name="city" value="<?= e($old['city']) ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label" for="country">Country</label>
            <input class="form-control" id="country" name="country" value="<?= e($old['country']) ?>">
          </div>
        </div>

        <h2 class="h6 text-muted text-uppercase mt-4 mb-3">Administrator account</h2>
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label" for="admin_name">Admin full name</label>
            <input class="form-control" id="admin_name" name="admin_name" value="<?= e($old['admin_name']) ?>" required autocomplete="name">
          </div>
          <div class="col-md-6">
            <label class="form-label" for="admin_email">Admin email</label>
            <input class="form-control" type="email" id="admin_email" name="admin_email" value="<?= e($old['admin_email']) ?>" required autocomplete="email">
          </div>
          <div class="col-md-6">
            <label class="form-label" for="password">Password</label>
            <input class="form-control" type="password" id="password" name="password" required minlength="8" autocomplete="new-password">
            <div class="form-text">At least 8 characters.</div>
          </div>
          <div class="col-md-6">
            <label class="form-label" for="password_confirm">Confirm password</label>
            <input class="form-control" type="password" id="password_confirm" name="password_confirm" required autocomplete="new-password">
          </div>
          <div class="col-12">
            <label class="form-label" for="plan_id">Subscription plan</label>
            <select class="form-select" id="plan_id" name="plan_id" required>
              <option value="">— Choose a plan —</option>
              <?php foreach ($plans as $p): ?>
                <option value="<?= (int)$p['id'] ?>" <?= $old['plan_id'] === (string)$p['id'] ? 'selected' : '' ?>>
                  <?= e((string)$p['name']) ?> — <?= e(money($p['monthly_price'])) ?>/mo · <?= (int)$p['trial_days'] ?>-day free trial
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <button class="btn btn-primary w-100 mt-4" type="submit"><i class="bi bi-hospital me-1"></i> Create hospital &amp; start trial</button>
      </form>
      <p class="small text-center mt-3 mb-0">Already registered? <a href="login.php">Sign in</a></p>
    <?php endif; ?>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function(){
  var nameEl=document.getElementById('hospital_name'), slugEl=document.getElementById('slug'), dirty=false;
  if(!nameEl||!slugEl) return;
  slugEl.addEventListener('input',function(){dirty=true;});
  nameEl.addEventListener('input',function(){
    if(dirty) return;
    slugEl.value=nameEl.value.toLowerCase().trim().replace(/[^a-z0-9]+/g,'-').replace(/^-+|-+$/g,'');
  });
})();
</script>
</body>
</html>
