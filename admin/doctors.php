<?php
declare(strict_types=1);
/* Hospital Admin — Doctors (Upgrade v2 page tree).
 * Doctor profiles with specialties + consultation fees, structured weekly
 * schedules (doctor_schedules) that drive the availability calendar and the
 * booking slot picker, and an optional portal login per doctor. */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/panel.php';
$pdo = db();
$user = require_role(['hospital_admin']);
$tid = require_tenant();
check_tenant_active($pdo);
if (!has_permission('doctors.view')) {
    flash('error', 'You do not have permission to view doctors.');
    redirect('dashboard.php');
}
$canManage = has_permission('doctors.manage');
$base = '../';
$panelSidebar = 'admin';

$departments = $pdo->prepare('SELECT * FROM departments WHERE tenant_id = ? ORDER BY name');
$departments->execute([$tid]);
$departments = $departments->fetchAll();

/* Regenerate the human-readable schedule summary on the doctor row. */
$syncScheduleText = static function (PDO $pdo, int $tid, int $doctorId): void {
    $s = $pdo->prepare('SELECT * FROM doctor_schedules WHERE tenant_id = ? AND doctor_id = ? AND is_available = 1 ORDER BY day_of_week');
    $s->execute([$tid, $doctorId]);
    $days = week_day_names();
    $parts = [];
    foreach ($s->fetchAll() as $r) {
        $parts[] = substr($days[(int)$r['day_of_week']], 0, 3) . ' ' . date('g:i A', strtotime((string)$r['start_time'])) . '–' . date('g:i A', strtotime((string)$r['end_time']));
    }
    $pdo->prepare('UPDATE doctors SET schedule = ? WHERE id = ? AND tenant_id = ?')
        ->execute([$parts ? implode(' · ', $parts) : 'Not scheduled', $doctorId, $tid]);
};

if (is_post()) {
    verify_csrf();
    if (!$canManage) {
        flash('error', 'You do not have permission to modify doctors.');
        redirect('doctors.php');
    }
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save_doctor') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $deptId = (int)($_POST['department_id'] ?? 0) ?: null;
        $data = [
            'name' => $name,
            'specialization' => trim((string)($_POST['specialization'] ?? '')) ?: null,
            'qualification' => trim((string)($_POST['qualification'] ?? '')) ?: null,
            'department_id' => $deptId,
            'phone' => trim((string)($_POST['phone'] ?? '')) ?: null,
            'email' => trim((string)($_POST['email'] ?? '')) ?: null,
            'consultation_fee' => (float)($_POST['consultation_fee'] ?? 0),
            'status' => ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active',
        ];
        if ($name === '') {
            flash('error', 'Doctor name is required.');
        } elseif ($id > 0) {
            $pdo->prepare('UPDATE doctors SET name=?, specialization=?, qualification=?, department_id=?, phone=?, email=?, consultation_fee=?, status=? WHERE id=? AND tenant_id=?')
                ->execute([$data['name'], $data['specialization'], $data['qualification'], $data['department_id'], $data['phone'], $data['email'], $data['consultation_fee'], $data['status'], $id, $tid]);
            audit_log($pdo, 'doctor_updated', 'doctors', $id, 'Doctor updated: ' . $name);
            flash('success', 'Doctor updated.');
        } else {
            if (plan_limit_reached($pdo, $tid, 'doctors')) {
                flash('error', 'Your plan\'s doctor limit has been reached. Upgrade the subscription to add more doctors.');
                redirect('doctors.php');
            }
            $userId = null;
            $loginEmail = trim((string)($_POST['login_email'] ?? ''));
            $loginPassword = (string)($_POST['login_password'] ?? '');
            if ($loginEmail !== '' && $loginPassword !== '') {
                $roleId = (int)$pdo->query("SELECT id FROM roles WHERE slug = 'doctor'")->fetchColumn();
                $pdo->prepare('INSERT INTO users (tenant_id, name, email, phone, password_hash, role_id, status, email_verified_at) VALUES (?, ?, ?, ?, ?, ?, "active", NOW())')
                    ->execute([$tid, $name, $loginEmail, $data['phone'], password_hash($loginPassword, PASSWORD_DEFAULT), $roleId]);
                $userId = (int)$pdo->lastInsertId();
            }
            $pdo->prepare('INSERT INTO doctors (tenant_id, user_id, department_id, name, specialization, qualification, phone, email, consultation_fee, schedule, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?)')
                ->execute([$tid, $userId, $data['department_id'], $data['name'], $data['specialization'], $data['qualification'], $data['phone'], $data['email'], $data['consultation_fee'], $data['status']]);
            $newId = (int)$pdo->lastInsertId();
            audit_log($pdo, 'doctor_created', 'doctors', $newId, 'Doctor added: ' . $name);
            flash('success', 'Doctor added' . ($userId ? ' with a portal login.' : '.'));
        }
    } elseif ($action === 'save_schedule') {
        $doctorId = (int)($_POST['doctor_id'] ?? 0);
        $chk = $pdo->prepare('SELECT COUNT(*) FROM doctors WHERE id = ? AND tenant_id = ?');
        $chk->execute([$doctorId, $tid]);
        if ((int)$chk->fetchColumn() === 0) {
            flash('error', 'Doctor not found.');
        } else {
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
                $up->execute([$tid, $doctorId, $d, $start, $end, $slot, $avail]);
            }
            $syncScheduleText($pdo, $tid, $doctorId);
            audit_log($pdo, 'doctor_schedule_updated', 'doctors', $doctorId, 'Weekly schedule updated');
            flash('success', 'Weekly schedule saved. The booking calendar now uses these hours.');
        }
    }
    redirect('doctors.php' . (!empty($_POST['back']) ? '?' . (string)$_POST['back'] : ''));
}

