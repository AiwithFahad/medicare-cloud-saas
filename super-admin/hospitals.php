<?php
declare(strict_types=1);
/* Super Admin — Hospitals (Upgrade v2 page tree).
 * Registration approvals + lifecycle: new hospitals register as 'pending'
 * (auth/register.php) and are approved here; active hospitals can be
 * suspended and reactivated. Usage is measured live against plan limits.
 * Full tenant detail (users, subscriptions, invoices) stays in tenants.php. */
require_once __DIR__ . '/../includes/auth.php';
$pdo = db();
$user = require_role(['super_admin']);

$notifyTenantAdmins = static function (PDO $pdo, int $tenantId, string $title, string $body, string $type): void {
    $s = $pdo->prepare('SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE u.tenant_id = ? AND r.slug = "hospital_admin"');
    $s->execute([$tenantId]);
    $ids = $s->fetchAll(PDO::FETCH_COLUMN);
    if (!$ids) {
        notify_user($pdo, $tenantId, null, $title, $body, $type);
        return;
    }
    foreach ($ids as $aid) {
        notify_user($pdo, $tenantId, (int)$aid, $title, $body, $type);
    }
};

if (is_post()) {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    $tenantId = (int)($_POST['tenant_id'] ?? 0);
    if (in_array($action, ['approve', 'suspend', 'reactivate'], true) && $tenantId > 0) {
        $s = $pdo->prepare('SELECT name, status FROM tenants WHERE id = ?');
        $s->execute([$tenantId]);
        $t = $s->fetch();
        if (!$t) {
            flash('error', 'Hospital not found.');
        } elseif ($action === 'approve' && $t['status'] === 'pending') {
            $pdo->prepare("UPDATE tenants SET status = 'active' WHERE id = ?")->execute([$tenantId]);
            audit_log($pdo, 'tenant_approved', 'tenants', $tenantId, 'Hospital registration approved: ' . $t['name']);
            log_activity($pdo, 'Hospital approved: ' . $t['name']);
            $notifyTenantAdmins($pdo, $tenantId, 'Workspace approved', 'Good news — your ' . APP_NAME . ' workspace has been approved and your free trial is active. You can sign in now.', 'success');
            flash('success', 'Hospital approved and activated.');
        } elseif ($action === 'suspend' && $t['status'] === 'active') {
            $pdo->prepare("UPDATE tenants SET status = 'suspended' WHERE id = ?")->execute([$tenantId]);
            audit_log($pdo, 'tenant_suspended', 'tenants', $tenantId, 'Hospital suspended: ' . $t['name']);
            $notifyTenantAdmins($pdo, $tenantId, 'Account suspended', 'Your ' . APP_NAME . ' account has been suspended by the platform team. Please contact support.', 'warning');
            flash('success', 'Hospital suspended.');
        } elseif ($action === 'reactivate' && $t['status'] === 'suspended') {
            $pdo->prepare("UPDATE tenants SET status = 'active' WHERE id = ?")->execute([$tenantId]);
            audit_log($pdo, 'tenant_reactivated', 'tenants', $tenantId, 'Hospital reactivated: ' . $t['name']);
            $notifyTenantAdmins($pdo, $tenantId, 'Account reactivated', 'Your ' . APP_NAME . ' account has been reactivated. Welcome back.', 'success');
            flash('success', 'Hospital reactivated.');
        } else {
            flash('error', 'That action is not valid for a hospital in "' . $t['status'] . '" status.');
        }
    }
    redirect('hospitals.php' . (!empty($_POST['back_status']) ? '?status=' . urlencode((string)$_POST['back_status']) : ''));
}

$statusFilter = (string)($_GET['status'] ?? '');
$q = trim((string)($_GET['q'] ?? ''));
[$perPage, $offset, $page] = page_params(15);

$where = '1=1';
$params = [];
if (in_array($statusFilter, ['pending', 'active', 'suspended'], true)) {
    $where .= ' AND t.status = ?';
    $params[] = $statusFilter;
}
if ($q !== '') {
    $where .= ' AND (t.name LIKE ? OR t.email LIKE ? OR t.slug LIKE ?)';
    array_push($params, '%' . $q . '%', '%' . $q . '%', '%' . $q . '%');
}
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM tenants t WHERE {$where}");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage));

