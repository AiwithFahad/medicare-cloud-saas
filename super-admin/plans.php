<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
$pdo = db();
$user = require_role(['super_admin']);

if (is_post()) {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    $planId = (int)($_POST['plan_id'] ?? 0);

    if ($action === 'save') {
        $name = trim((string)($_POST['name'] ?? ''));
        $slug = slugify((string)($_POST['slug'] ?? '') !== '' ? (string)$_POST['slug'] : $name);
        $featuresText = (string)($_POST['features'] ?? '');
        $features = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $featuresText) ?: []), static fn($l) => $l !== ''));
        $data = [
            $name,
            $slug,
            max(0, (float)($_POST['monthly_price'] ?? 0)),
            max(0, (float)($_POST['yearly_price'] ?? 0)),
            max(0, (int)($_POST['trial_days'] ?? 14)),
            (int)($_POST['max_doctors'] ?? 0),
            (int)($_POST['max_staff'] ?? 0),
            (int)($_POST['max_patients'] ?? 0),
            (int)($_POST['max_departments'] ?? 0),
            max(0, (int)($_POST['max_storage_mb'] ?? 0)),
            json_encode($features, JSON_UNESCAPED_UNICODE),
            isset($_POST['is_active']) ? 1 : 0,
            (int)($_POST['sort_order'] ?? 0),
        ];
        if ($name === '') {
            flash('error', 'Plan name is required.');
            redirect('plans.php');
        }
        try {
            if ($planId > 0) {
                $data[] = $planId;
                $pdo->prepare('UPDATE subscription_plans SET name=?, slug=?, monthly_price=?, yearly_price=?, trial_days=?, max_doctors=?, max_staff=?, max_patients=?, max_departments=?, max_storage_mb=?, features=?, is_active=?, sort_order=? WHERE id=?')->execute($data);
                audit_log($pdo, 'plan_updated', 'subscription_plans', $planId, 'Plan updated: ' . $name);
                flash('success', 'Plan updated.');
            } else {
                $pdo->prepare('INSERT INTO subscription_plans (name, slug, monthly_price, yearly_price, trial_days, max_doctors, max_staff, max_patients, max_departments, max_storage_mb, features, is_active, sort_order) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute($data);
                audit_log($pdo, 'plan_created', 'subscription_plans', (int)$pdo->lastInsertId(), 'Plan created: ' . $name);
                flash('success', 'Plan created.');
            }
        } catch (PDOException $ex) {
            flash('error', 'Could not save the plan — the slug may already be in use.');
        }
    } elseif ($action === 'toggle' && $planId > 0) {
        $pdo->prepare('UPDATE subscription_plans SET is_active = 1 - is_active WHERE id = ?')->execute([$planId]);
        audit_log($pdo, 'plan_toggled', 'subscription_plans', $planId, 'Plan active flag toggled');
        flash('success', 'Plan status changed.');
    } elseif ($action === 'delete' && $planId > 0) {
        $c = $pdo->prepare('SELECT COUNT(*) FROM subscriptions WHERE plan_id = ?');
        $c->execute([$planId]);
        if ((int)$c->fetchColumn() > 0) {
            /* Guarded delete: plans in use are deactivated, never removed. */
            $pdo->prepare('UPDATE subscription_plans SET is_active = 0 WHERE id = ?')->execute([$planId]);
            audit_log($pdo, 'plan_deactivated', 'subscription_plans', $planId, 'Delete blocked — subscriptions exist; plan deactivated instead');
            flash('error', 'This plan has subscriptions, so it cannot be deleted. It has been deactivated instead.');
        } else {
            $pdo->prepare('DELETE FROM subscription_plans WHERE id = ?')->execute([$planId]);
            audit_log($pdo, 'plan_deleted', 'subscription_plans', $planId, 'Plan deleted');
            flash('success', 'Plan deleted.');
        }
    }
    redirect('plans.php');
}

$plans = $pdo->query(
    'SELECT p.*, (SELECT COUNT(*) FROM subscriptions s WHERE s.plan_id = p.id) AS sub_count
       FROM subscription_plans p ORDER BY p.sort_order, p.id'
)->fetchAll();

$edit = null;
if (isset($_GET['edit'])) {
    $s = $pdo->prepare('SELECT * FROM subscription_plans WHERE id = ?');
    $s->execute([(int)$_GET['edit']]);
    $edit = $s->fetch() ?: null;
}
$editFeatures = '';
if ($edit) {
    $decoded = json_decode((string)($edit['features'] ?? ''), true);
    $editFeatures = is_array($decoded) ? implode("\n", $decoded) : (string)($edit['features'] ?? '');
}
$limitLabel = static fn($v) => (int)$v < 0 ? 'Unlimited' : number_format((int)$v);

