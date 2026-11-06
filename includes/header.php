<?php
/* App layout header: <head>, topbar (search, notifications, theme, profile).
 * Pages set $pageTitle and $base ('' or '../') before including this file.
 * Unread notifications for the topbar are loaded here from the database. */
$b  = $base ?? '';
$cu = function_exists('current_user') ? (current_user() ?? []) : [];
$nm = trim((string)($cu['name'] ?? ''));
$notifs = [];
$unread = 0;
if (!empty($cu)) {
    try {
        $ns = db()->prepare('SELECT * FROM notifications WHERE (user_id = ? OR (tenant_id = ? AND user_id IS NULL)) ORDER BY id DESC LIMIT 6');
        $ns->execute([(int)($cu['id'] ?? 0), tenant_id() ?? 0]);
        $notifs = $ns->fetchAll();
        $cs = db()->prepare('SELECT COUNT(*) FROM notifications WHERE is_read = 0 AND (user_id = ? OR (tenant_id = ? AND user_id IS NULL))');
        $cs->execute([(int)($cu['id'] ?? 0), tenant_id() ?? 0]);
        $unread = (int)$cs->fetchColumn();
    } catch (Throwable $ignored) {
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(($pageTitle ?? 'Dashboard')) ?> | <?= e(APP_NAME) ?></title>
<meta name="description" content="MediCare Cloud — multi-tenant Hospital Management SaaS">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<meta name="api-base" content="<?= $b ?>api/">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="<?= $b ?>assets/css/style.css" rel="stylesheet">
<script>(function(){try{if(localStorage.getItem('medicare-theme')==='dark')document.documentElement.setAttribute('data-theme','dark');if(localStorage.getItem('medicare-sidebar')==='collapsed')document.documentElement.classList.add('sidebar-pre-collapsed');}catch(e){}})();</script>
</head>
<body>
<div class="layout">
<?php if (!empty($panelSidebar)) { include __DIR__ . '/panel-sidebar.php'; } else { include __DIR__ . '/sidebar.php'; } ?>
<div class="sidebar-backdrop" id="sidebarBackdrop"></div>
<main class="main">
<header class="topbar">
  <button class="icon-btn" id="sidebarToggle" type="button" title="Toggle sidebar" aria-label="Toggle sidebar"><i class="bi bi-layout-sidebar-inset"></i></button>
  <h1><?= e($pageTitle ?? '') ?></h1>
  <div class="search-wrap d-none d-md-flex"><i class="bi bi-search"></i><input class="form-control" id="globalSearch" type="search" placeholder="Search menu…" aria-label="Search menu"></div>
  <span class="ms-auto"></span>
  <div class="dropdown">
    <button class="icon-btn position-relative" type="button" data-bs-toggle="dropdown" aria-label="Notifications">
      <i class="bi bi-bell"></i><?php if ($unread > 0): ?><span class="dot"></span><?php endif; ?>
    </button>
    <ul class="dropdown-menu dropdown-menu-end notif-menu">
      <li class="dropdown-header">Notifications <?= $unread ? '(' . $unread . ' unread)' : '' ?></li>
      <?php if (!$notifs): ?><li><span class="dropdown-item-text text-muted small">No notifications yet.</span></li><?php endif; ?>
      <?php foreach ($notifs as $n): ?>
        <li><span class="dropdown-item-text"><strong class="d-block small"><?= e($n['title']) ?></strong><span class="text-muted small"><?= e($n['body']) ?></span></span></li>
      <?php endforeach; ?>
    </ul>
  </div>
  <button class="icon-btn" id="themeToggle" type="button" title="Toggle light / dark" aria-label="Toggle theme"><i class="bi bi-moon-stars"></i></button>
  <div class="dropdown">
    <button class="profile-chip" type="button" data-bs-toggle="dropdown" aria-label="Profile menu">
      <span class="avatar-initials"><?= e(initials($nm)) ?></span>
      <span class="d-none d-lg-block text-start"><strong class="d-block lh-1 small"><?= e($nm) ?></strong><small class="text-muted text-capitalize"><?= e(str_replace('_', ' ', (string)($cu['role'] ?? ''))) ?></small></span>
    </button>
    <ul class="dropdown-menu dropdown-menu-end">
      <li><span class="dropdown-item-text small text-muted"><?= e((string)($cu['email'] ?? '')) ?></span></li>
      <li><hr class="dropdown-divider"></li>
      <?php
      $panelKey = (string)($panelSidebar ?? '');
      $settingsUrl = $panelKey === 'admin' ? $b . 'admin/settings.php' : $b . 'app/settings.php';
      $patientUrl  = $panelKey === 'patient' ? $b . 'patient/profile.php' : $b . 'app/portal.php';
      ?>
      <?php if (($cu['role'] ?? '') === 'patient'): ?>
        <li><a class="dropdown-item" href="<?= $patientUrl ?>"><i class="bi bi-person-heart me-2"></i>My Profile</a></li>
      <?php elseif (($cu['role'] ?? '') !== 'super_admin'): ?>
        <li><a class="dropdown-item" href="<?= $settingsUrl ?>"><i class="bi bi-gear me-2"></i>Settings</a></li>
        <li><a class="dropdown-item" href="<?= $b ?>app/subscription.php"><i class="bi bi-credit-card me-2"></i>Subscription</a></li>
      <?php endif; ?>
      <li><a class="dropdown-item text-danger" href="<?= $b ?>auth/logout.php"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
    </ul>
  </div>
</header>
<div class="page-body">
<?php if ($m = flash('success')): ?><div class="alert alert-success alert-dismissible fade show"><?= e($m) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
<?php if ($m = flash('error')): ?><div class="alert alert-danger alert-dismissible fade show"><?= e($m) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
