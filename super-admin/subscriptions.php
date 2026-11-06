<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
$pdo = db();
$user = require_role(['super_admin']);

if (is_post()) {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    $subId = (int)($_POST['subscription_id'] ?? 0);
    if ($subId > 0) {
        $s = $pdo->prepare('SELECT s.*, t.name AS tenant_name FROM subscriptions s JOIN tenants t ON t.id = s.tenant_id WHERE s.id = ?');
        $s->execute([$subId]);
        $sub = $s->fetch();
        if (!$sub) {
            flash('error', 'Subscription not found.');
            redirect('subscriptions.php');
        }
        if ($action === 'activate') {
            $pdo->prepare("UPDATE subscriptions SET status = 'active', starts_at = COALESCE(starts_at, CURDATE()) WHERE id = ?")->execute([$subId]);
            audit_log($pdo, 'subscription_activated', 'subscriptions', $subId, 'Subscription activated for ' . $sub['tenant_name']);
            flash('success', 'Subscription activated.');
        } elseif ($action === 'cancel') {
            $pdo->prepare("UPDATE subscriptions SET status = 'cancelled', cancelled_at = NOW() WHERE id = ?")->execute([$subId]);
            audit_log($pdo, 'subscription_cancelled', 'subscriptions', $subId, 'Subscription cancelled for ' . $sub['tenant_name']);
            flash('success', 'Subscription cancelled.');
        } elseif ($action === 'expire') {
            $pdo->prepare("UPDATE subscriptions SET status = 'expired' WHERE id = ?")->execute([$subId]);
            audit_log($pdo, 'subscription_expired', 'subscriptions', $subId, 'Subscription marked expired for ' . $sub['tenant_name']);
            flash('success', 'Subscription marked expired.');
        } elseif ($action === 'change_plan') {
            $newPlanId = (int)($_POST['plan_id'] ?? 0);
            $p = $pdo->prepare('SELECT name FROM subscription_plans WHERE id = ?');
            $p->execute([$newPlanId]);
            $planName = $p->fetchColumn();
            if ($planName === false) {
                flash('error', 'Please choose a valid plan.');
            } else {
                $pdo->prepare('UPDATE subscriptions SET plan_id = ? WHERE id = ?')->execute([$newPlanId, $subId]);
                audit_log($pdo, 'subscription_plan_changed', 'subscriptions', $subId, 'Plan changed to ' . $planName . ' for ' . $sub['tenant_name']);
                flash('success', 'Plan changed to ' . $planName . '.');
            }
        }
    }
    redirect('subscriptions.php');
}

$q = trim((string)($_GET['q'] ?? ''));
$statusFilter = (string)($_GET['status'] ?? '');
$planFilter = (int)($_GET['plan_id'] ?? 0);
$where = [];
$params = [];
if ($q !== '') {
    $where[] = '(t.name LIKE ? OR t.slug LIKE ?)';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}