/* ---------------- Availability calendar view ---------------- */
$calendarId = (int)($_GET['calendar'] ?? 0);
if ($calendarId > 0) {
    $ds = $pdo->prepare('SELECT d.*, dep.name AS department_name FROM doctors d LEFT JOIN departments dep ON dep.id = d.department_id WHERE d.id = ? AND d.tenant_id = ?');
    $ds->execute([$calendarId, $tid]);
    $doctor = $ds->fetch();
    if (!$doctor) {
        flash('error', 'Doctor not found.');
        redirect('doctors.php');
    }
    $days = [];
    for ($i = 0; $i < 14; $i++) {
        $date = date('Y-m-d', strtotime("+{$i} days"));
        $free = doctor_free_slots($pdo, $tid, $calendarId, $date);
        $bs = $pdo->prepare(
            "SELECT a.*, p.name AS patient_name FROM appointments a JOIN patients p ON p.id = a.patient_id
              WHERE a.tenant_id = ? AND a.doctor_id = ? AND a.appointment_date = ? AND a.status IN ('scheduled','confirmed')
              ORDER BY a.appointment_time"
        );
        $bs->execute([$tid, $calendarId, $date]);
        $days[] = ['date' => $date, 'free' => $free, 'booked' => $bs->fetchAll()];
    }
    $pageTitle = 'Availability — ' . $doctor['name'];
    include __DIR__ . '/../includes/header.php';
    ?>
    <p><a href="doctors.php" class="small"><i class="bi bi-arrow-left"></i> Back to doctors</a></p>
    <div class="card mb-3">
      <div class="card-body d-flex flex-wrap align-items-center gap-3">
        <span class="avatar-initials" style="width:52px;height:52px"><?= e(initials($doctor['name'])) ?></span>
        <span>
          <strong class="d-block fs-5"><?= e($doctor['name']) ?></strong>
          <small class="text-muted"><?= e($doctor['specialization'] ?? '') ?> · <?= e($doctor['department_name'] ?? 'No department') ?> · Fee <?= money((float)$doctor['consultation_fee']) ?></small>
        </span>
        <span class="ms-auto"><span class="badge text-bg-<?= $doctor['status'] === 'active' ? 'success' : 'secondary' ?>"><?= e(ucfirst((string)$doctor['status'])) ?></span></span>
      </div>
    </div>
    <div class="row g-3">
      <?php foreach ($days as $day): $isToday = $day['date'] === date('Y-m-d'); ?>
      <div class="col-md-6 col-xl-4">
        <div class="cal-day <?= $isToday ? 'today' : '' ?> <?= $day['free']['available'] ? '' : 'off' ?>">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <strong><?= e(date('D, d M', strtotime($day['date']))) ?><?= $isToday ? ' <span class="badge text-bg-primary">Today</span>' : '' ?></strong>
            <?php if ($day['free']['schedule']): ?>
              <small class="text-muted"><?= e(substr((string)$day['free']['schedule']['start_time'], 0, 5)) ?>–<?= e(substr((string)$day['free']['schedule']['end_time'], 0, 5)) ?></small>
            <?php else: ?><small class="text-muted">Day off</small><?php endif; ?>
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
    <?php include __DIR__ . '/../includes/footer.php';
    return;
}

