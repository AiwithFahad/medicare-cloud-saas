<?php
declare(strict_types=1);
/* Receptionist dashboard (Upgrade v2 page tree) — the front-desk day at a
 * glance: today's arrivals with check-in, new registrations, reminders to
 * work through, and outstanding bills. All live queries, tenant-scoped. */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/panel.php';
$pdo = db();
$user = require_role(['receptionist']);
$tid = require_tenant();
check_tenant_active($pdo);
$base = '../';
$panelSidebar = 'receptionist';

if (is_post()) {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'checkin' && has_permission('appointments.manage')) {
        $pdo->prepare("UPDATE appointments SET status = 'confirmed' WHERE id = ? AND tenant_id = ? AND status = 'scheduled'")
            ->execute([(int)($_POST['appointment_id'] ?? 0), $tid]);
        flash('success', 'Patient checked in (appointment confirmed).');
    } elseif ($action === 'reminder_done') {
        $pdo->prepare("UPDATE reminders SET status = 'done' WHERE id = ? AND tenant_id = ?")
            ->execute([(int)($_POST['reminder_id'] ?? 0), $tid]);
        flash('success', 'Reminder marked done.');
    }
    redirect('dashboard.php');
}

$cnt = static function (string $sql) use ($pdo, $tid): int {
    $s = $pdo->prepare($sql);
    $s->execute([$tid]);
    return (int)$s->fetchColumn();
};
$todayTotal = $cnt('SELECT COUNT(*) FROM appointments WHERE tenant_id = ? AND appointment_date = CURDATE()');
$todayWaiting = $cnt("SELECT COUNT(*) FROM appointments WHERE tenant_id = ? AND appointment_date = CURDATE() AND status = 'scheduled'");
$newToday = $cnt('SELECT COUNT(*) FROM patients WHERE tenant_id = ? AND DATE(created_at) = CURDATE()');
$unpaidCount = $cnt("SELECT COUNT(*) FROM patient_invoices WHERE tenant_id = ? AND status IN ('unpaid','partial')");
$emergencyActive = $cnt("SELECT COUNT(*) FROM emergency_cases WHERE tenant_id = ? AND status IN ('waiting','in_treatment')");

$s = $pdo->prepare(
    "SELECT a.*, p.name AS patient_name, p.patient_code, p.phone, d.name AS doctor_name
       FROM appointments a JOIN patients p ON p.id = a.patient_id JOIN doctors d ON d.id = a.doctor_id
      WHERE a.tenant_id = ? AND a.appointment_date = CURDATE()
      ORDER BY a.appointment_time"
);
$s->execute([$tid]);
$today = $s->fetchAll();

$s = $pdo->prepare(
    "SELECT r.*, p.name AS patient_name FROM reminders r LEFT JOIN patients p ON p.id = r.patient_id
      WHERE r.tenant_id = ? AND r.status = 'pending' AND r.remind_at <= DATE_ADD(NOW(), INTERVAL 24 HOUR)
      ORDER BY r.remind_at LIMIT 8"
);
$s->execute([$tid]);
$reminders = $s->fetchAll();

$pageTitle = 'Front Desk Dashboard';
include __DIR__ . '/../includes/header.php';
?>
<div class="row g-3 mb-3">
  <div class="col-6 col-xl-3"><div class="card stat-card h-100"><span class="stat-ic ic-blue"><i class="bi bi-calendar2-check"></i></span><span><span class="num d-block"><?= $todayTotal ?></span><span class="lbl">Appointments today</span></span></div></div>
  <div class="col-6 col-xl-3"><div class="card stat-card h-100"><span class="stat-ic ic-amber"><i class="bi bi-hourglass-split"></i></span><span><span class="num d-block"><?= $todayWaiting ?></span><span class="lbl">Awaiting check-in</span></span></div></div>
  <div class="col-6 col-xl-3"><div class="card stat-card h-100"><span class="stat-ic ic-green"><i class="bi bi-person-plus"></i></span><span><span class="num d-block"><?= $newToday ?></span><span class="lbl">New patients today</span></span></div></div>
  <div class="col-6 col-xl-3"><div class="card stat-card h-100"><span class="stat-ic ic-rose"><i class="bi bi-receipt-cutoff"></i></span><span><span class="num d-block"><?= $unpaidCount ?></span><span class="lbl">Unpaid / partial bills</span></span></div></div>
