<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
$pdo = db();
$user = require_role(['hospital_admin']);
$tid = require_tenant();
check_tenant_active($pdo);
if (!has_permission('settings.view')) { http_response_code(403); exit('Access denied for your role.'); }
$canManage = has_permission('settings.manage');

if (is_post()) {
    verify_csrf();
    if (!$canManage) { flash('error', 'You do not have permission to change settings.'); redirect('settings.php'); }
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'profile') {
        $u = $pdo->prepare('UPDATE tenants SET name=?, phone=?, address=?, city=?, country=? WHERE id=?');
        $u->execute([
            trim((string)($_POST['name'] ?? '')) ?: 'Hospital',
            trim((string)($_POST['phone'] ?? '')) ?: null,
            trim((string)($_POST['address'] ?? '')) ?: null,
            trim((string)($_POST['city'] ?? '')) ?: null,
            trim((string)($_POST['country'] ?? '')) ?: null,
            $tid,
        ]);
        audit_log($pdo, 'settings.profile', 'tenant', $tid, '');
        flash('success', 'Hospital profile saved.');
        redirect('settings.php');
    }

    if ($action === 'preferences') {
        $values = [
            'tagline' => trim((string)($_POST['tagline'] ?? '')),
            'currency' => strtoupper(trim((string)($_POST['currency'] ?? 'USD'))) ?: 'USD',
            'appointment_slot_minutes' => (string)max(5, (int)($_POST['appointment_slot_minutes'] ?? 30)),
            'invoice_tax_percent' => (string)max(0, (float)($_POST['invoice_tax_percent'] ?? 0)),
        ];
        $ins = $pdo->prepare('INSERT INTO tenant_settings(tenant_id,skey,svalue) VALUES(?,?,?) ON DUPLICATE KEY UPDATE svalue=VALUES(svalue)');
        foreach ($values as $k => $v) $ins->execute([$tid, $k, $v]);
        audit_log($pdo, 'settings.preferences', 'tenant', $tid, implode(',', array_keys($values)));
        flash('success', 'Preferences saved.');
        redirect('settings.php#preferences');
    }

    if ($action === 'password') {
        $current = (string)($_POST['current_password'] ?? '');
        $new = (string)($_POST['new_password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');
        $s = $pdo->prepare('SELECT password_hash FROM users WHERE id=? AND tenant_id=?');
        $s->execute([(int)$user['id'], $tid]);
        $hash = (string)($s->fetchColumn() ?: '');
        if (!password_verify($current, $hash)) {
            flash('error', 'Your current password is incorrect.');
        } elseif (strlen($new) < 8) {
            flash('error', 'The new password must be at least 8 characters.');
        } elseif ($new !== $confirm) {
            flash('error', 'The new password and its confirmation do not match.');
        } else {
            $u = $pdo->prepare('UPDATE users SET password_hash=? WHERE id=? AND tenant_id=?');
            $u->execute([password_hash($new, PASSWORD_DEFAULT), (int)$user['id'], $tid]);
            audit_log($pdo, 'settings.password_change', 'user', (int)$user['id'], '');
            flash('success', 'Password changed. Use the new password at your next sign-in.');
        }
        redirect('settings.php#security');
    }

    flash('error', 'Unknown settings action.');
    redirect('settings.php');
}

$ts = $pdo->prepare('SELECT * FROM tenants WHERE id=?');
$ts->execute([$tid]);
$tenant = $ts->fetch() ?: [];
$ss = $pdo->prepare('SELECT skey, svalue FROM tenant_settings WHERE tenant_id=?');
$ss->execute([$tid]);
$settings = [];
foreach ($ss->fetchAll() as $row) $settings[$row['skey']] = $row['svalue'];

$pageTitle = 'Settings';
$base = '../';
include __DIR__ . '/../includes/header.php';
?>
<ul class="nav nav-tabs mb-4">
  <li class="nav-item"><a class="nav-link active" href="#profile" data-bs-toggle="tab">Hospital Profile</a></li>
  <li class="nav-item"><a class="nav-link" href="#preferences" data-bs-toggle="tab">Preferences</a></li>
  <li class="nav-item"><a class="nav-link" href="#security" data-bs-toggle="tab">Security</a></li>