$pageTitle = 'Subscription Plans';
include __DIR__ . '/sa-header.php';
?>
<div class="row g-3">
  <div class="col-lg-8">
    <div class="card">
      <div class="card-header">Plans (<?= count($plans) ?>)</div>
      <div class="table-responsive">
        <table class="table table-hover">
          <thead><tr><th>Plan</th><th>Monthly</th><th>Yearly</th><th>Limits (Doc/Staff/Pat)</th><th>Subs</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
          <tbody>
          <?php if (!$plans): ?><tr><td colspan="7" class="text-muted text-center py-4">No plans yet.</td></tr><?php endif; ?>
          <?php foreach ($plans as $p): ?>
            <tr>
              <td><strong><?= e($p['name']) ?></strong><br><small class="text-muted"><?= e($p['slug']) ?> · trial <?= (int)$p['trial_days'] ?>d · dept <?= e($limitLabel($p['max_departments'])) ?> · storage <?= (int)$p['max_storage_mb'] ?> MB</small></td>
              <td><?= money($p['monthly_price']) ?></td>
              <td><?= money($p['yearly_price']) ?></td>
              <td class="small"><?= e($limitLabel($p['max_doctors'])) ?> / <?= e($limitLabel($p['max_staff'])) ?> / <?= e($limitLabel($p['max_patients'])) ?></td>
              <td><?= (int)$p['sub_count'] ?></td>
              <td><?= (int)$p['is_active'] === 1 ? status_badge('active') : status_badge('inactive') ?></td>
              <td class="text-end text-nowrap">
                <a class="btn btn-sm btn-outline-primary" href="plans.php?edit=<?= (int)$p['id'] ?>"><i class="bi bi-pencil"></i></a>
                <form method="post" class="d-inline">
                  <?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="plan_id" value="<?= (int)$p['id'] ?>">
                  <button class="btn btn-sm btn-outline-secondary" type="submit" title="Toggle active"><?= (int)$p['is_active'] === 1 ? '<i class="bi bi-pause"></i>' : '<i class="bi bi-play"></i>' ?></button>
                </form>
                <form method="post" class="d-inline" data-confirm="Delete this plan? If subscriptions exist it will only be deactivated.">
                  <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="plan_id" value="<?= (int)$p['id'] ?>">
                  <button class="btn btn-sm btn-outline-danger" type="submit"><i class="bi bi-trash"></i></button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card">
      <div class="card-header"><?= $edit ? 'Edit plan — ' . e($edit['name']) : 'Create plan' ?></div>
      <div class="card-body">
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="save">
          <input type="hidden" name="plan_id" value="<?= (int)($edit['id'] ?? 0) ?>">
          <div class="mb-2"><label class="form-label small fw-semibold">Name *</label><input class="form-control" name="name" required value="<?= e($edit['name'] ?? '') ?>"></div>
          <div class="mb-2"><label class="form-label small fw-semibold">Slug</label><input class="form-control" name="slug" value="<?= e($edit['slug'] ?? '') ?>" placeholder="auto from name"></div>
          <div class="row g-2">
            <div class="col-6 mb-2"><label class="form-label small fw-semibold">Monthly price ($)</label><input class="form-control" type="number" step="0.01" min="0" name="monthly_price" value="<?= e((string)($edit['monthly_price'] ?? '0')) ?>"></div>
            <div class="col-6 mb-2"><label class="form-label small fw-semibold">Yearly price ($)</label><input class="form-control" type="number" step="0.01" min="0" name="yearly_price" value="<?= e((string)($edit['yearly_price'] ?? '0')) ?>"></div>
            <div class="col-6 mb-2"><label class="form-label small fw-semibold">Trial days</label><input class="form-control" type="number" min="0" name="trial_days" value="<?= (int)($edit['trial_days'] ?? 14) ?>"></div>
            <div class="col-6 mb-2"><label class="form-label small fw-semibold">Sort order</label><input class="form-control" type="number" name="sort_order" value="<?= (int)($edit['sort_order'] ?? 0) ?>"></div>
            <div class="col-6 mb-2"><label class="form-label small fw-semibold">Max doctors (-1 = ∞)</label><input class="form-control" type="number" name="max_doctors" value="<?= (int)($edit['max_doctors'] ?? 2) ?>"></div>
            <div class="col-6 mb-2"><label class="form-label small fw-semibold">Max staff (-1 = ∞)</label><input class="form-control" type="number" name="max_staff" value="<?= (int)($edit['max_staff'] ?? 5) ?>"></div>
            <div class="col-6 mb-2"><label class="form-label small fw-semibold">Max patients (-1 = ∞)</label><input class="form-control" type="number" name="max_patients" value="<?= (int)($edit['max_patients'] ?? 200) ?>"></div>
            <div class="col-6 mb-2"><label class="form-label small fw-semibold">Max departments (-1 = ∞)</label><input class="form-control" type="number" name="max_departments" value="<?= (int)($edit['max_departments'] ?? 3) ?>"></div>
            <div class="col-12 mb-2"><label class="form-label small fw-semibold">Max storage (MB)</label><input class="form-control" type="number" min="0" name="max_storage_mb" value="<?= (int)($edit['max_storage_mb'] ?? 500) ?>"></div>
          </div>
          <div class="mb-2"><label class="form-label small fw-semibold">Features (one per line)</label><textarea class="form-control" name="features" rows="5" placeholder="Patient registration&#10;Appointment booking"><?= e($editFeatures) ?></textarea></div>
          <div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="is_active" id="planActive" value="1" <?= !$edit || (int)$edit['is_active'] === 1 ? 'checked' : '' ?>><label class="form-check-label" for="planActive">Active (visible for purchase)</label></div>
          <div class="d-flex gap-2">
            <button class="btn btn-primary" type="submit"><i class="bi bi-save"></i> <?= $edit ? 'Save changes' : 'Create plan' ?></button>
            <?php if ($edit): ?><a class="btn btn-outline-secondary" href="plans.php">Cancel</a><?php endif; ?>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/sa-footer.php'; ?>