if (in_array($statusFilter, ['trial', 'active', 'past_due', 'cancelled', 'expired'], true)) {
    $where[] = 's.status = ?';
    $params[] = $statusFilter;
}
if ($planFilter > 0) {
    $where[] = 's.plan_id = ?';
    $params[] = $planFilter;
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$countStmt = $pdo->prepare('SELECT COUNT(*) FROM subscriptions s JOIN tenants t ON t.id = s.tenant_id' . $whereSql);
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
[$perPage, $offset, $page] = page_params(15);
$listStmt = $pdo->prepare(
    'SELECT s.*, t.name AS tenant_name, t.slug AS tenant_slug, p.name AS plan_name
       FROM subscriptions s
       JOIN tenants t ON t.id = s.tenant_id
       JOIN subscription_plans p ON p.id = s.plan_id' . $whereSql . ' ORDER BY s.id DESC LIMIT ? OFFSET ?'
);
foreach ($params as $i => $v) {
    $listStmt->bindValue($i + 1, $v);
}
$listStmt->bindValue(count($params) + 1, $perPage, PDO::PARAM_INT);
$listStmt->bindValue(count($params) + 2, $offset, PDO::PARAM_INT);
$listStmt->execute();
$subs = $listStmt->fetchAll();
$allPlans = $pdo->query('SELECT id, name FROM subscription_plans ORDER BY sort_order, id')->fetchAll();

$pageTitle = 'Subscriptions';
include __DIR__ . '/sa-header.php';
?>
<div class="card">
  <div class="card-header d-flex flex-wrap gap-2 justify-content-between align-items-center">
    <span>All subscriptions (<?= $total ?>)</span>
    <form method="get" class="d-flex gap-2 flex-wrap">
      <input class="form-control form-control-sm" type="search" name="q" value="<?= e($q) ?>" placeholder="Search tenant…">
      <select class="form-select form-select-sm" name="status" style="width:auto">
        <option value="">All statuses</option>
        <?php foreach (['trial', 'active', 'past_due', 'cancelled', 'expired'] as $st): ?><option value="<?= e($st) ?>" <?= $statusFilter === $st ? 'selected' : '' ?>><?= e(ucwords(str_replace('_', ' ', $st))) ?></option><?php endforeach; ?>
      </select>
      <select class="form-select form-select-sm" name="plan_id" style="width:auto">
        <option value="0">All plans</option>
        <?php foreach ($allPlans as $pl): ?><option value="<?= (int)$pl['id'] ?>" <?= $planFilter === (int)$pl['id'] ? 'selected' : '' ?>><?= e($pl['name']) ?></option><?php endforeach; ?>
      </select>
      <button class="btn btn-sm btn-primary" type="submit">Filter</button>
    </form>
  </div>
  <div class="table-responsive">
    <table class="table table-hover">
      <thead><tr><th>Tenant</th><th>Plan</th><th>Cycle</th><th>Status</th><th>Period end</th><th>Trial end</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
      <?php if (!$subs): ?><tr><td colspan="7" class="text-muted text-center py-4">No subscriptions match your filters.</td></tr><?php endif; ?>
      <?php foreach ($subs as $sb): ?>
        <tr>
          <td><strong><?= e($sb['tenant_name']) ?></strong><br><small class="text-muted"><?= e($sb['tenant_slug']) ?> · since <?= fmt_date($sb['starts_at'] ?? $sb['created_at']) ?></small></td>
          <td><span class="chip"><?= e($sb['plan_name']) ?></span></td>
          <td class="text-capitalize"><?= e($sb['billing_cycle']) ?></td>
          <td><?= status_badge((string)$sb['status']) ?></td>
          <td><?= fmt_date($sb['ends_at']) ?></td>
          <td><?= fmt_date($sb['trial_ends_at']) ?></td>
          <td class="text-end">
            <div class="d-flex gap-1 justify-content-end flex-wrap">
              <?php if ($sb['status'] !== 'active'): ?>
              <form method="post">
                <?= csrf_field() ?><input type="hidden" name="action" value="activate"><input type="hidden" name="subscription_id" value="<?= (int)$sb['id'] ?>">
                <button class="btn btn-sm btn-outline-success" type="submit">Activate</button>
              </form>
              <?php endif; ?>
              <?php if ($sb['status'] !== 'cancelled'): ?>
              <form method="post" data-confirm="Cancel this subscription?">
                <?= csrf_field() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="subscription_id" value="<?= (int)$sb['id'] ?>">
                <button class="btn btn-sm btn-outline-danger" type="submit">Cancel</button>
              </form>
              <?php endif; ?>
              <?php if ($sb['status'] !== 'expired'): ?>
              <form method="post">
                <?= csrf_field() ?><input type="hidden" name="action" value="expire"><input type="hidden" name="subscription_id" value="<?= (int)$sb['id'] ?>">
                <button class="btn btn-sm btn-outline-secondary" type="submit">Mark expired</button>
              </form>
              <?php endif; ?>
              <form method="post" class="d-flex gap-1">
                <?= csrf_field() ?><input type="hidden" name="action" value="change_plan"><input type="hidden" name="subscription_id" value="<?= (int)$sb['id'] ?>">
                <select class="form-select form-select-sm" name="plan_id" style="width:auto">
                  <?php foreach ($allPlans as $pl): ?><option value="<?= (int)$pl['id'] ?>" <?= (int)$sb['plan_id'] === (int)$pl['id'] ? 'selected' : '' ?>><?= e($pl['name']) ?></option><?php endforeach; ?>
                </select>
                <button class="btn btn-sm btn-outline-primary" type="submit">Change plan</button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($total > $perPage): ?><div class="card-body d-flex justify-content-end"><?= pager_html($total, $perPage, $page) ?></div><?php endif; ?>
</div>
<?php include __DIR__ . '/sa-footer.php'; ?>