</ul>
<div class="tab-content">
  <div class="tab-pane fade show active" id="profile">
    <div class="card" style="max-width:760px"><div class="card-header">Hospital profile</div><div class="card-body">
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="profile">
        <div class="mb-3"><label class="form-label">Hospital name *</label><input name="name" class="form-control" required value="<?= e((string)($tenant['name'] ?? '')) ?>"></div>
        <div class="row g-3">
          <div class="col-md-6 mb-1"><label class="form-label">Phone</label><input name="phone" class="form-control" value="<?= e((string)($tenant['phone'] ?? '')) ?>"></div>
          <div class="col-md-6 mb-1"><label class="form-label">Contact email</label><input class="form-control" value="<?= e((string)($tenant['email'] ?? '')) ?>" disabled><small class="text-muted">Managed by the platform team.</small></div>
          <div class="col-12 mb-1"><label class="form-label">Address</label><input name="address" class="form-control" value="<?= e((string)($tenant['address'] ?? '')) ?>"></div>
          <div class="col-md-6 mb-1"><label class="form-label">City</label><input name="city" class="form-control" value="<?= e((string)($tenant['city'] ?? '')) ?>"></div>
          <div class="col-md-6 mb-1"><label class="form-label">Country</label><input name="country" class="form-control" value="<?= e((string)($tenant['country'] ?? '')) ?>"></div>
        </div>
        <button class="btn btn-primary mt-3" <?= $canManage ? '' : 'disabled' ?>>Save profile</button>
      </form>
    </div></div>
  </div>

  <div class="tab-pane fade" id="preferences">
    <div class="card" style="max-width:760px"><div class="card-header">Hospital preferences</div><div class="card-body">
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="preferences">
        <div class="mb-3"><label class="form-label">Tagline</label><input name="tagline" class="form-control" maxlength="255" value="<?= e((string)($settings['tagline'] ?? '')) ?>" placeholder="e.g. Compassionate care, powered by the cloud"></div>
        <div class="row g-3">
          <div class="col-md-4"><label class="form-label">Currency code</label><input name="currency" class="form-control" maxlength="3" value="<?= e((string)($settings['currency'] ?? 'USD')) ?>"></div>
          <div class="col-md-4"><label class="form-label">Appointment slot (minutes)</label><input type="number" min="5" step="5" name="appointment_slot_minutes" class="form-control" value="<?= e((string)($settings['appointment_slot_minutes'] ?? '30')) ?>"></div>
          <div class="col-md-4"><label class="form-label">Invoice tax (%)</label><input type="number" step="0.01" min="0" name="invoice_tax_percent" class="form-control" value="<?= e((string)($settings['invoice_tax_percent'] ?? '0')) ?>"></div>
        </div>
        <button class="btn btn-primary mt-3" <?= $canManage ? '' : 'disabled' ?>>Save preferences</button>
      </form>
    </div></div>
  </div>

  <div class="tab-pane fade" id="security">
    <div class="card" style="max-width:760px"><div class="card-header">Change your password</div><div class="card-body">
      <p class="small text-muted">Signed in as <strong><?= e($user['name']) ?></strong> (<?= e($user['email']) ?>).</p>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="password">
        <div class="mb-3"><label class="form-label">Current password *</label><input type="password" name="current_password" class="form-control" required autocomplete="current-password"></div>
        <div class="row g-3">
          <div class="col-md-6"><label class="form-label">New password *</label><input type="password" name="new_password" class="form-control" required minlength="8" autocomplete="new-password"></div>
          <div class="col-md-6"><label class="form-label">Confirm new password *</label><input type="password" name="confirm_password" class="form-control" required minlength="8" autocomplete="new-password"></div>
        </div>
        <button class="btn btn-primary mt-3" <?= $canManage ? '' : 'disabled' ?>>Change password</button>
      </form>
    </div></div>
  </div>
</div>
<script>
(function(){var h=location.hash;if(h){var a=document.querySelector('a[href="'+h+'"]');if(a)new bootstrap.Tab(a).show();}})();
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