/* ---------------- List ---------------- */
$s = $pdo->prepare(
    "SELECT d.*, dep.name AS department_name,
            (SELECT COUNT(*) FROM appointments a WHERE a.doctor_id = d.id AND a.tenant_id = d.tenant_id) AS appt_total,
            (SELECT COUNT(*) FROM appointments a WHERE a.doctor_id = d.id AND a.tenant_id = d.tenant_id AND a.appointment_date = CURDATE()) AS appt_today,
            (SELECT COUNT(*) FROM doctor_schedules ds WHERE ds.doctor_id = d.id AND ds.tenant_id = d.tenant_id AND ds.is_available = 1 AND ds.day_of_week = MOD(WEEKDAY(CURDATE()) + 1, 7)) AS on_duty_today
       FROM doctors d LEFT JOIN departments dep ON dep.id = d.department_id
      WHERE d.tenant_id = ? ORDER BY d.name"
);
$s->execute([$tid]);
$doctors = $s->fetchAll();

$schedMap = [];
$ss = $pdo->prepare('SELECT * FROM doctor_schedules WHERE tenant_id = ?');
$ss->execute([$tid]);
foreach ($ss->fetchAll() as $r) {
    $schedMap[(int)$r['doctor_id']][(int)$r['day_of_week']] = $r;
}
$dayNames = week_day_names();
$editId = (int)($_GET['edit'] ?? 0);
$editDoctor = null;
foreach ($doctors as $d) {
    if ((int)$d['id'] === $editId) {
        $editDoctor = $d;
    }
}

$pageTitle = 'Doctors';
include __DIR__ . '/../includes/header.php';
?>
<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span><?= count($doctors) ?> doctor<?= count($doctors) === 1 ? '' : 's' ?></span>
    <?php if ($canManage): ?><button class="btn btn-primary btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#doctorModal"><i class="bi bi-plus-lg"></i> Add Doctor</button><?php endif; ?>
  </div>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead><tr><th>Doctor</th><th>Department</th><th>Specialty</th><th>Fee</th><th>Today</th><th>Appointments</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
      <?php if (!$doctors): ?><tr><td colspan="8"><div class="empty-state py-4"><i class="bi bi-person-badge"></i><p class="mb-0">No doctors yet — add your first doctor.</p></div></td></tr><?php endif; ?>
      <?php foreach ($doctors as $d): ?>
        <tr>
          <td><div class="d-flex align-items-center gap-2"><span class="avatar-initials"><?= e(initials($d['name'])) ?></span><span><strong class="d-block"><?= e($d['name']) ?></strong><small class="text-muted"><?= e($d['qualification'] ?? '') ?></small></span></div></td>
          <td><?= e($d['department_name'] ?? '—') ?></td>
          <td><?= e($d['specialization'] ?? '—') ?></td>
          <td><?= money((float)$d['consultation_fee']) ?></td>
          <td><?= (int)$d['on_duty_today'] > 0 ? '<span class="badge text-bg-success">On duty</span>' : '<span class="badge text-bg-secondary">Off</span>' ?> <small class="text-muted"><?= (int)$d['appt_today'] ?> appts</small></td>
          <td><?= (int)$d['appt_total'] ?></td>
          <td><?= status_badge((string)$d['status']) ?></td>
          <td class="text-end">
            <a class="btn btn-sm btn-outline-primary" href="doctors.php?calendar=<?= (int)$d['id'] ?>" title="Availability calendar"><i class="bi bi-calendar-week"></i></a>
            <?php if ($canManage): ?>
              <a class="btn btn-sm btn-outline-secondary" href="doctors.php?edit=<?= (int)$d['id'] ?>" title="Edit doctor"><i class="bi bi-pencil"></i></a>
              <button class="btn btn-sm btn-outline-success" type="button" data-bs-toggle="modal" data-bs-target="#scheduleModal<?= (int)$d['id'] ?>" title="Weekly schedule"><i class="bi bi-clock-history"></i> Schedule</button>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($canManage): ?>