</div>

<div class="d-flex flex-wrap gap-2 mb-3">
  <a class="btn btn-primary" href="patients.php"><i class="bi bi-person-plus"></i> Register / Find Patient</a>
  <a class="btn btn-outline-primary" href="appointments.php"><i class="bi bi-calendar-plus"></i> Book Appointment</a>
  <a class="btn btn-outline-danger" href="appointments.php#emergency"><i class="bi bi-heart-pulse"></i> Emergency Board <?= $emergencyActive ? '<span class="badge text-bg-danger">' . $emergencyActive . ' active</span>' : '' ?></a>
  <a class="btn btn-outline-success" href="billing.php"><i class="bi bi-cash-coin"></i> Collect Payment</a>
</div>

<div class="row g-3">
  <div class="col-lg-8">
    <div class="card h-100">
      <div class="card-header d-flex justify-content-between align-items-center"><span>Today's arrivals</span><a class="btn btn-sm btn-outline-primary" href="appointments.php">All appointments</a></div>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead><tr><th>Time</th><th>Patient</th><th>Doctor</th><th>Type</th><th>Status</th><th class="text-end">Check-in</th></tr></thead>
          <tbody>
          <?php if (!$today): ?><tr><td colspan="6" class="text-muted text-center py-4">No appointments today.</td></tr><?php endif; ?>
          <?php foreach ($today as $a): ?>
            <tr>
              <td><strong><?= e(date('h:i A', strtotime((string)$a['appointment_time']))) ?></strong></td>
              <td><a href="patients.php?id=<?= (int)$a['patient_id'] ?>"><?= e($a['patient_name']) ?></a><br><small class="text-muted"><?= e($a['patient_code']) ?> · <?= e($a['phone'] ?? '') ?></small></td>
              <td><?= e($a['doctor_name']) ?></td>
              <td><?= e($a['type']) ?></td>
              <td><?= status_badge((string)$a['status']) ?></td>
              <td class="text-end">
                <?php if ($a['status'] === 'scheduled' && has_permission('appointments.manage')): ?>
                <form method="post" class="d-inline">
                  <?= csrf_field() ?><input type="hidden" name="action" value="checkin"><input type="hidden" name="appointment_id" value="<?= (int)$a['id'] ?>">
                  <button class="btn btn-sm btn-success" type="submit"><i class="bi bi-check-lg"></i> Check in</button>
                </form>
                <?php elseif ($a['status'] === 'confirmed'): ?><span class="text-success small"><i class="bi bi-check2-all"></i> Checked in</span>
                <?php else: ?><span class="text-muted small">—</span><?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header"><i class="bi bi-bell me-1"></i>Reminders to work through</div>
      <div class="card-body">
        <?php if (!$reminders): ?><p class="text-muted mb-0">No reminders due in the next 24 hours. New bookings create reminders automatically.</p><?php endif; ?>
        <?php foreach ($reminders as $r): ?>
          <div class="d-flex gap-2 py-2 border-bottom align-items-start">
            <span class="flex-grow-1">
              <span class="d-block small"><?= e($r['title']) ?></span>
              <small class="text-muted"><?= e(ucfirst((string)$r['channel'])) ?> · <?= fmt_datetime($r['remind_at']) ?></small>
            </span>
            <form method="post" class="d-inline">
              <?= csrf_field() ?><input type="hidden" name="action" value="reminder_done"><input type="hidden" name="reminder_id" value="<?= (int)$r['id'] ?>">
              <button class="btn btn-sm btn-outline-success" type="submit" title="Mark done"><i class="bi bi-check-lg"></i></button>
            </form>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
