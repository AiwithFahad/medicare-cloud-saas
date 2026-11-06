<?php
/* Public marketing-site header. Pages require config + functions first and
 * may set $pageTitle and $base. No login required. */
$b = $base ?? '';
$cur = basename((string)($_SERVER['SCRIPT_NAME'] ?? 'index.php'));
$nav = function (string $file, string $label) use ($b, $cur): string {
    return '<a class="nav-link' . ($cur === $file ? ' active' : '') . '" href="' . $b . $file . '">' . $label . '</a>';
};
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(($pageTitle ?? 'Hospital Management SaaS')) ?> | <?= e(APP_NAME) ?></title>
<meta name="description" content="MediCare Cloud is the multi-tenant Hospital Management SaaS: patients, doctors, appointments, beds, pharmacy, lab, billing and subscriptions in one secure platform.">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="<?= $b ?>assets/css/style.css" rel="stylesheet">
</head>
<body class="site">
<header class="site-nav sticky-top">
  <nav class="navbar navbar-expand-lg container">
    <a class="navbar-brand brand" href="<?= $b ?>index.php"><span class="brand-mark"><i class="bi bi-heart-pulse-fill"></i></span><span class="brand-text">MediCare <em>Cloud</em></span></a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#siteNav" aria-label="Toggle navigation"><span class="navbar-toggler-icon"></span></button>
    <div class="collapse navbar-collapse" id="siteNav">
      <div class="navbar-nav mx-auto gap-lg-2">
        <?= $nav('index.php', 'Home') ?>
        <?= $nav('about.php', 'About') ?>
        <?= $nav('features.php', 'Features') ?>
        <?= $nav('pricing.php', 'Pricing') ?>
        <?= $nav('departments.php', 'Departments') ?>
        <?= $nav('doctors.php', 'Doctors') ?>
        <?= $nav('blog.php', 'Blog') ?>
        <?= $nav('contact.php', 'Contact') ?>
      </div>
      <div class="d-flex gap-2 mt-3 mt-lg-0">
        <a class="btn btn-outline-primary" href="<?= $b ?>auth/login.php">Login</a>
        <a class="btn btn-primary" href="<?= $b ?>auth/register.php">Start Free Trial</a>
      </div>
    </div>
  </nav>
</header>
<?php if ($m = flash('success')): ?><div class="container mt-3"><div class="alert alert-success alert-dismissible fade show"><?= e($m) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div></div><?php endif; ?>
<?php if ($m = flash('error')): ?><div class="container mt-3"><div class="alert alert-danger alert-dismissible fade show"><?= e($m) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div></div><?php endif; ?>
