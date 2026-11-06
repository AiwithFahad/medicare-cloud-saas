<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
$pdo = db();
$user = require_role(['super_admin']);

if (is_post()) {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    $couponId = (int)($_POST['coupon_id'] ?? 0);

    if ($action === 'save') {
        $code = strtoupper(trim((string)($_POST['code'] ?? '')));
        $type = (string)($_POST['discount_type'] ?? 'percent');
        if (!in_array($type, ['percent', 'fixed'], true)) {
            $type = 'percent';
        }
        $maxUses = trim((string)($_POST['max_uses'] ?? ''));
        $validFrom = trim((string)($_POST['valid_from'] ?? ''));
        $validUntil = trim((string)($_POST['valid_until'] ?? ''));
        $data = [
            $code,
            trim((string)($_POST['description'] ?? '')) ?: null,
            $type,
            max(0, (float)($_POST['discount_value'] ?? 0)),
            $maxUses === '' ? null : max(1, (int)$maxUses),
            $validFrom === '' ? null : $validFrom,
            $validUntil === '' ? null : $validUntil,
            isset($_POST['is_active']) ? 1 : 0,
        ];
        if ($code === '') {
            flash('error', 'Coupon code is required.');
            redirect('coupons.php');
        }
        try {
            if ($couponId > 0) {
                $data[] = $couponId;
                $pdo->prepare('UPDATE coupons SET code=?, description=?, discount_type=?, discount_value=?, max_uses=?, valid_from=?, valid_until=?, is_active=? WHERE id=?')->execute($data);
                audit_log($pdo, 'coupon_updated', 'coupons', $couponId, 'Coupon updated: ' . $code);
                flash('success', 'Coupon updated.');
            } else {
                $pdo->prepare('INSERT INTO coupons (code, description, discount_type, discount_value, max_uses, valid_from, valid_until, is_active) VALUES (?,?,?,?,?,?,?,?)')->execute($data);
                audit_log($pdo, 'coupon_created', 'coupons', (int)$pdo->lastInsertId(), 'Coupon created: ' . $code);
                flash('success', 'Coupon created.');
            }
        } catch (PDOException $ex) {
            flash('error', 'Could not save the coupon — the code may already exist.');
        }
    } elseif ($action === 'toggle' && $couponId > 0) {
        $pdo->prepare('UPDATE coupons SET is_active = 1 - is_active WHERE id = ?')->execute([$couponId]);
        audit_log($pdo, 'coupon_toggled', 'coupons', $couponId, 'Coupon active flag toggled');
        flash('success', 'Coupon status changed.');
    } elseif ($action === 'delete' && $couponId > 0) {
        $c = $pdo->prepare('SELECT COUNT(*) FROM coupon_redemptions WHERE coupon_id = ?');
        $c->execute([$couponId]);
        if ((int)$c->fetchColumn() > 0) {
            $pdo->prepare('UPDATE coupons SET is_active = 0 WHERE id = ?')->execute([$couponId]);
            audit_log($pdo, 'coupon_deactivated', 'coupons', $couponId, 'Delete blocked — redemptions exist; coupon deactivated instead');
            flash('error', 'This coupon has redemptions, so it cannot be deleted. It has been deactivated instead.');
        } else {
            $pdo->prepare('DELETE FROM coupons WHERE id = ?')->execute([$couponId]);
            audit_log($pdo, 'coupon_deleted', 'coupons', $couponId, 'Coupon deleted');
            flash('success', 'Coupon deleted.');
        }
    }
    redirect('coupons.php');
}

$coupons = $pdo->query(
    'SELECT c.*, (SELECT COUNT(*) FROM coupon_redemptions cr WHERE cr.coupon_id = c.id) AS redemption_count
       FROM coupons c ORDER BY c.id DESC'
)->fetchAll();

$edit = null;
if (isset($_GET['edit'])) {
    $s = $pdo->prepare('SELECT * FROM coupons WHERE id = ?');
    $s->execute([(int)$_GET['edit']]);
    $edit = $s->fetch() ?: null;
}

