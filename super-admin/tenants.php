<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
$pdo = db();
$user = require_role(['super_admin']);

/* Notify every hospital_admin of a tenant (or the tenant generally when it
 * has no admin user yet) through the notifications table. */
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
    if (in_array($action, ['suspend', 'reactivate'], true) && $tenantId > 0) {
        $s = $pdo->prepare('SELECT name FROM tenants WHERE id = ?');
        $s->execute([$tenantId]);
        $tName = $s->fetchColumn();
        if ($tName === false) {
            flash('error', 'Tenant not found.');
        } elseif ($action === 'suspend') {
            $pdo->prepare("UPDATE tenants SET status = 'suspended' WHERE id = ?")->execute([$tenantId]);
            audit_log($pdo, 'tenant_suspended', 'tenants', $tenantId, 'Tenant suspended: ' . $tName);
            $notifyTenantAdmins($pdo, $tenantId, 'Account suspended', 'Your MediCare Cloud account has been suspended by the platform team. Please contact support.', 'warning');
            flash('success', 'Tenant suspended.');
        } else {
            $pdo->prepare("UPDATE tenants SET status = 'active' WHERE id = ?")->execute([$tenantId]);
            audit_log($pdo, 'tenant_reactivated', 'tenants', $tenantId, 'Tenant reactivated: ' . $tName);
            $notifyTenantAdmins($pdo, $tenantId, 'Account reactivated', 'Your MediCare Cloud account has been reactivated. Welcome back.', 'success');
            flash('success', 'Tenant reactivated.');
        }
    }
    redirect('tenants.php');
}

