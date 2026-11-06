<?php
declare(strict_types=1);
/* Patient portal — My Profile (Upgrade v2 page tree).
 * The patient can review their registered details, update contact
 * information (phone, address, emergency contact), and change their
 * portal password. Clinical fields (blood group, allergies, DOB) are
 * maintained by hospital staff and shown read-only. */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/panel.php';
$pdo = db();
$user = require_role(['patient']);
$tid = require_tenant();
check_tenant_active($pdo);
$base = '../';
$panelSidebar = 'patient';
$patient = panel_patient($pdo, $tid, (int)$user['id']);

if (is_post() && $patient) {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'contact') {
        $pdo->prepare('UPDATE patients SET phone = ?, email = ?, address = ?, emergency_contact = ? WHERE id = ? AND tenant_id = ?')
            ->execute([
                trim((string)($_POST['phone'] ?? '')) ?: null,
                trim((string)($_POST['contact_email'] ?? '')) ?: null,
                trim((string)($_POST['address'] ?? '')) ?: null,
                trim((string)($_POST['emergency_contact'] ?? '')) ?: null,
                (int)$patient['id'],
                $tid,
            ]);
        flash('success', 'Contact details updated.');
    } elseif ($action === 'password') {
        $current = (string)($_POST['current_password'] ?? '');
        $new = (string)($_POST['new_password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');
        $hs = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
        $hs->execute([(int)$user['id']]);
        if (!password_verify($current, (string)$hs->fetchColumn())) {
            flash('error', 'Your current password is incorrect.');
        } elseif (strlen($new) < 8) {
            flash('error', 'The new password must be at least 8 characters.');
        } elseif ($new !== $confirm) {
            flash('error', 'The new passwords do not match.');
        } else {
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), (int)$user['id']]);
            audit_log($pdo, 'password_changed', 'users', (int)$user['id'], 'Patient changed own password');
            flash('success', 'Password changed.');
        }
    }
    redirect('profile.php');
}

$pageTitle = 'My Profile';
include __DIR__ . '/../includes/header.php';
if (!$patient) {
    ?>
    <div class="card"><div class="card-body">
      <h5 class="mb-2">Account</h5>
      <dl class="row small mb-3">
        <dt class="col-4 text-muted">Name</dt><dd class="col-8"><?= e($user['name'] ?? '') ?></dd>
        <dt class="col-4 text-muted">Login email</dt><dd class="col-8"><?= e($user['email'] ?? '') ?></dd>
        <dt class="col-4 text-muted">Patient profile</dt><dd class="col-8">Not linked yet — ask the front desk to link this login to your patient record.</dd>
      </dl>
      <h6 class="mt-4">Change password</h6>
      <form method="post" class="row g-3">
        <?= csrf_field() ?><input type="hidden" name="action" value="password">
        <div class="col-md-4"><label class="form-label">Current password</label><input class="form-control" type="password" name="current_password" required></div>
        <div class="col-md-4"><label class="form-label">New password</label><input class="form-control" type="password" name="new_password" required minlength="8"></div>
        <div class="col-md-4"><label class="form-label">Confirm</label><input class="form-control" type="password" name="confirm_password" required minlength="8"></div>
        <div class="col-12"><button class="btn btn-outline-primary" type="submit"><i class="bi bi-shield-lock"></i> Change password</button></div>
      </form>
    </div></div>
    <?php include __DIR__ . '/../includes/footer.php';
    return;
}
?>
<div class="row g-3">
  <div class="col-lg-7">
    <div class="card">
      <div class="card-header"><i class="bi bi-person-circle me-1"></i>My details</div>
      <div class="card-body">
        <div class="d-flex align-items-center gap-3 mb-3">
          <span class="avatar-initials" style="width:56px;height:56px;font-size:1.2rem"><?= e(initials($patient['name'])) ?></span>
          <span><strong class="d-block fs-5"><?= e($patient['name']) ?></strong><span class="chip"><?= e($patient['patient_code']) ?></span></span>
        </div>
        <dl class="row small mb-4">
          <dt class="col-4 text-muted">Date of birth</dt><dd class="col-8"><?= $patient['dob'] ? fmt_date($patient['dob']) . ' (' . e(patient_age($patient['dob'])) . ')' : '—' ?></dd>
          <dt class="col-4 text-muted">Gender</dt><dd class="col-8"><?= e(ucfirst((string)($patient['gender'] ?? '—'))) ?></dd>
          <dt class="col-4 text-muted">Blood group</dt><dd class="col-8"><?= e($patient['blood_group'] ?? '—') ?></dd>
          <dt class="col-4 text-muted">Allergies</dt><dd class="col-8"><?= $patient['allergies'] ? '<span class="badge text-bg-danger">' . e($patient['allergies']) . '</span>' : 'None recorded' ?></dd>
          <dt class="col-4 text-muted">Registered</dt><dd class="col-8"><?= fmt_date($patient['created_at']) ?></dd>
        </dl>
        <h6>Contact details <small class="text-muted">(you can update these)</small></h6>
        <form method="post" class="row g-3">
          <?= csrf_field() ?><input type="hidden" name="action" value="contact">
          <div class="col-md-6"><label class="form-label">Phone</label><input class="form-control" name="phone" maxlength="40" value="<?= e($patient['phone'] ?? '') ?>"></div>
          <div class="col-md-6"><label class="form-label">Email</label><input class="form-control" type="email" name="contact_email" maxlength="190" value="<?= e($patient['email'] ?? '') ?>"></div>
          <div class="col-md-6"><label class="form-label">Emergency contact</label><input class="form-control" name="emergency_contact" maxlength="120" value="<?= e($patient['emergency_contact'] ?? '') ?>"></div>
          <div class="col-md-6"><label class="form-label">Address</label><input class="form-control" name="address" maxlength="255" value="<?= e($patient['address'] ?? '') ?>"></div>
          <div class="col-12"><button class="btn btn-primary" type="submit"><i class="bi bi-check-lg"></i> Save contact details</button></div>
        </form>
      </div>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="card">
      <div class="card-header"><i class="bi bi-key me-1"></i>Change password</div>
      <div class="card-body">
        <dl class="row small mb-3">
          <dt class="col-5 text-muted">Login email</dt><dd class="col-7"><?= e($user['email'] ?? '') ?></dd>
        </dl>
        <form method="post">
          <?= csrf_field() ?><input type="hidden" name="action" value="password">
          <div class="mb-3"><label class="form-label">Current password</label><input class="form-control" type="password" name="current_password" required autocomplete="current-password"></div>
          <div class="mb-3"><label class="form-label">New password</label><input class="form-control" type="password" name="new_password" required minlength="8" autocomplete="new-password"></div>
          <div class="mb-3"><label class="form-label">Confirm new password</label><input class="form-control" type="password" name="confirm_password" required minlength="8" autocomplete="new-password"></div>
          <button class="btn btn-outline-primary w-100" type="submit"><i class="bi bi-shield-lock"></i> Change password</button>
        </form>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
