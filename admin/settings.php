<?php
declare(strict_types=1);
/* Hospital Admin — Settings (Upgrade v2 page tree).
 * Hospital profile (tenants row), workspace preferences (tenant_settings),
 * the admin's own password, and a live subscription/usage summary that
 * links to the full subscription page in app/. */
require_once __DIR__ . '/../includes/auth.php';
$pdo = db();
$user = require_role(['hospital_admin']);
$tid = require_tenant();
check_tenant_active($pdo);
if (!has_permission('settings.view')) {
    flash('error', 'You do not have permission to view settings.');
    redirect('dashboard.php');
}
$canManage = has_permission('settings.manage');
$base = '../';
$panelSidebar = 'admin';

$ts = $pdo->prepare('SELECT * FROM tenants WHERE id = ?');
$ts->execute([$tid]);
$tenant = $ts->fetch();

if (is_post()) {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'profile') {
        if (!$canManage) {
            flash('error', 'You do not have permission to change settings.');
            redirect('settings.php');
        }
        $pdo->prepare('UPDATE tenants SET name = ?, phone = ?, address = ?, city = ?, country = ? WHERE id = ?')
            ->execute([trim((string)($_POST['name'] ?? '')), trim((string)($_POST['phone'] ?? '')) ?: null, trim((string)($_POST['address'] ?? '')) ?: null, trim((string)($_POST['city'] ?? '')) ?: null, trim((string)($_POST['country'] ?? '')) ?: null, $tid]);
        $ins = $pdo->prepare('INSERT INTO tenant_settings (tenant_id, skey, svalue) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)');
        foreach (['hospital_name', 'tagline', 'appointment_reminder_hours', 'currency_note'] as $key) {
            if (isset($_POST['set_' . $key])) {
                $ins->execute([$tid, $key, trim((string)$_POST['set_' . $key])]);
            }
        }
        audit_log($pdo, 'tenant_settings_updated', 'tenants', $tid, 'Hospital profile updated');
        flash('success', 'Hospital profile saved.');
    } elseif ($action === 'password') {
        $current = (string)($_POST['current_password'] ?? '');
        $new = (string)($_POST['new_password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');
        $hs = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
        $hs->execute([(int)$user['id']]);
        $hash = (string)$hs->fetchColumn();
        if (!password_verify($current, $hash)) {
            flash('error', 'Your current password is incorrect.');
        } elseif (strlen($new) < 8) {
            flash('error', 'The new password must be at least 8 characters.');
        } elseif ($new !== $confirm) {
            flash('error', 'The new passwords do not match.');
        } else {
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), (int)$user['id']]);
            audit_log($pdo, 'password_changed', 'users', (int)$user['id'], 'Admin changed own password');
            flash('success', 'Password changed.');
        }
    }
    redirect('settings.php');
}

$ss = $pdo->prepare('SELECT skey, svalue FROM tenant_settings WHERE tenant_id = ?');
$ss->execute([$tid]);
$settings = [];
foreach ($ss->fetchAll() as $row) {
    $settings[$row['skey']] = $row['svalue'];
}
$sub = active_subscription($pdo, $tid);
$usage = usage_counts($pdo, $tid);

