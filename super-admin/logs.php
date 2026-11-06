<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
$pdo = db();
$user = require_role(['super_admin']);

$tab = (string)($_GET['tab'] ?? 'activity');
if (!in_array($tab, ['activity', 'audit'], true)) {
    $tab = 'activity';
}
$tenantFilter = (int)($_GET['tenant_id'] ?? 0);
$tenants = $pdo->query('SELECT id, name FROM tenants ORDER BY name')->fetchAll();

$tenantWhere = '';
$tenantParams = [];
if ($tenantFilter > 0) {
    $tenantWhere = ' WHERE a.tenant_id = ?';
    $tenantParams[] = $tenantFilter;
}

$activityLogs = [];
$auditLogs = [];
if ($tab === 'activity') {
    $stmt = $pdo->prepare(
        'SELECT a.*, t.name AS tenant_name, u.name AS user_name
           FROM activity_logs a
           LEFT JOIN tenants t ON t.id = a.tenant_id
           LEFT JOIN users u ON u.id = a.user_id' . $tenantWhere . ' ORDER BY a.id DESC LIMIT 100'
    );
    $stmt->execute($tenantParams);
    $activityLogs = $stmt->fetchAll();
} else {
    $stmt = $pdo->prepare(
        'SELECT a.*, t.name AS tenant_name, u.name AS user_name
           FROM audit_logs a
           LEFT JOIN tenants t ON t.id = a.tenant_id
           LEFT JOIN users u ON u.id = a.user_id' . $tenantWhere . ' ORDER BY a.id DESC LIMIT 100'
    );
    $stmt->execute($tenantParams);
    $auditLogs = $stmt->fetchAll();
}

$pageTitle = 'Logs';
include __DIR__ . '/sa-header.php';
?>
<div class="card">
  <div class="card-header d-flex flex-wrap gap-2 justify-content-between align-items-center">
    <ul class="nav nav-tabs card-header-tabs">
      <li class="nav-item"><a class="nav-link <?= $tab === 'activity' ? 'active' : '' ?>" href="logs.php?tab=activity<?= $tenantFilter ? '&tenant_id=' . $tenantFilter : '' ?>">Activity logs</a></li>
      <li class="nav-item"><a class="nav-link <?= $tab === 'audit' ? 'active' : '' ?>" href="logs.php?tab=audit<?= $tenantFilter ? '&tenant_id=' . $tenantFilter : '' ?>">Audit logs</a></li>
    </ul>
    <form method="get" class="d-flex gap-2">
      <input type="hidden" name="tab" value="<?= e($tab) ?>">
      <select class="form-select form-select-sm" name="tenant_id" style="width:auto">
        <option value="0">All tenants / platform</option>
        <?php foreach ($tenants as $t): ?><option value="<?= (int)$t['id'] ?>" <?= $tenantFilter === (int)$t['id'] ? 'selected' : '' ?>><?= e($t['name']) ?></option><?php endforeach; ?>
      </select>
      <button class="btn btn-sm btn-primary" type="submit">Filter</button>
    </form>
  </div>

  <?php if ($tab === 'activity'): ?>
  <div class="table-responsive">
    <table class="table table-hover">
      <thead><tr><th>When</th><th>Tenant</th><th>User</th><th>Activity</th><th>IP</th></tr></thead>
      <tbody>
      <?php if (!$activityLogs): ?><tr><td colspan="5" class="text-muted text-center py-4">No activity logs found.</td></tr><?php endif; ?>
      <?php foreach ($activityLogs as $a): ?>
        <tr>
          <td class="small text-nowrap"><?= fmt_datetime($a['created_at']) ?></td>
          <td><?= $a['tenant_name'] ? e($a['tenant_name']) : '<span class="chip">Platform</span>' ?></td>
          <td><?= e($a['user_name'] ?? '—') ?></td>
          <td><?= e($a['description']) ?></td>
          <td class="small text-muted"><?= e($a['ip_address'] ?? '—') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card-body"><small class="text-muted">Showing the latest <?= count($activityLogs) ?> activity entries (max 100).</small></div>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table table-hover">
      <thead><tr><th>When</th><th>Tenant</th><th>User</th><th>Action</th><th>Entity</th><th>Details</th><th>IP</th></tr></thead>
      <tbody>
      <?php if (!$auditLogs): ?><tr><td colspan="7" class="text-muted text-center py-4">No audit logs found.</td></tr><?php endif; ?>
      <?php foreach ($auditLogs as $a): ?>
        <tr>
          <td class="small text-nowrap"><?= fmt_datetime($a['created_at']) ?></td>
          <td><?= $a['tenant_name'] ? e($a['tenant_name']) : '<span class="chip">Platform</span>' ?></td>
          <td><?= e($a['user_name'] ?? '—') ?></td>
          <td><span class="chip"><?= e($a['action']) ?></span></td>
          <td class="small"><?= e($a['entity'] ?? '') ?><?= $a['entity_id'] ? ' #' . (int)$a['entity_id'] : '' ?></td>
          <td class="small text-muted"><?= e($a['details'] ?? '') ?></td>
          <td class="small text-muted"><?= e($a['ip_address'] ?? '—') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card-body"><small class="text-muted">Showing the latest <?= count($auditLogs) ?> audit entries (max 100).</small></div>
  <?php endif; ?>
</div>
<?php include __DIR__ . '/sa-footer.php'; ?>
