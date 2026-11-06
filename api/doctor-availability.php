<?php
declare(strict_types=1);
/*
 * GET /api/doctor-availability.php?doctor_id=&date=&ignore=
 *
 * Free slots for one doctor on one date, computed from doctor_schedules
 * minus booked (scheduled/confirmed) appointments. Used by the booking
 * forms in the admin / receptionist / patient panels (MediCare.bindSlots).
 * Response: { ok, available, reason, slots: ["09:00", …],
 *             schedule: {start_time, end_time, slot_minutes} | null }
 */

require_once __DIR__ . '/common.php';

$user = api_user();
$pdo = db();
$tenantId = api_tenant();

try {
    if (!has_permission('appointments.view') && !has_permission('portal.view')) {
        api_fail(403, 'You do not have permission to view availability.');
    }
    $doctorId = (int)($_GET['doctor_id'] ?? 0);
    $date = (string)($_GET['date'] ?? '');
    $ignore = isset($_GET['ignore']) ? (int)$_GET['ignore'] : null;
    if ($doctorId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        api_fail(422, 'doctor_id and a valid date are required.');
    }
    $ds = $pdo->prepare('SELECT id FROM doctors WHERE id = ? AND tenant_id = ?');
    $ds->execute([$doctorId, $tenantId]);
    if (!$ds->fetch()) {
        api_fail(404, 'Doctor not found.');
    }
    $result = doctor_free_slots($pdo, $tenantId, $doctorId, $date, $ignore);
    api_ok([
        'available' => $result['available'],
        'reason' => $result['reason'],
        'slots' => $result['slots'],
        'schedule' => $result['schedule'] ? [
            'start_time' => substr((string)$result['schedule']['start_time'], 0, 5),
            'end_time' => substr((string)$result['schedule']['end_time'], 0, 5),
            'slot_minutes' => (int)$result['schedule']['slot_minutes'],
        ] : null,
    ]);
} catch (Throwable $e) {
    api_fail(500, 'Availability request failed.');
}
