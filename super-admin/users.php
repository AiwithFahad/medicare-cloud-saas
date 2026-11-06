<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
$pdo = db();
$user = require_role(['super_admin']);

if (is_post()) {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    $targetId = (int)($_POST['user_id'] ?? 0);
    if (in_array($action, ['activate', 'deactivate'], true) && $targetId > 0) {
        if ($targetId === (int)$user['id']) {
            flash('error', 'You cannot change the status of your own account.');
        } else {
            $s = $pdo->prepare('SELECT name FROM users WHERE id = ?');
            $s->execute([$targetId]);
            $tName = $s->fetchColumn();
            if ($tName === false) {
                flash('error', 'User not found.');
            } else {
                $newStatus = $action === 'activate' ? 'active' : 'inactive';
                $pdo->prepare('UPDATE users SET status = ? WHERE id = ?')->execute([$newStatus, $targetId]);
                audit_log($pdo, 'user_' . $action . 'd', 'users', $targetId, 'User ' . $action . 'd: ' . $tName);
                flash('success', 'User ' . $action . 'd.');
            }
        }
    }
    redirect('users.php');
}

$q = trim((string)($_GET['q'] ?? ''));
$roleFilter = (string)($_GET['role'] ?? '');
$where = [];
$params = [];
if ($q !== '') {
    $where[] = '(u.name LIKE ? OR u.email LIKE ?)';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}
if ($roleFilter !== '') {
    $where[] = 'r.slug = ?';
    $params[] = $roleFilter;
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$countStmt = $pdo->prepare('SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id' . $whereSql);
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
[$perPage, $offset, $page] = page_params(15);
$listStmt = $pdo->prepare(
    'SELECT u.*, r.name AS role_name, r.slug AS role_slug, t.name AS tenant_name
       FROM users u
       JOIN roles r ON r.id = u.role_id
       LEFT JOIN tenants t ON t.id = u.tenant_id' . $whereSql . ' ORDER BY u.id DESC LIMIT ? OFFSET ?'
);
foreach ($params as $i => $v) {
    $listStmt->bindValue($i + 1, $v);
}
$listStmt->bindValue(count($params) + 1, $perPage, PDO::PARAM_INT);
$listStmt->bindValue(count($params) + 2, $offset, PDO::PARAM_INT);
$listStmt->execute();
$users = $listStmt->fetchAll();
$roles = $pdo->query('SELECT slug, name FROM roles ORDER BY id')->fetchAll();

$pageTitle = 'Users';
include __DIR__ . '/sa-header.php';
?>
<div class="card">
  <div class="card-header d-flex flex-wrap gap-2 justify-content-between align-items-center">
    <span>All users across the platform (<?= $total ?>)</span>
    <form method="get" class="d-flex gap-2">
      <input class="form-control form-control-sm" type="search" name="q" value="<?= e($q) ?>" placeholder="Search name or email…">
      <select class="form-select form-select-sm" name="role" style="width:auto">
        <option value="">All roles</option>
        <?php foreach ($roles as $r): ?><option value="<?= e($r['slug']) ?>" <?= $roleFilter === $r['slug'] ? 'selected' : '' ?>><?= e($r['name']) ?></option><?php endforeach; ?>
      </select>
      <button class="btn btn-sm btn-primary" type="submit">Filter</button>
    </form>
  </div>
  <div class="table-responsive">
    <table class="table table-hover">
      <thead><tr><th>User</th><th>Tenant</th><th>Role</th><th>Status</th><th>Last login</th><th>Joined</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
      <?php if (!$users): ?><tr><td colspan="7" class="text-muted text-center py-4">No users match your filters.</td></tr><?php endif; ?>
      <?php foreach ($users as $u): ?>
        <tr>
          <td><span class="avatar-sm me-1"><?= e(initials($u['name'])) ?></span><strong><?= e($u['name']) ?></strong><br><small class="text-muted ms-4 ps-2"><?= e($u['email']) ?></small></td>
          <td><?= $u['tenant_id'] === null ? '<span class="chip">Platform</span>' : e($u['tenant_name'] ?? '—') ?></td>
          <td><?= e($u['role_name']) ?></td>
          <td><?= status_badge((string)$u['status']) ?></td>
          <td class="small"><?= fmt_datetime($u['last_login_at']) ?></td>
          <td class="small"><?= fmt_date($u['created_at']) ?></td>
          <td class="text-end text-nowrap">
            <?php if ((int)$u['id'] === (int)$user['id']): ?>
              <span class="text-muted small">You</span>
            <?php elseif ($u['status'] === 'active'): ?>
              <form method="post" class="d-inline" data-confirm="Deactivate this user? They will no longer be able to sign in.">
                <?= csrf_field() ?><input type="hidden" name="action" value="deactivate"><input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                <button class="btn btn-sm btn-outline-danger" type="submit">Deactivate</button>
              </form>
            <?php else: ?>
              <form method="post" class="d-inline">
                <?= csrf_field() ?><input type="hidden" name="action" value="activate"><input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                <button class="btn btn-sm btn-outline-success" type="submit">Activate</button>
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