$stmt = $pdo->prepare(
    "SELECT t.*,
            (SELECT COUNT(*) FROM users u WHERE u.tenant_id = t.id) AS user_count,
            (SELECT p.name FROM subscriptions s JOIN subscription_plans p ON p.id = s.plan_id WHERE s.tenant_id = t.id ORDER BY s.id DESC LIMIT 1) AS plan_name,
            (SELECT s.status FROM subscriptions s WHERE s.tenant_id = t.id ORDER BY s.id DESC LIMIT 1) AS sub_status,
            (SELECT s.ends_at FROM subscriptions s WHERE s.tenant_id = t.id ORDER BY s.id DESC LIMIT 1) AS sub_ends_at,
            (SELECT s.trial_ends_at FROM subscriptions s WHERE s.tenant_id = t.id ORDER BY s.id DESC LIMIT 1) AS trial_ends_at
       FROM tenants t WHERE {$where}
       ORDER BY FIELD(t.status, 'pending', 'active', 'suspended'), t.id DESC
       LIMIT {$perPage} OFFSET {$offset}"
);
$stmt->execute($params);
$tenants = $stmt->fetchAll();

$counts = [];
foreach (['pending', 'active', 'suspended'] as $st) {
    $cs = $pdo->prepare('SELECT COUNT(*) FROM tenants WHERE status = ?');
    $cs->execute([$st]);
    $counts[$st] = (int)$cs->fetchColumn();
}

$pageTitle = 'Hospitals';
include __DIR__ . '/sa-header.php';
?>
<div class="row g-3 mb-3">
  <div class="col-6 col-xl-3"><div class="card stat-card h-100"><span class="stat-ic ic-amber"><i class="bi bi-hourglass-split"></i></span><span><span class="num d-block"><?= $counts['pending'] ?></span><span class="lbl">Awaiting approval</span></span></div></div>
  <div class="col-6 col-xl-3"><div class="card stat-card h-100"><span class="stat-ic ic-green"><i class="bi bi-building-check"></i></span><span><span class="num d-block"><?= $counts['active'] ?></span><span class="lbl">Active hospitals</span></span></div></div>
  <div class="col-6 col-xl-3"><div class="card stat-card h-100"><span class="stat-ic ic-rose"><i class="bi bi-building-slash"></i></span><span><span class="num d-block"><?= $counts['suspended'] ?></span><span class="lbl">Suspended</span></span></div></div>
  <div class="col-6 col-xl-3"><div class="card stat-card h-100"><span class="stat-ic ic-blue"><i class="bi bi-buildings"></i></span><span><span class="num d-block"><?= array_sum($counts) ?></span><span class="lbl">Total hospitals</span></span></div></div>
</div>

