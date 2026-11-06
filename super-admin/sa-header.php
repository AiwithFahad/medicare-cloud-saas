<?php
declare(strict_types=1);
/* Super admin layout header: own <head> (../assets), platform topbar with
 * search placeholder, theme toggle and the super admin profile chip.
 * Pages set $pageTitle and $user before including this file. This header
 * includes sa-sidebar.php. Super admins have tenant_id NULL — no tenant
 * context is required or checked here. */
$saUser = function_exists('current_user') ? (current_user() ?? []) : ($user ?? []);
$saName = trim((string)($saUser['name'] ?? 'Super Admin'));
$pageTitle = $pageTitle ?? 'Super Admin';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?> | MediCare Cloud — Platform</title>
<meta name="description" content="MediCare Cloud — platform administration console">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<meta name="api-base" content="../api/">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="../assets/css/style.css" rel="stylesheet">
<style>
  .sa-sidebar{background:linear-gradient(180deg,#0b1e42 0%,#14306e 55%,#1d4ed8 145%);border-right:0;--sidebar-ink:#dbeafe}
  .sa-sidebar .brand{color:#fff}
  .sa-sidebar .brand-text em{color:#34d399}
  .sa-sidebar .side-heading{color:rgba(219,234,254,.55)}
  .sa-sidebar .side-link{color:#dbeafe}
  .sa-sidebar .side-link:hover{background:rgba(255,255,255,.12);color:#fff}
  .sa-sidebar .side-link.active{background:#fff;color:#1d4ed8;box-shadow:0 6px 18px rgba(2,6,23,.25)}
  .sa-sidebar .side-footer{border-top-color:rgba(255,255,255,.18)}
  html[data-theme="dark"] .sa-sidebar{background:linear-gradient(180deg,#081630 0%,#0f2450 60%,#1e40af 150%)}
</style>
<script>(function(){try{if(localStorage.getItem('medicare-theme')==='dark')document.documentElement.setAttribute('data-theme','dark');if(localStorage.getItem('medicare-sidebar')==='collapsed')document.documentElement.classList.add('sidebar-pre-collapsed');}catch(e){}})();</script>
</head>
<body>
<div class="layout">
<?php include __DIR__ . '/sa-sidebar.php'; ?>
<div class="sidebar-backdrop" id="sidebarBackdrop"></div>
<main class="main">
<header class="topbar">
  <button class="icon-btn" id="sidebarToggle" type="button" title="Toggle sidebar" aria-label="Toggle sidebar"><i class="bi bi-layout-sidebar-inset"></i></button>
  <h1><?= e($pageTitle) ?></h1>
  <span class="badge text-bg-primary d-none d-md-inline-block">Super Admin</span>
  <div class="search-wrap d-none d-md-flex"><i class="bi bi-search"></i><input class="form-control" id="globalSearch" type="search" placeholder="Search platform menu…" aria-label="Search platform menu"></div>
  <span class="ms-auto"></span>
  <button class="icon-btn" id="themeToggle" type="button" title="Toggle light / dark" aria-label="Toggle theme"><i class="bi bi-moon-stars"></i></button>
  <div class="dropdown">
    <button class="profile-chip" type="button" data-bs-toggle="dropdown" aria-label="Profile menu">
      <span class="avatar-initials"><?= e(initials($saName)) ?></span>
      <span class="d-none d-lg-block text-start"><strong class="d-block lh-1 small"><?= e($saName) ?></strong><small class="text-muted">Super Admin · Platform</small></span>
    </button>
    <ul class="dropdown-menu dropdown-menu-end">
      <li><span class="dropdown-item-text small text-muted"><?= e((string)($saUser['email'] ?? '')) ?></span></li>
      <li><hr class="dropdown-divider"></li>
      <li><a class="dropdown-item" href="settings.php"><i class="bi bi-gear me-2"></i>Platform Settings</a></li>
      <li><a class="dropdown-item" href="../index.php"><i class="bi bi-globe me-2"></i>View Site</a></li>
      <li><a class="dropdown-item text-danger" href="../auth/logout.php"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
    </ul>
  </div>
</header>
<div class="page-body">
<?php if ($m = flash('success')): ?><div class="alert alert-success alert-dismissible fade show"><?= e($m) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
<?php if ($m = flash('error')): ?><div class="alert alert-danger alert-dismissible fade show"><?= e($m) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
<?php if ($m = flash('info')): ?><div class="alert alert-info alert-dismissible fade show"><?= e($m) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
