<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
$pdo = db();
$user = require_role(['super_admin']);

/* Editable platform settings (key → label, input type, default). */
$settingDefs = [
    'platform_name'      => ['label' => 'Platform name', 'type' => 'text', 'default' => 'MediCare Cloud'],
    'support_email'      => ['label' => 'Support email', 'type' => 'email', 'default' => 'support@medicare.test'],
    'default_trial_days' => ['label' => 'Default trial days', 'type' => 'number', 'default' => '14'],
    'grace_days'         => ['label' => 'Grace days after expiry', 'type' => 'number', 'default' => '7'],
    'currency'           => ['label' => 'Currency', 'type' => 'currency', 'default' => 'USD'],
    'maintenance_mode'   => ['label' => 'Maintenance mode', 'type' => 'checkbox', 'default' => '0'],
];

if (is_post()) {
    verify_csrf();
    $stmt = $pdo->prepare('INSERT INTO platform_settings (skey, svalue) VALUES (?, ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)');
    foreach ($settingDefs as $key => $def) {
        if ($def['type'] === 'checkbox') {
            $value = isset($_POST[$key]) ? '1' : '0';
        } elseif ($def['type'] === 'number') {
            $value = (string)max(0, (int)($_POST[$key] ?? $def['default']));
        } else {
            $value = trim((string)($_POST[$key] ?? $def['default']));
        }
        $stmt->execute([$key, $value]);
    }
    audit_log($pdo, 'platform_settings_updated', 'platform_settings', null, 'Platform settings saved: ' . implode(', ', array_keys($settingDefs)));
    flash('success', 'Platform settings saved.');
    redirect('settings.php');
}

$rows = $pdo->query('SELECT skey, svalue FROM platform_settings')->fetchAll();
$settings = [];
foreach ($rows as $r) {
    $settings[$r['skey']] = $r['svalue'];
}
foreach ($settingDefs as $key => $def) {
    if (!array_key_exists($key, $settings)) {
        $settings[$key] = $def['default'];
    }
}

$auditLogs = $pdo->query(
    'SELECT al.*, u.name AS user_name, t.name AS tenant_name
       FROM audit_logs al
       LEFT JOIN users u ON u.id = al.user_id
       LEFT JOIN tenants t ON t.id = al.tenant_id
      ORDER BY al.id DESC LIMIT 20'
)->fetchAll();
$currencies = ['USD', 'EUR', 'GBP', 'PKR', 'INR', 'AED'];

$pageTitle = 'Platform Settings';
include __DIR__ . '/sa-header.php';
?>
<div class="row g-3">
  <div class="col-lg-5">
    <div class="card">
      <div class="card-header">Platform settings</div>
      <div class="card-body">
        <form method="post">
          <?= csrf_field() ?>
          <?php foreach ($settingDefs as $key => $def): $val = (string)($settings[$key] ?? $def['default']); ?>
            <?php if ($def['type'] === 'checkbox'): ?>
              <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" name="<?= e($key) ?>" id="set_<?= e($key) ?>" value="1" <?= $val === '1' ? 'checked' : '' ?>>
                <label class="form-check-label fw-semibold" for="set_<?= e($key) ?>"><?= e($def['label']) ?></label>
                <div class="form-text">When on, tenants see a maintenance notice. Super admins keep full access.</div>
              </div>
            <?php elseif ($def['type'] === 'currency'): ?>
              <div class="mb-3"><label class="form-label small fw-semibold" for="set_<?= e($key) ?>"><?= e($def['label']) ?></label>
                <select class="form-select" name="<?= e($key) ?>" id="set_<?= e($key) ?>">
                  <?php foreach ($currencies as $cur): ?><option value="<?= e($cur) ?>" <?= $val === $cur ? 'selected' : '' ?>><?= e($cur) ?></option><?php endforeach; ?>
                  <?php if (!in_array($val, $currencies, true)): ?><option value="<?= e($val) ?>" selected><?= e($val) ?></option><?php endif; ?>
                </select></div>
            <?php else: ?>
              <div class="mb-3"><label class="form-label small fw-semibold" for="set_<?= e($key) ?>"><?= e($def['label']) ?></label>
                <input class="form-control" type="<?= e($def['type']) ?>" name="<?= e($key) ?>" id="set_<?= e($key) ?>" value="<?= e($val) ?>" <?= $def['type'] === 'number' ? 'min="0"' : '' ?>></div>
            <?php endif; ?>
          <?php endforeach; ?>
          <button class="btn btn-primary" type="submit"><i class="bi bi-save"></i> Save settings</button>
        </form>
      </div>
    </div>
  </div>
  <div class="col-lg-7">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">Recent audit log <a class="btn btn-sm btn-outline-primary" href="logs.php?tab=audit">Full logs</a></div>
      <div class="table-responsive">
        <table class="table table-hover">
          <thead><tr><th>When</th><th>Actor</th><th>Action</th><th>Entity</th><th>Details</th></tr></thead>
          <tbody>
          <?php if (!$auditLogs): ?><tr><td colspan="5" class="text-muted text-center py-4">No audit entries yet.</td></tr><?php endif; ?>
          <?php foreach ($auditLogs as $al): ?>
            <tr>
              <td class="small text-nowrap"><?= fmt_datetime($al['created_at']) ?></td>
              <td class="small"><?= e($al['user_name'] ?? 'System') ?><?php if ($al['tenant_name']): ?><br><small class="text-muted"><?= e($al['tenant_name']) ?></small><?php endif; ?></td>
              <td><span class="chip"><?= e($al['action']) ?></span></td>
              <td class="small"><?= e($al['entity'] ?? '') ?><?= $al['entity_id'] ? ' #' . (int)$al['entity_id'] : '' ?></td>
              <td class="small text-muted"><?= e($al['details'] ?? '') ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/sa-footer.php'; ?>