<!-- Doctor create/edit modal -->
<div class="modal fade" id="doctorModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <form class="modal-content" method="post">
      <div class="modal-header"><h5 class="modal-title"><?= $editDoctor ? 'Edit Doctor — ' . e($editDoctor['name']) : 'Add Doctor' ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body">
        <?= csrf_field() ?><input type="hidden" name="action" value="save_doctor"><input type="hidden" name="id" value="<?= (int)($editDoctor['id'] ?? 0) ?>">
        <div class="row g-3">
          <div class="col-md-6"><label class="form-label">Full name *</label><input class="form-control" name="name" required maxlength="120" value="<?= e($editDoctor['name'] ?? '') ?>" placeholder="Dr. Jane Smith"></div>
          <div class="col-md-6"><label class="form-label">Department</label>
            <select class="form-select" name="department_id"><option value="">— None —</option>
              <?php foreach ($departments as $dep): ?><option value="<?= (int)$dep['id'] ?>" <?= ($editDoctor['department_id'] ?? null) == $dep['id'] ? 'selected' : '' ?>><?= e($dep['name']) ?></option><?php endforeach; ?>
            </select></div>
          <div class="col-md-6"><label class="form-label">Specialty</label><input class="form-control" name="specialization" maxlength="120" value="<?= e($editDoctor['specialization'] ?? '') ?>" placeholder="e.g. Interventional Cardiology"></div>
          <div class="col-md-6"><label class="form-label">Qualification</label><input class="form-control" name="qualification" maxlength="160" value="<?= e($editDoctor['qualification'] ?? '') ?>" placeholder="MBBS, MD"></div>
          <div class="col-md-4"><label class="form-label">Consultation fee ($)</label><input class="form-control" type="number" name="consultation_fee" min="0" step="0.01" value="<?= e((string)($editDoctor['consultation_fee'] ?? '0')) ?>"></div>
          <div class="col-md-4"><label class="form-label">Phone</label><input class="form-control" name="phone" maxlength="40" value="<?= e($editDoctor['phone'] ?? '') ?>"></div>
          <div class="col-md-4"><label class="form-label">Status</label>
            <select class="form-select" name="status"><option value="active" <?= ($editDoctor['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option><option value="inactive" <?= ($editDoctor['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option></select></div>
          <div class="col-md-6"><label class="form-label">Contact email</label><input class="form-control" type="email" name="email" maxlength="190" value="<?= e($editDoctor['email'] ?? '') ?>"></div>
          <?php if (!$editDoctor): ?>
          <div class="col-md-6"><label class="form-label">Portal login email <small class="text-muted">(optional)</small></label><input class="form-control" type="email" name="login_email" maxlength="190" placeholder="Creates a doctor login"></div>
          <div class="col-md-6"><label class="form-label">Portal password <small class="text-muted">(optional)</small></label><input class="form-control" type="text" name="login_password" maxlength="72" placeholder="Set an initial password"></div>
          <?php endif; ?>
        </div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Save doctor</button></div>
    </form>
  </div>
</div>

<!-- Schedule modals (one per doctor) -->
<?php foreach ($doctors as $d): $dm = $schedMap[(int)$d['id']] ?? []; ?>
<div class="modal fade" id="scheduleModal<?= (int)$d['id'] ?>" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <form class="modal-content" method="post">
      <div class="modal-header"><h5 class="modal-title">Weekly schedule — <?= e($d['name']) ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body">
        <?= csrf_field() ?><input type="hidden" name="action" value="save_schedule"><input type="hidden" name="doctor_id" value="<?= (int)$d['id'] ?>">
        <p class="text-muted small">These hours drive the availability calendar and the booking slot picker. Untick a day to mark the doctor off.</p>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead><tr><th>Day</th><th>Available</th><th>Start</th><th>End</th><th>Slot (min)</th></tr></thead>
            <tbody>
            <?php for ($day = 0; $day <= 6; $day++): $row = $dm[$day] ?? null; ?>
              <tr>
                <td><?= e($dayNames[$day]) ?></td>
                <td><input class="form-check-input" type="checkbox" name="avail[<?= $day ?>]" value="1" <?= $row && (int)$row['is_available'] ? 'checked' : '' ?> aria-label="Available on <?= e($dayNames[$day]) ?>"></td>
                <td><input class="form-control form-control-sm" type="time" name="start[<?= $day ?>]" value="<?= e($row ? substr((string)$row['start_time'], 0, 5) : '09:00') ?>"></td>
                <td><input class="form-control form-control-sm" type="time" name="end[<?= $day ?>]" value="<?= e($row ? substr((string)$row['end_time'], 0, 5) : '17:00') ?>"></td>
                <td><input class="form-control form-control-sm" type="number" name="slot[<?= $day ?>]" min="5" max="120" step="5" value="<?= (int)($row['slot_minutes'] ?? 30) ?>" style="width:90px"></td>
              </tr>
            <?php endfor; ?>
            </tbody>
          </table>
        </div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Save schedule</button></div>
    </form>
  </div>
</div>
<?php endforeach; ?>

<?php if ($editDoctor): ?>
<script>document.addEventListener('DOMContentLoaded', function () { bootstrap.Modal.getOrCreateInstance(document.getElementById('doctorModal')).show(); });</script>
<?php endif; ?>
<?php endif; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