$pageTitle = 'Coupons';
include __DIR__ . '/sa-header.php';
?>
<div class="row g-3">
  <div class="col-lg-8">
    <div class="card">
      <div class="card-header">Coupons (<?= count($coupons) ?>)</div>
      <div class="table-responsive">
        <table class="table table-hover">
          <thead><tr><th>Code</th><th>Discount</th><th>Usage</th><th>Validity</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
          <tbody>
          <?php if (!$coupons): ?><tr><td colspan="6" class="text-muted text-center py-4">No coupons yet.</td></tr><?php endif; ?>
          <?php foreach ($coupons as $c): ?>
            <tr>
              <td><strong><?= e($c['code']) ?></strong><?php if ($c['description']): ?><br><small class="text-muted"><?= e($c['description']) ?></small><?php endif; ?></td>
              <td><?= $c['discount_type'] === 'percent' ? e(rtrim(rtrim(number_format((float)$c['discount_value'], 2), '0'), '.')) . '% off' : money($c['discount_value']) . ' off' ?></td>
              <td><?= (int)$c['used_count'] ?> used · <?= (int)$c['redemption_count'] ?> redemptions<br><small class="text-muted"><?= $c['max_uses'] === null ? 'Unlimited uses' : 'Max ' . (int)$c['max_uses'] ?></small></td>
              <td class="small"><?= $c['valid_from'] ? fmt_date($c['valid_from']) : '—' ?> → <?= $c['valid_until'] ? fmt_date($c['valid_until']) : 'No expiry' ?></td>
              <td><?= (int)$c['is_active'] === 1 ? status_badge('active') : status_badge('inactive') ?></td>
              <td class="text-end text-nowrap">
                <a class="btn btn-sm btn-outline-primary" href="coupons.php?edit=<?= (int)$c['id'] ?>"><i class="bi bi-pencil"></i></a>
                <form method="post" class="d-inline">
                  <?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="coupon_id" value="<?= (int)$c['id'] ?>">
                  <button class="btn btn-sm btn-outline-secondary" type="submit" title="Toggle active"><?= (int)$c['is_active'] === 1 ? '<i class="bi bi-pause"></i>' : '<i class="bi bi-play"></i>' ?></button>
                </form>
                <form method="post" class="d-inline" data-confirm="Delete this coupon?">
                  <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="coupon_id" value="<?= (int)$c['id'] ?>">
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
      <div class="card-header"><?= $edit ? 'Edit coupon — ' . e($edit['code']) : 'Create coupon' ?></div>
      <div class="card-body">
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="save">
          <input type="hidden" name="coupon_id" value="<?= (int)($edit['id'] ?? 0) ?>">
          <div class="mb-2"><label class="form-label small fw-semibold">Code *</label><input class="form-control text-uppercase" name="code" required value="<?= e($edit['code'] ?? '') ?>" placeholder="WELCOME10"></div>
          <div class="mb-2"><label class="form-label small fw-semibold">Description</label><input class="form-control" name="description" value="<?= e($edit['description'] ?? '') ?>"></div>
          <div class="row g-2">
            <div class="col-6 mb-2"><label class="form-label small fw-semibold">Type</label>
              <select class="form-select" name="discount_type">
                <option value="percent" <?= ($edit['discount_type'] ?? 'percent') === 'percent' ? 'selected' : '' ?>>Percent (%)</option>
                <option value="fixed" <?= ($edit['discount_type'] ?? '') === 'fixed' ? 'selected' : '' ?>>Fixed amount ($)</option>
              </select></div>
            <div class="col-6 mb-2"><label class="form-label small fw-semibold">Value</label><input class="form-control" type="number" step="0.01" min="0" name="discount_value" value="<?= e((string)($edit['discount_value'] ?? '0')) ?>"></div>
            <div class="col-12 mb-2"><label class="form-label small fw-semibold">Max uses (blank = unlimited)</label><input class="form-control" type="number" min="1" name="max_uses" value="<?= $edit && $edit['max_uses'] !== null ? (int)$edit['max_uses'] : '' ?>"></div>
            <div class="col-6 mb-2"><label class="form-label small fw-semibold">Valid from</label><input class="form-control" type="date" name="valid_from" value="<?= e($edit['valid_from'] ?? '') ?>"></div>
            <div class="col-6 mb-2"><label class="form-label small fw-semibold">Valid until</label><input class="form-control" type="date" name="valid_until" value="<?= e($edit['valid_until'] ?? '') ?>"></div>
          </div>
          <div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="is_active" id="couponActive" value="1" <?= !$edit || (int)$edit['is_active'] === 1 ? 'checked' : '' ?>><label class="form-check-label" for="couponActive">Active</label></div>
          <div class="d-flex gap-2">
            <button class="btn btn-primary" type="submit"><i class="bi bi-save"></i> <?= $edit ? 'Save changes' : 'Create coupon' ?></button>
            <?php if ($edit): ?><a class="btn btn-outline-secondary" href="coupons.php">Cancel</a><?php endif; ?>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/sa-footer.php'; ?>
