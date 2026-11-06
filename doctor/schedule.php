<?php
declare(strict_types=1);
/* Doctor — My Schedule (Upgrade v2 page tree).
 * The doctor edits their own weekly availability (doctor_schedules);
 * saving regenerates the public schedule summary on the doctor profile
 * and immediately changes the slots offered by the booking system.
 * Below: a 14-day availability calendar with booked visits per day. */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/panel.php';
$pdo = db();
$user = require_role(['doctor']);
$tid = require_tenant();
check_tenant_active($pdo);
$doctor = panel_doctor($pdo, $tid, (int)$user['id']);
if (!$doctor) {
    flash('error', 'Your login is not linked to a doctor profile yet. Ask your hospital admin to link it from the Doctors page.');
    redirect('../app/dashboard.php');
}
$did = (int)$doctor['id'];
$base = '../';
$panelSidebar = 'doctor';

if (is_post()) {
    verify_csrf();
    if (($_POST['action'] ?? '') === 'save_schedule') {
        $up = $pdo->prepare(
            'INSERT INTO doctor_schedules (tenant_id, doctor_id, day_of_week, start_time, end_time, slot_minutes, is_available)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE start_time = VALUES(start_time), end_time = VALUES(end_time), slot_minutes = VALUES(slot_minutes), is_available = VALUES(is_available)'
        );
        for ($d = 0; $d <= 6; $d++) {
            $avail = isset($_POST['avail'][$d]) ? 1 : 0;
            $start = preg_match('/^\d{2}:\d{2}$/', (string)($_POST['start'][$d] ?? '')) ? (string)$_POST['start'][$d] . ':00' : '09:00:00';
            $end = preg_match('/^\d{2}:\d{2}$/', (string)($_POST['end'][$d] ?? '')) ? (string)$_POST['end'][$d] . ':00' : '17:00:00';
            $slot = max(5, min(120, (int)($_POST['slot'][$d] ?? 30)));
            $up->execute([$tid, $did, $d, $start, $end, $slot, $avail]);
        }
        // Refresh the human-readable summary stored on the doctor row.
        $s = $pdo->prepare('SELECT * FROM doctor_schedules WHERE tenant_id = ? AND doctor_id = ? AND is_available = 1 ORDER BY day_of_week');
        $s->execute([$tid, $did]);
        $days = week_day_names();
        $parts = [];
        foreach ($s->fetchAll() as $r) {
            $parts[] = substr($days[(int)$r['day_of_week']], 0, 3) . ' ' . date('g:i A', strtotime((string)$r['start_time'])) . '–' . date('g:i A', strtotime((string)$r['end_time']));
        }
        $pdo->prepare('UPDATE doctors SET schedule = ? WHERE id = ? AND tenant_id = ?')->execute([$parts ? implode(' · ', $parts) : 'Not scheduled', $did, $tid]);
        audit_log($pdo, 'doctor_schedule_updated', 'doctors', $did, 'Doctor updated own weekly schedule');
        flash('success', 'Schedule saved — booking slots now follow these hours.');
    }
    redirect('schedule.php');
}

$sched = [];
$ss = $pdo->prepare('SELECT * FROM doctor_schedules WHERE tenant_id = ? AND doctor_id = ?');
$ss->execute([$tid, $did]);
foreach ($ss->fetchAll() as $r) {
    $sched[(int)$r['day_of_week']] = $r;
}
$dayNames = week_day_names();

$days = [];
for ($i = 0; $i < 14; $i++) {
    $date = date('Y-m-d', strtotime("+{$i} days"));
    $free = doctor_free_slots($pdo, $tid, $did, $date);
    $bs = $pdo->prepare(
        "SELECT a.*, p.name AS patient_name FROM appointments a JOIN patients p ON p.id = a.patient_id
          WHERE a.tenant_id = ? AND a.doctor_id = ? AND a.appointment_date = ? AND a.status IN ('scheduled','confirmed')
          ORDER BY a.appointment_time"
    );
    $bs->execute([$tid, $did, $date]);
    $days[] = ['date' => $date, 'free' => $free, 'booked' => $bs->fetchAll()];
}

$pageTitle = 'My Schedule';
include __DIR__ . '/../includes/header.php';
?>
<div class="card mb-3">
  <div class="card-header"><i class="bi bi-clock-history me-1"></i>Weekly availability</div>
  <div class="card-body">
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="save_schedule">
      <div class="table-responsive">
        <table class="table table-sm align-middle">
          <thead><tr><th>Day</th><th>Available</th><th>Start</th><th>End</th><th>Slot length (min)</th></tr></thead>
          <tbody>
          <?php for ($d = 0; $d <= 6; $d++): $row = $sched[$d] ?? null; ?>
            <tr>
              <td><strong><?= e($dayNames[$d]) ?></strong></td>
              <td><input class="form-check-input" type="checkbox" name="avail[<?= $d ?>]" value="1" <?= $row && (int)$row['is_available'] ? 'checked' : '' ?> aria-label="Available on <?= e($dayNames[$d]) ?>"></td>
              <td><input class="form-control" type="time" name="start[<?= $d ?>]" value="<?= e($row ? substr((string)$row['start_time'], 0, 5) : '09:00') ?>" style="max-width:150px"></td>
              <td><input class="form-control" type="time" name="end[<?= $d ?>]" value="<?= e($row ? substr((string)$row['end_time'], 0, 5) : '17:00') ?>" style="max-width:150px"></td>
              <td><input class="form-control" type="number" name="slot[<?= $d ?>]" min="5" max="120" step="5" value="<?= (int)($row['slot_minutes'] ?? 30) ?>" style="max-width:110px"></td>
            </tr>
          <?php endfor; ?>
          </tbody>
        </table>
      </div>
      <button class="btn btn-primary" type="submit"><i class="bi bi-check-lg"></i> Save schedule</button>
      <span class="text-muted small ms-2">Current public summary: <?= e($doctor['schedule'] ?? 'Not scheduled') ?></span>
    </form>
  </div>
</div>

<h5 class="mb-3">Next 14 days</h5>
<div class="row g-3">
  <?php foreach ($days as $day): $isToday = $day['date'] === date('Y-m-d'); ?>
  <div class="col-md-6 col-xl-4">
    <div class="cal-day <?= $isToday ? 'today' : '' ?> <?= $day['free']['available'] ? '' : 'off' ?>">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <strong><?= e(date('D, d M', strtotime($day['date']))) ?><?= $isToday ? ' <span class="badge text-bg-primary">Today</span>' : '' ?></strong>
        <?php if ($day['free']['schedule']): ?><small class="text-muted"><?= e(substr((string)$day['free']['schedule']['start_time'], 0, 5)) ?>–<?= e(substr((string)$day['free']['schedule']['end_time'], 0, 5)) ?></small><?php else: ?><small class="text-muted">Day off</small><?php endif; ?>
      </div>
      <?php if (!$day['free']['available']): ?>
        <p class="text-muted small mb-0"><?= e($day['free']['reason']) ?></p>
      <?php else: ?>
        <p class="small mb-2"><span class="badge text-bg-success"><?= count($day['free']['slots']) ?> free slots</span> <span class="badge text-bg-secondary"><?= count($day['booked']) ?> booked</span></p>
        <?php foreach ($day['booked'] as $bk): ?>
          <div class="small border-top py-1"><i class="bi bi-clock me-1"></i><?= e(substr((string)$bk['appointment_time'], 0, 5)) ?> — <?= e($bk['patient_name']) ?> <span class="text-muted">(<?= e($bk['type']) ?>)</span></div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