$pageTitle = 'Settings';
include __DIR__ . '/../includes/header.php';
?>
<div class="row g-3">
  <div class="col-lg-7">
    <div class="card mb-3">
      <div class="card-header"><i class="bi bi-hospital me-1"></i>Hospital profile</div>
      <div class="card-body">
        <form method="post">
          <?= csrf_field() ?><input type="hidden" name="action" value="profile">
          <div class="row g-3">
            <div class="col-md-6"><label class="form-label">Hospital name *</label><input class="form-control" name="name" required maxlength="160" value="<?= e($tenant['name']) ?>" <?= $canManage ? '' : 'disabled' ?>></div>
            <div class="col-md-6"><label class="form-label">Contact email</label><input class="form-control" value="<?= e($tenant['email']) ?>" disabled><small class="text-muted">The registration email cannot be changed here.</small></div>
            <div class="col-md-6"><label class="form-label">Phone</label><input class="form-control" name="phone" maxlength="40" value="<?= e($tenant['phone'] ?? '') ?>" <?= $canManage ? '' : 'disabled' ?>></div>
            <div class="col-md-6"><label class="form-label">Tagline</label><input class="form-control" name="set_tagline" maxlength="160" value="<?= e($settings['tagline'] ?? '') ?>" placeholder="Shown on printed documents" <?= $canManage ? '' : 'disabled' ?>></div>
            <div class="col-12"><label class="form-label">Address</label><input class="form-control" name="address" maxlength="255" value="<?= e($tenant['address'] ?? '') ?>" <?= $canManage ? '' : 'disabled' ?>></div>
            <div class="col-md-6"><label class="form-label">City</label><input class="form-control" name="city" maxlength="80" value="<?= e($tenant['city'] ?? '') ?>" <?= $canManage ? '' : 'disabled' ?>></div>
            <div class="col-md-6"><label class="form-label">Country</label><input class="form-control" name="country" maxlength="80" value="<?= e($tenant['country'] ?? '') ?>" <?= $canManage ? '' : 'disabled' ?>></div>
            <div class="col-md-6"><label class="form-label">Appointment reminder lead time (hours)</label><input class="form-control" type="number" name="set_appointment_reminder_hours" min="1" max="72" value="<?= e($settings['appointment_reminder_hours'] ?? '1') ?>" <?= $canManage ? '' : 'disabled' ?>><small class="text-muted">New bookings create a front-desk reminder this long before the visit.</small></div>
            <div class="col-md-6"><label class="form-label">Workspace status</label><input class="form-control" value="<?= e(ucfirst((string)$tenant['status'])) ?>" disabled></div>
          </div>
          <?php if ($canManage): ?><button class="btn btn-primary mt-3" type="submit"><i class="bi bi-check-lg"></i> Save profile</button><?php endif; ?>
        </form>
      </div>
    </div>
    <div class="card">
      <div class="card-header"><i class="bi bi-key me-1"></i>Change my password</div>
      <div class="card-body">
        <form method="post" class="row g-3">
          <?= csrf_field() ?><input type="hidden" name="action" value="password">
          <div class="col-md-4"><label class="form-label">Current password</label><input class="form-control" type="password" name="current_password" required autocomplete="current-password"></div>
          <div class="col-md-4"><label class="form-label">New password</label><input class="form-control" type="password" name="new_password" required minlength="8" autocomplete="new-password"></div>
          <div class="col-md-4"><label class="form-label">Confirm new password</label><input class="form-control" type="password" name="confirm_password" required minlength="8" autocomplete="new-password"></div>
          <div class="col-12"><button class="btn btn-outline-primary" type="submit"><i class="bi bi-shield-lock"></i> Change password</button></div>
        </form>
      </div>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="card">
      <div class="card-header"><i class="bi bi-credit-card me-1"></i>Subscription &amp; usage</div>
      <div class="card-body">
        <?php if ($sub): ?>
          <div class="d-flex align-items-center gap-2 mb-3">
            <span class="chip"><?= e($sub['plan_name']) ?></span>
            <?= status_badge((string)$sub['status']) ?>
            <span class="text-muted small"><?= e(ucfirst((string)$sub['billing_cycle'])) ?> billing</span>
          </div>
          <?php
          $meters = [
              ['Doctors', (int)$usage['doctors'], (int)$sub['max_doctors']],
              ['Staff', (int)$usage['staff'], (int)$sub['max_staff']],
              ['Patients', (int)$usage['patients'], (int)$sub['max_patients']],
              ['Departments', (int)$usage['departments'], (int)$sub['max_departments']],
          ];
          foreach ($meters as [$label, $used, $limit]):
              $pct = $limit > 0 ? min(100, round($used / $limit * 100)) : 0;
          ?>
            <div class="d-flex justify-content-between small mb-1"><span><?= e($label) ?></span><span><?= $used ?> / <?= $limit < 0 ? '∞' : $limit ?></span></div>
            <div class="progress mb-3"><div class="progress-bar <?= $pct >= 90 ? 'bg-danger' : '' ?>" style="width:<?= $limit < 0 ? 4 : $pct ?>%"></div></div>
          <?php endforeach; ?>
          <dl class="row small mb-3">
            <?php if ($sub['trial_ends_at']): ?><dt class="col-5 text-muted">Trial ends</dt><dd class="col-7"><?= fmt_date($sub['trial_ends_at']) ?></dd><?php endif; ?>
            <?php if ($sub['ends_at']): ?><dt class="col-5 text-muted">Current period ends</dt><dd class="col-7"><?= fmt_date($sub['ends_at']) ?></dd><?php endif; ?>
          </dl>
        <?php else: ?>
          <p class="text-muted">No active subscription was found for this hospital.</p>
        <?php endif; ?>
        <a class="btn btn-primary w-100" href="../app/subscription.php"><i class="bi bi-credit-card"></i> Manage subscription &amp; billing</a>
        <a class="btn btn-outline-primary w-100 mt-2" href="../app/tickets.php"><i class="bi bi-life-preserver"></i> Support tickets</a>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
