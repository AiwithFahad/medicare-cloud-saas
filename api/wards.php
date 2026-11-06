<?php
declare(strict_types=1);
/*
 * /api/wards.php — live bed board (tenant-scoped).
 *
 * GET                          → wards with beds + current patient names (wards.view)
 * POST action=bed_status       → set a bed available / reserved / maintenance,
 *                                or mark it occupied for a patient  (wards.manage)
 * Occupancy changes made here only move the bed board; formal admission and
 * discharge (with billing) run through the admissions workflow.
 */

require_once __DIR__ . '/common.php';

$user = api_user();
$pdo = db();
$tenantId = api_tenant();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($method === 'GET') {
        api_require_permission('wards.view');
        $wards = $pdo->prepare('SELECT * FROM wards WHERE tenant_id = ? ORDER BY name');
        $wards->execute([$tenantId]);
        $beds = $pdo->prepare(
            'SELECT b.*, p.name AS patient_name, p.patient_code
               FROM beds b LEFT JOIN patients p ON p.id = b.current_patient_id
              WHERE b.tenant_id = ? ORDER BY b.bed_number'
        );
        $beds->execute([$tenantId]);
        $byWard = [];
        foreach ($beds->fetchAll() as $bed) {
            $byWard[(int)$bed['ward_id']][] = [
                'id' => (int)$bed['id'],
                'bed_number' => (string)$bed['bed_number'],
                'status' => (string)$bed['status'],
                'patient_id' => $bed['current_patient_id'] !== null ? (int)$bed['current_patient_id'] : null,
                'patient_name' => $bed['patient_name'],
                'patient_code' => $bed['patient_code'],
            ];
        }
        $out = [];
        foreach ($wards->fetchAll() as $w) {
            $list = $byWard[(int)$w['id']] ?? [];
            $out[] = [
                'id' => (int)$w['id'],
                'name' => (string)$w['name'],
                'type' => (string)$w['type'],
                'total_beds' => count($list),
                'occupied' => count(array_filter($list, static fn($b) => $b['status'] === 'occupied')),
                'beds' => $list,
            ];
        }
        api_ok(['wards' => $out]);
    }

    if ($method !== 'POST') {
        api_fail(405, 'Method not allowed.');
    }
    api_verify_csrf();
    api_require_permission('wards.manage');
    if ((string)($_POST['action'] ?? '') !== 'bed_status') {
        api_fail(422, 'Unsupported action.');
    }
    $bedId = (int)($_POST['bed_id'] ?? 0);
    $status = (string)($_POST['status'] ?? '');
    if (!in_array($status, ['available', 'occupied', 'reserved', 'maintenance'], true)) {
        api_fail(422, 'Invalid bed status.');
    }
    $patientId = null;
    if ($status === 'occupied') {
        $patientId = (int)($_POST['patient_id'] ?? 0);
        $pc = $pdo->prepare('SELECT COUNT(*) FROM patients WHERE id = ? AND tenant_id = ?');
        $pc->execute([$patientId, $tenantId]);
        if ($patientId <= 0 || (int)$pc->fetchColumn() === 0) {
            api_fail(422, 'Choose the patient occupying this bed.');
        }
    }
    $upd = $pdo->prepare('UPDATE beds SET status = ?, current_patient_id = ? WHERE id = ? AND tenant_id = ?');
    $upd->execute([$status, $patientId, $bedId, $tenantId]);
    if ($upd->rowCount() === 0) {
        $chk = $pdo->prepare('SELECT COUNT(*) FROM beds WHERE id = ? AND tenant_id = ?');
        $chk->execute([$bedId, $tenantId]);
        if ((int)$chk->fetchColumn() === 0) {
            api_fail(404, 'Bed not found.');
        }
    }
    audit_log($pdo, 'bed_status_changed', 'beds', $bedId, 'Bed marked ' . $status);
    api_ok(['message' => 'Bed marked ' . $status . '.']);
} catch (Throwable $e) {
    api_fail(500, 'Ward request failed.');
}
