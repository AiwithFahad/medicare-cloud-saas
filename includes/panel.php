<?php
/* Upgrade v2 shared helpers for the separate role panels
 * (admin/, doctor/, receptionist/, patient/) and the JSON API.
 * Loaded after includes/auth.php. Everything here is tenant-scoped:
 * callers pass the current tenant id and every query filters by it. */

/** Doctor profile row linked to a login user (doctors.user_id), or null. */
function panel_doctor(PDO $pdo, int $tenantId, int $userId): ?array
{
    $s = $pdo->prepare(
        'SELECT d.*, dep.name AS department_name, u.name AS full_name, u.email AS user_email
           FROM doctors d
           JOIN users u ON u.id = d.user_id
           LEFT JOIN departments dep ON dep.id = d.department_id
          WHERE d.tenant_id = ? AND d.user_id = ? LIMIT 1'
    );
    $s->execute([$tenantId, $userId]);
    $row = $s->fetch();
    return $row ?: null;
}

/** Patient profile row linked to a login user (patients.user_id), or null. */
function panel_patient(PDO $pdo, int $tenantId, int $userId): ?array
{
    $s = $pdo->prepare('SELECT * FROM patients WHERE tenant_id = ? AND user_id = ? LIMIT 1');
    $s->execute([$tenantId, $userId]);
    $row = $s->fetch();
    return $row ?: null;
}

/** Weekly schedule row for a doctor on a given date (doctor_schedules). */
function doctor_schedule_for(PDO $pdo, int $tenantId, int $doctorId, string $date): ?array
{
    $dow = (int)date('w', strtotime($date));
    $s = $pdo->prepare('SELECT * FROM doctor_schedules WHERE tenant_id = ? AND doctor_id = ? AND day_of_week = ? LIMIT 1');
    $s->execute([$tenantId, $doctorId, $dow]);
    $row = $s->fetch();
    return $row ?: null;
}

/**
 * Free appointment slots for a doctor on a date, as "HH:MM" strings.
 * Slots come from doctor_schedules; booked slots (scheduled/confirmed
 * appointments) and — for today — past times are excluded. Returns an
 * array with keys: available (bool), reason (string), slots (string[]),
 * schedule (row|null).
 */
function doctor_free_slots(PDO $pdo, int $tenantId, int $doctorId, string $date, ?int $ignoreAppointmentId = null): array
{
    $sched = doctor_schedule_for($pdo, $tenantId, $doctorId, $date);
    if (!$sched || !(int)$sched['is_available']) {
        return ['available' => false, 'reason' => 'Doctor is not available on this day.', 'slots' => [], 'schedule' => $sched];
    }
    $booked = [];
    $sql = "SELECT appointment_time FROM appointments
             WHERE tenant_id = ? AND doctor_id = ? AND appointment_date = ?
               AND status IN ('scheduled','confirmed')";
    $params = [$tenantId, $doctorId, $date];
    if ($ignoreAppointmentId) {
        $sql .= ' AND id <> ?';
        $params[] = $ignoreAppointmentId;
    }
    $bs = $pdo->prepare($sql);
    $bs->execute($params);
    foreach ($bs->fetchAll(PDO::FETCH_COLUMN) as $t) {
        $booked[substr((string)$t, 0, 5)] = true;
    }
    $slots = [];
    $step = max(5, (int)$sched['slot_minutes']);
    $start = strtotime($date . ' ' . $sched['start_time']);
    $end = strtotime($date . ' ' . $sched['end_time']);
    $isToday = $date === date('Y-m-d');
    $now = time();
    for ($t = $start; $t + $step * 60 <= $end + 1; $t += $step * 60) {
        $label = date('H:i', $t);
        if (isset($booked[$label])) {
            continue;
        }
        if ($isToday && $t <= $now) {
            continue;
        }
        $slots[] = $label;
    }
    return [
        'available' => true,
        'reason' => $slots ? '' : 'All slots are booked for this day.',
        'slots' => $slots,
        'schedule' => $sched,
    ];
}

/** Day names indexed by doctor_schedules.day_of_week (0 = Sunday). */
function week_day_names(): array
{
    return ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
}

/** Human age from a date of birth, or '—'. */
function patient_age(?string $dob): string
{
    if (!$dob) {
        return '—';
    }
    $d = date_diff(date_create($dob), date_create('today'));
    return $d->y > 0 ? $d->y . ' yrs' : ($d->m > 0 ? $d->m . ' mo' : $d->d . ' days');
}

/* ------------------------- Print documents ------------------------- */
/* Standalone printable layouts (invoice, prescription, lab report).
 * Same brand: white page, medical-blue header band, Inter font. */

function print_page_start(string $title, string $base = '../'): void
{
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> | <?= e(APP_NAME) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="<?= $base ?>assets/css/style.css" rel="stylesheet">
<style>
  body{background:#e8eef5}
  .print-sheet{background:#fff;max-width:820px;margin:24px auto;padding:36px 40px;border-radius:12px;box-shadow:0 10px 30px rgba(15,23,42,.12)}
  .print-brand{display:flex;align-items:center;gap:12px;border-bottom:3px solid var(--blue);padding-bottom:14px;margin-bottom:20px}
  .print-brand .brand-mark{width:44px;height:44px;font-size:1.25rem}
  @media print{ body{background:#fff} .print-sheet{box-shadow:none;margin:0;max-width:none;border-radius:0} .no-print{display:none!important} }
</style>
</head>
<body>
<div class="print-sheet">
  <div class="print-brand">
    <span class="brand-mark"><i class="bi bi-heart-pulse-fill"></i></span>
    <div>
      <div class="fw-bold fs-5 lh-1">MediCare <span style="color:var(--blue)">Cloud</span></div>
      <small class="text-muted"><?= e($title) ?></small>
    </div>
    <span class="ms-auto no-print">
      <button class="btn btn-primary btn-sm" onclick="window.print()"><i class="bi bi-printer"></i> Print / Save PDF</button>
      <button class="btn btn-outline-secondary btn-sm" onclick="window.close()"><i class="bi bi-x-lg"></i> Close</button>
    </span>
  </div>
<?php
}

function print_page_end(): void
{
    ?>
  <p class="text-muted small mt-4 mb-0 text-center">Generated by <?= e(APP_NAME) ?> · <?= e(date('d M Y, h:i A')) ?> · This document was produced from live hospital records.</p>
</div>
</body>
</html>
<?php
}