/* ---------------- Detail view ---------------- */
$viewId = isset($_GET['view']) ? (int)$_GET['view'] : 0;
if ($viewId > 0) {
    $s = $pdo->prepare('SELECT * FROM tenants WHERE id = ?');
    $s->execute([$viewId]);
    $tenant = $s->fetch();
    if (!$tenant) {
        flash('error', 'Tenant not found.');
        redirect('tenants.php');
    }
    $uStmt = $pdo->prepare('SELECT u.*, r.name AS role_name, r.slug AS role_slug FROM users u JOIN roles r ON r.id = u.role_id WHERE u.tenant_id = ? ORDER BY u.id');
    $uStmt->execute([$viewId]);
    $tUsers = $uStmt->fetchAll();
    $subStmt = $pdo->prepare('SELECT s.*, p.name AS plan_name FROM subscriptions s JOIN subscription_plans p ON p.id = s.plan_id WHERE s.tenant_id = ? ORDER BY s.id DESC');
    $subStmt->execute([$viewId]);
    $tSubs = $subStmt->fetchAll();
    $invStmt = $pdo->prepare('SELECT * FROM invoices WHERE tenant_id = ? ORDER BY id DESC');
    $invStmt->execute([$viewId]);
    $tInvoices = $invStmt->fetchAll();
    $currentSub = $tSubs[0] ?? null;

    $pageTitle = 'Tenant — ' . $tenant['name'];
    include __DIR__ . '/sa-header.php';
    ?>
    <p><a href="tenants.php" class="small"><i class="bi bi-arrow-left"></i> Back to tenants</a></p>
    <div class="row g-3">
      <div class="col-lg-4">
        <div class="card h-100">
          <div class="card-header">Tenant information</div>
          <div class="card-body">
            <div class="d-flex align-items-center gap-3 mb-3">
              <span class="avatar-initials" style="width:52px;height:52px;font-size:1.1rem"><?= e(initials($tenant['name'])) ?></span>
              <span><strong class="d-block"><?= e($tenant['name']) ?></strong><small class="text-muted"><?= e($tenant['slug']) ?></small></span>
              <span class="ms-auto"><?= status_badge((string)$tenant['status']) ?></span>
            </div>
            <dl class="row small mb-0">
              <dt class="col-4 text-muted">Email</dt><dd class="col-8"><?= e($tenant['email']) ?></dd>
              <dt class="col-4 text-muted">Phone</dt><dd class="col-8"><?= e($tenant['phone'] ?? '—') ?></dd>
              <dt class="col-4 text-muted">Address</dt><dd class="col-8"><?= e($tenant['address'] ?? '—') ?></dd>
              <dt class="col-4 text-muted">City</dt><dd class="col-8"><?= e($tenant['city'] ?? '—') ?></dd>
              <dt class="col-4 text-muted">Country</dt><dd class="col-8"><?= e($tenant['country'] ?? '—') ?></dd>
              <dt class="col-4 text-muted">Current plan</dt><dd class="col-8"><?= $currentSub ? e($currentSub['plan_name']) . ' (' . e($currentSub['status']) . ')' : '—' ?></dd>
              <dt class="col-4 text-muted">Users</dt><dd class="col-8"><?= count($tUsers) ?></dd>
              <dt class="col-4 text-muted">Created</dt><dd class="col-8"><?= fmt_datetime($tenant['created_at']) ?></dd>
            </dl>
            <hr>
            <?php if ($tenant['status'] === 'active'): ?>
              <form method="post" data-confirm="Suspend this tenant? Its users lose access immediately.">
                <?= csrf_field() ?><input type="hidden" name="action" value="suspend"><input type="hidden" name="tenant_id" value="<?= (int)$tenant['id'] ?>">
                <button class="btn btn-danger btn-sm" type="submit"><i class="bi bi-pause-circle"></i> Suspend tenant</button>
              </form>
            <?php else: ?>
              <form method="post">
                <?= csrf_field() ?><input type="hidden" name="action" value="reactivate"><input type="hidden" name="tenant_id" value="<?= (int)$tenant['id'] ?>">
                <button class="btn btn-success btn-sm" type="submit"><i class="bi bi-play-circle"></i> Reactivate tenant</button>
              </form>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <div class="col-lg-8">
        <div class="card mb-3">
          <div class="card-header">Users (<?= count($tUsers) ?>)</div>
          <div class="table-responsive"><table class="table table-hover">
            <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Last login</th></tr></thead>
            <tbody>
            <?php if (!$tUsers): ?><tr><td colspan="5" class="text-muted text-center py-3">No users.</td></tr><?php endif; ?>
            <?php foreach ($tUsers as $u): ?>
              <tr><td><span class="avatar-sm me-1"><?= e(initials($u['name'])) ?></span><?= e($u['name']) ?></td><td><?= e($u['email']) ?></td><td><?= e($u['role_name']) ?></td><td><?= status_badge((string)$u['status']) ?></td><td><?= fmt_datetime($u['last_login_at']) ?></td></tr>
            <?php endforeach; ?>
            </tbody></table></div>
        </div>
        <div class="card mb-3">
          <div class="card-header">Subscription history</div>
          <div class="table-responsive"><table class="table table-hover">
            <thead><tr><th>Plan</th><th>Cycle</th><th>Status</th><th>Starts</th><th>Ends</th><th>Trial ends</th></tr></thead>
            <tbody>
            <?php if (!$tSubs): ?><tr><td colspan="6" class="text-muted text-center py-3">No subscriptions.</td></tr><?php endif; ?>
            <?php foreach ($tSubs as $sb): ?>
              <tr><td><?= e($sb['plan_name']) ?></td><td class="text-capitalize"><?= e($sb['billing_cycle']) ?></td><td><?= status_badge((string)$sb['status']) ?></td><td><?= fmt_date($sb['starts_at']) ?></td><td><?= fmt_date($sb['ends_at']) ?></td><td><?= fmt_date($sb['trial_ends_at']) ?></td></tr>
            <?php endforeach; ?>
            </tbody></table></div>
        </div>
        <div class="card">
          <div class="card-header">Invoices</div>
          <div class="table-responsive"><table class="table table-hover">
            <thead><tr><th>Invoice</th><th>Total</th><th>Status</th><th>Due</th><th>Paid</th></tr></thead>
            <tbody>
            <?php if (!$tInvoices): ?><tr><td colspan="5" class="text-muted text-center py-3">No invoices.</td></tr><?php endif; ?>
            <?php foreach ($tInvoices as $inv): ?>
              <tr><td><?= e($inv['invoice_no']) ?></td><td><?= money($inv['total']) ?></td><td><?= status_badge((string)$inv['status']) ?></td><td><?= fmt_date($inv['due_date']) ?></td><td><?= fmt_datetime($inv['paid_at']) ?></td></tr>
            <?php endforeach; ?>
            </tbody></table></div>
        </div>
      </div>
    </div>
    <?php
    include __DIR__ . '/sa-footer.php';
    return;
}

/* ---------------- List ---------------- */
$q = trim((string)($_GET['q'] ?? ''));
$statusFilter = (string)($_GET['status'] ?? '');
$where = [];
$params = [];
if ($q !== '') {
    $where[] = '(t.name LIKE ? OR t.slug LIKE ? OR t.email LIKE ?)';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}