<div class="card">
  <div class="card-header">
    <form class="row g-2 align-items-center" method="get">
      <div class="col-md-4"><input class="form-control" type="search" name="q" value="<?= e($q) ?>" placeholder="Search hospitals…" aria-label="Search hospitals"></div>
      <div class="col-md-3">
        <select class="form-select" name="status" aria-label="Filter by status">
          <option value="">All statuses</option>
          <?php foreach (['pending', 'active', 'suspended'] as $st): ?><option value="<?= $st ?>" <?= $statusFilter === $st ? 'selected' : '' ?>><?= ucfirst($st) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2"><button class="btn btn-primary w-100" type="submit"><i class="bi bi-funnel"></i> Filter</button></div>
      <div class="col-md-3 text-md-end"><span class="text-muted small"><?= $total ?> hospital<?= $total === 1 ? '' : 's' ?></span></div>
    </form>
  </div>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead><tr><th>Hospital</th><th>Plan / Subscription</th><th>Usage (patients · doctors · staff · depts)</th><th>Users</th><th>Status</th><th>Joined</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
      <?php if (!$tenants): ?>
        <tr><td colspan="7"><div class="empty-state py-4"><i class="bi bi-buildings"></i><p class="mb-0">No hospitals match this filter.</p></div></td></tr>
      <?php endif; ?>
      <?php foreach ($tenants as $t):
          $usage = usage_counts($pdo, (int)$t['id']);
          $sub = active_subscription($pdo, (int)$t['id']);
          $limitTxt = static function (?int $limit): string { return $limit === null || $limit < 0 ? '∞' : (string)$limit; };
      ?>
        <tr>
          <td>
            <div class="d-flex align-items-center gap-2">
              <span class="avatar-initials"><?= e(initials($t['name'])) ?></span>
              <span><strong class="d-block"><?= e($t['name']) ?></strong><small class="text-muted"><?= e($t['slug']) ?> · <?= e($t['email']) ?></small></span>
            </div>
          </td>
          <td>
            <?= $t['plan_name'] ? '<span class="chip">' . e($t['plan_name']) . '</span>' : '<span class="text-muted">—</span>' ?>
            <?php if ($t['sub_status']): ?><small class="d-block text-muted"><?= e(ucfirst((string)$t['sub_status'])) ?><?= $t['sub_status'] === 'trial' && $t['trial_ends_at'] ? ' · ends ' . fmt_date($t['trial_ends_at']) : ($t['sub_ends_at'] ? ' · renews ' . fmt_date($t['sub_ends_at']) : '') ?></small><?php endif; ?>
          </td>
          <td>
            <?php if ($sub): ?>
              <small class="d-block">Patients <?= (int)$usage['patients'] ?> / <?= $limitTxt(isset($sub['max_patients']) ? (int)$sub['max_patients'] : null) ?> · Doctors <?= (int)$usage['doctors'] ?> / <?= $limitTxt(isset($sub['max_doctors']) ? (int)$sub['max_doctors'] : null) ?></small>
              <small class="d-block text-muted">Staff <?= (int)$usage['staff'] ?> / <?= $limitTxt(isset($sub['max_staff']) ? (int)$sub['max_staff'] : null) ?> · Depts <?= (int)$usage['departments'] ?> / <?= $limitTxt(isset($sub['max_departments']) ? (int)$sub['max_departments'] : null) ?></small>
            <?php else: ?><small class="text-muted">No subscription</small><?php endif; ?>
          </td>
          <td><?= (int)$t['user_count'] ?></td>
          <td><?= status_badge((string)$t['status']) ?></td>
          <td><?= fmt_date($t['created_at']) ?></td>
          <td class="text-end">
            <div class="d-inline-flex gap-1">
              <a class="btn btn-sm btn-outline-primary" href="tenants.php?view=<?= (int)$t['id'] ?>" title="Full tenant detail"><i class="bi bi-eye"></i></a>
              <?php if ($t['status'] === 'pending'): ?>
                <form method="post" class="d-inline" data-confirm="Approve <?= e($t['name']) ?>? Their admin will be able to sign in immediately.">
                  <?= csrf_field() ?><input type="hidden" name="action" value="approve"><input type="hidden" name="tenant_id" value="<?= (int)$t['id'] ?>"><input type="hidden" name="back_status" value="<?= e($statusFilter) ?>">
                  <button class="btn btn-sm btn-success" type="submit"><i class="bi bi-check-lg"></i> Approve</button>
                </form>
              <?php elseif ($t['status'] === 'active'): ?>
                <form method="post" class="d-inline" data-confirm="Suspend <?= e($t['name']) ?>? All of their users will be blocked from signing in.">
                  <?= csrf_field() ?><input type="hidden" name="action" value="suspend"><input type="hidden" name="tenant_id" value="<?= (int)$t['id'] ?>"><input type="hidden" name="back_status" value="<?= e($statusFilter) ?>">
                  <button class="btn btn-sm btn-outline-danger" type="submit"><i class="bi bi-pause-circle"></i> Suspend</button>
                </form>
              <?php else: ?>
                <form method="post" class="d-inline">
                  <?= csrf_field() ?><input type="hidden" name="action" value="reactivate"><input type="hidden" name="tenant_id" value="<?= (int)$t['id'] ?>"><input type="hidden" name="back_status" value="<?= e($statusFilter) ?>">
                  <button class="btn btn-sm btn-outline-success" type="submit"><i class="bi bi-play-circle"></i> Reactivate</button>
                </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pages > 1): ?>
  <div class="card-body d-flex justify-content-between align-items-center">
    <span class="text-muted small">Page <?= $page ?> of <?= $pages ?></span>
    <?= pager_html($total, $perPage, $page) ?>
  </div>
  <?php endif; ?>
</div>
<?php include __DIR__ . '/sa-footer.php'; ?>
