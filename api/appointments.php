<?php
declare(strict_types=1);
/*
 * /api/appointments.php — appointment list + actions (tenant-scoped).
 *
 * GET  ?q=&status=&date=&doctor_id=&page=  → rows/total/page/pages (appointments.view)
 *      Doctors only ever see their own appointments; patients only their own.
 * POST action=create     → book (slot-checked against doctor_schedules)
 *      action=status     → scheduled/confirmed/completed/cancelled/no_show
 *      action=reschedule → new date + time, slot-checked
 * Booking a non-emergency appointment creates a reminder for the front desk,
 * and the patient is notified in-app when they have a portal login.
 */

require_once __DIR__ . '/common.php';

$user = api_user();
$pdo = db();
$tenantId = api_tenant();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$role = (string)($user['role'] ?? '');

$ownDoctor = $role === 'doctor' ? panel_doctor($pdo, $tenantId, (int)$user['id']) : null;
$ownPatient = $role === 'patient' ? panel_patient($pdo, $tenantId, (int)$user['id']) : null;

try {
    if ($method === 'GET') {
        api_require_permission($role === 'patient' ? 'portal.view' : 'appointments.view');
        [$page, $perPage, $offset] = api_page_params();
        $q = trim((string)($_GET['q'] ?? ''));
        $status = trim((string)($_GET['status'] ?? ''));
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['date'] ?? '')) ? (string)$_GET['date'] : '';
        $doctorId = (int)($_GET['doctor_id'] ?? 0);

        $where = 'a.tenant_id = ?';
        $params = [$tenantId];
        if ($ownDoctor) {
            $where .= ' AND a.doctor_id = ?';
            $params[] = (int)$ownDoctor['id'];
        } elseif ($doctorId > 0) {
            $where .= ' AND a.doctor_id = ?';
            $params[] = $doctorId;
        }
        if ($ownPatient) {
            $where .= ' AND a.patient_id = ?';
            $params[] = (int)$ownPatient['id'];
        }
        if ($q !== '') {
            $where .= ' AND (p.name LIKE ? OR p.patient_code LIKE ? OR d.name LIKE ?)';
            $like = '%' . $q . '%';
            array_push($params, $like, $like, $like);
        }
        if (in_array($status, ['scheduled', 'confirmed', 'completed', 'cancelled', 'no_show'], true)) {
            $where .= ' AND a.status = ?';
            $params[] = $status;
        }
        if ($date !== '') {
            $where .= ' AND a.appointment_date = ?';
            $params[] = $date;
        }

        $count = $pdo->prepare("SELECT COUNT(*) FROM appointments a JOIN patients p ON p.id = a.patient_id JOIN doctors d ON d.id = a.doctor_id WHERE {$where}");
        $count->execute($params);
        $total = (int)$count->fetchColumn();

        $stmt = $pdo->prepare(
            "SELECT a.*, p.name AS patient_name, p.patient_code, p.phone AS patient_phone,
                    d.name AS doctor_name, d.specialization, dep.name AS department_name
               FROM appointments a
               JOIN patients p ON p.id = a.patient_id
               JOIN doctors d ON d.id = a.doctor_id
               LEFT JOIN departments dep ON dep.id = a.department_id
              WHERE {$where}
              ORDER BY a.appointment_date DESC, a.appointment_time DESC
              LIMIT {$perPage} OFFSET {$offset}"
        );
        $stmt->execute($params);
        $rows = [];
        foreach ($stmt->fetchAll() as $r) {
            $rows[] = [
                'id' => (int)$r['id'],
                'patient_id' => (int)$r['patient_id'],
                'patient_name' => (string)$r['patient_name'],
                'patient_code' => (string)$r['patient_code'],
                'patient_phone' => $r['patient_phone'],
                'doctor_id' => (int)$r['doctor_id'],
                'doctor_name' => (string)$r['doctor_name'],
                'specialization' => $r['specialization'],
                'department_name' => $r['department_name'],
                'date' => (string)$r['appointment_date'],
                'time' => substr((string)$r['appointment_time'], 0, 5),
                'type' => (string)$r['type'],
                'status' => (string)$r['status'],
                'fee' => (float)$r['fee'],
                'reason' => $r['reason'],
            ];
        }
        api_ok(['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => max(1, (int)ceil($total / $perPage))]);
    }

    if ($method !== 'POST') {
        api_fail(405, 'Method not allowed.');
    }
    api_verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);

    /* Load + ownership-check an appointment. */
    $loadAppt = static function (int $apptId) use ($pdo, $tenantId, $ownDoctor, $ownPatient): array {
        $s = $pdo->prepare('SELECT * FROM appointments WHERE id = ? AND tenant_id = ?');
        $s->execute([$apptId, $tenantId]);
        $row = $s->fetch();
        if (!$row) {
            api_fail(404, 'Appointment not found.');
        }
        if ($ownDoctor && (int)$row['doctor_id'] !== (int)$ownDoctor['id']) {
            api_fail(403, 'This appointment belongs to another doctor.');
        }
        if ($ownPatient && (int)$row['patient_id'] !== (int)$ownPatient['id']) {
            api_fail(403, 'This appointment belongs to another patient.');
        }
        return $row;
    };

    if ($action === 'status') {
        if ($role !== 'patient') {
            api_require_permission('appointments.manage');
        }
        $status = (string)($_POST['status'] ?? '');
        $allowed = ['scheduled', 'confirmed', 'completed', 'cancelled', 'no_show'];
        if ($role === 'patient') {
            $allowed = ['cancelled']; // patients may only cancel their own visit
        }
        if (!in_array($status, $allowed, true)) {
            api_fail(422, 'Invalid status transition.');
        }
        $appt = $loadAppt($id);
        $pdo->prepare('UPDATE appointments SET status = ? WHERE id = ? AND tenant_id = ?')->execute([$status, $id, $tenantId]);
        audit_log($pdo, 'appointment_' . $status, 'appointments', $id, 'Appointment status → ' . $status);
        if ($status === 'cancelled') {
            $pdo->prepare("UPDATE reminders SET status = 'cancelled' WHERE tenant_id = ? AND appointment_id = ? AND status = 'pending'")->execute([$tenantId, $id]);
        }
        api_ok(['message' => 'Appointment ' . str_replace('_', ' ', $status) . '.']);
    }

    if ($action === 'reschedule') {
        if ($role !== 'patient') {
            api_require_permission('appointments.manage');
        }
        $appt = $loadAppt($id);
        $date = (string)($_POST['date'] ?? '');
        $time = (string)($_POST['time'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $time)) {
            api_fail(422, 'Pick a valid date and time slot.');
        }
        if ($appt['type'] !== 'Emergency') {
            $free = doctor_free_slots($pdo, $tenantId, (int)$appt['doctor_id'], $date, $id);
            if (!$free['available'] || !in_array($time, $free['slots'], true)) {
                api_fail(422, 'That slot is not available. Please pick one of the free slots.');
            }
        }
        $pdo->prepare('UPDATE appointments SET appointment_date = ?, appointment_time = ?, status = IF(status = \'cancelled\', \'scheduled\', status) WHERE id = ? AND tenant_id = ?')
            ->execute([$date, $time . ':00', $id, $tenantId]);
        audit_log($pdo, 'appointment_rescheduled', 'appointments', $id, 'Rescheduled to ' . $date . ' ' . $time);
        api_ok(['message' => 'Appointment rescheduled to ' . $date . ' at ' . $time . '.']);
    }

    if ($action === 'create') {
        if ($role === 'patient') {
            if (!$ownPatient) {
                api_fail(422, 'Your login is not linked to a patient profile yet. Please contact the hospital.');
            }
            $patientId = (int)$ownPatient['id'];
        } else {
            api_require_permission('appointments.manage');
            $patientId = (int)($_POST['patient_id'] ?? 0);
        }
        $doctorId = (int)($_POST['doctor_id'] ?? 0);
        $date = (string)($_POST['date'] ?? '');
        $time = (string)($_POST['time'] ?? '');
        $type = in_array(($_POST['type'] ?? ''), ['OPD', 'Follow-up', 'Emergency', 'Teleconsult'], true) ? (string)$_POST['type'] : 'OPD';
        $reason = trim((string)($_POST['reason'] ?? '')) ?: null;

        $pc = $pdo->prepare('SELECT COUNT(*) FROM patients WHERE id = ? AND tenant_id = ?');
        $pc->execute([$patientId, $tenantId]);
        if ((int)$pc->fetchColumn() === 0) {
            api_fail(422, 'Please choose a patient.');
        }
        $ds = $pdo->prepare('SELECT * FROM doctors WHERE id = ? AND tenant_id = ?');
        $ds->execute([$doctorId, $tenantId]);
        $doctor = $ds->fetch();
        if (!$doctor) {
            api_fail(422, 'Please choose a doctor.');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $time)) {
            api_fail(422, 'Pick a valid date and time slot.');
        }
        if ($type !== 'Emergency') {
            $free = doctor_free_slots($pdo, $tenantId, $doctorId, $date);
            if (!$free['available']) {
                api_fail(422, $free['reason']);
            }
            if (!in_array($time, $free['slots'], true)) {
                api_fail(422, 'That slot was just taken. Please pick another free slot.');
            }
        }
        $fee = isset($_POST['fee']) && $_POST['fee'] !== '' ? (float)$_POST['fee'] : (float)$doctor['consultation_fee'];
        $status = $role === 'patient' ? 'scheduled' : ((string)($_POST['status'] ?? 'scheduled') === 'confirmed' ? 'confirmed' : 'scheduled');
        $ins = $pdo->prepare(
            'INSERT INTO appointments (tenant_id, patient_id, doctor_id, department_id, appointment_date, appointment_time, type, status, fee, reason, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $ins->execute([$tenantId, $patientId, $doctorId, $doctor['department_id'] ? (int)$doctor['department_id'] : null, $date, $time . ':00', $type, $status, $fee, $reason, (int)$user['id']]);
        $newId = (int)$pdo->lastInsertId();

        // Front-desk reminder before the visit (lead time is configurable in
        // admin/settings.php via tenant_settings.appointment_reminder_hours,
        // default 1 hour) + patient notification.
        $leadStmt = $pdo->prepare("SELECT svalue FROM tenant_settings WHERE tenant_id = ? AND skey = 'appointment_reminder_hours'");
        $leadStmt->execute([$tenantId]);
        $leadHours = max(1, min(72, (int)($leadStmt->fetchColumn() ?: 1)));
        $remindAt = date('Y-m-d H:i:s', strtotime($date . ' ' . $time) - $leadHours * 3600);
        $pName = $pdo->prepare('SELECT name, user_id FROM patients WHERE id = ?');
        $pName->execute([$patientId]);
        $pRow = $pName->fetch();
        $pdo->prepare('INSERT INTO reminders (tenant_id, patient_id, appointment_id, type, title, remind_at, channel, created_by) VALUES (?, ?, ?, \'appointment\', ?, ?, \'call\', ?)')
            ->execute([$tenantId, $patientId, $newId, 'Confirm appointment: ' . ($pRow['name'] ?? 'Patient') . ' with ' . $doctor['name'] . ' on ' . $date . ' at ' . $time, $remindAt, (int)$user['id']]);
        if (!empty($pRow['user_id'])) {
            notify_user($pdo, $tenantId, (int)$pRow['user_id'], 'Appointment booked', 'Your appointment with ' . $doctor['name'] . ' is set for ' . $date . ' at ' . $time . '.', 'info');
        }
        audit_log($pdo, 'appointment_created', 'appointments', $newId, 'Appointment booked for ' . $date . ' ' . $time);
        api_ok(['message' => 'Appointment booked for ' . $date . ' at ' . $time . '.', 'id' => $newId]);
    }

    api_fail(422, 'Unsupported action.');
} catch (Throwable $e) {
    api_fail(500, 'Appointment request failed.');
}