if (in_array($statusFilter, ['active', 'suspended'], true)) {
    $where[] = 't.status = ?';
    $params[] = $statusFilter;
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$countStmt = $pdo->prepare('SELECT COUNT(*) FROM tenants t' . $whereSql);
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
[$perPage, $offset, $page] = page_params(15);
$listStmt = $pdo->prepare(
    'SELECT t.*,
            (SELECT COUNT(*) FROM users u WHERE u.tenant_id = t.id) AS user_count,
            (SELECT p.name FROM subscriptions s JOIN subscription_plans p ON p.id = s.plan_id
              WHERE s.tenant_id = t.id ORDER BY s.id DESC LIMIT 1) AS plan_name,
            (SELECT s.status FROM subscriptions s WHERE s.tenant_id = t.id ORDER BY s.id DESC LIMIT 1) AS sub_status
       FROM tenants t' . $whereSql . ' ORDER BY t.id DESC LIMIT ? OFFSET ?'
);
foreach ($params as $i => $v) {
    $listStmt->bindValue($i + 1, $v);
}
$listStmt->bindValue(count($params) + 1, $perPage, PDO::PARAM_INT);
$listStmt->bindValue(count($params) + 2, $offset, PDO::PARAM_INT);
$listStmt->execute();
$tenants = $listStmt->fetchAll();

$pageTitle = 'Tenants';
include __DIR__ . '/sa-header.php';
?>
<div class="card">
  <div class="card-header d-flex flex-wrap gap-2 justify-content-between align-items-center">
    <span>All tenants (<?= $total ?>)</span>
    <form method="get" class="d-flex gap-2">
      <input class="form-control form-control-sm" type="search" name="q" value="<?= e($q) ?>" placeholder="Search name, slug, email…">
      <select class="form-select form-select-sm" name="status" style="width:auto">
        <option value="">All statuses</option>
        <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
        <option value="suspended" <?= $statusFilter === 'suspended' ? 'selected' : '' ?>>Suspended</option>
      </select>
      <button class="btn btn-sm btn-primary" type="submit">Filter</button>
    </form>
  </div>
  <div class="table-responsive">
    <table class="table table-hover">
      <thead><tr><th>Tenant</th><th>Plan</th><th>Users</th><th>Status</th><th>Created</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
      <?php if (!$tenants): ?><tr><td colspan="6" class="text-muted text-center py-4">No tenants match your filters.</td></tr><?php endif; ?>
      <?php foreach ($tenants as $t): ?>
        <tr>
          <td><span class="avatar-sm me-1"><?= e(initials($t['name'])) ?></span><strong><?= e($t['name']) ?></strong><br><small class="text-muted ms-4 ps-2"><?= e($t['slug']) ?> · <?= e($t['email']) ?></small></td>
          <td><?= $t['plan_name'] ? '<span class="chip">' . e($t['plan_name']) . '</span>' . ($t['sub_status'] ? ' <small class="text-muted">' . e($t['sub_status']) . '</small>' : '') : '<span class="text-muted">—</span>' ?></td>
          <td><?= (int)$t['user_count'] ?></td>
          <td><?= status_badge((string)$t['status']) ?></td>
          <td><?= fmt_date($t['created_at']) ?></td>
          <td class="text-end text-nowrap">
            <a class="btn btn-sm btn-outline-primary" href="tenants.php?view=<?= (int)$t['id'] ?>"><i class="bi bi-eye"></i> View</a>
            <?php if ($t['status'] === 'active'): ?>
              <form method="post" class="d-inline" data-confirm="Suspend this tenant? Its users lose access immediately.">
                <?= csrf_field() ?><input type="hidden" name="action" value="suspend"><input type="hidden" name="tenant_id" value="<?= (int)$t['id'] ?>">
                <button class="btn btn-sm btn-outline-danger" type="submit">Suspend</button>
              </form>
            <?php else: ?>
              <form method="post" class="d-inline">
                <?= csrf_field() ?><input type="hidden" name="action" value="reactivate"><input type="hidden" name="tenant_id" value="<?= (int)$t['id'] ?>">
                <button class="btn btn-sm btn-outline-success" type="submit">Reactivate</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($total > $perPage): ?><div class="card-body d-flex justify-content-end"><?= pager_html($total, $perPage, $page) ?></div><?php endif; ?>
</div>
<?php include __DIR__ . '/sa-footer.php'; ?>
