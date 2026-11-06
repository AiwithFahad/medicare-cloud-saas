<?php
declare(strict_types=1);
/* Upgrade v2: the platform dashboard moved to dashboard.php (separate role
 * page tree). This file is kept as a redirect so old links/bookmarks and the
 * pre-upgrade login flow keep working. */
require_once __DIR__ . '/../includes/auth.php';
require_role(['super_admin']);
redirect('dashboard.php');
