<?php
declare(strict_types=1);

/*
 * GET /api/search.php?q=...
 *
 * Tenant-scoped global search (JSON).
 * - Requires an authenticated session (includes/auth.php → require_login).
 * - Tenant users only ever see rows from their own tenant_id.
 * - Super admins (no tenant) receive platform tenant counts + tenant matches
 *   instead of patient/doctor data.
 *
 * Response (tenant user):
 *   {
 *     "query": "sarah",
 *     "patients": [{"id": 1, "name": "John Miller", "code": "PAT-0001"}],
 *     "doctors":  [{"id": 1, "name": "Dr. Sarah Khan", "specialization": "Interventional Cardiology"}],
 *     "appointments_today": 4
 *   }
 *
 * Response (super admin):
 *   {
 *     "query": "city",
 *     "tenants": [{"id": 1, "name": "City Care Hospital", "status": "active"}],
 *     "counts": {"total": 3, "active": 3, "suspended": 0},
 *     "appointments_today": 12
 *   }
 */

require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed. Use GET.']);
    exit;
}

$user = require_login('/auth/login.php');
$pdo  = db();

// Suspended-tenant kill switch — same check as the HTML app.
if (!is_super_admin()) {
    check_tenant_active($pdo);
}

$q = trim((string)($_GET['q'] ?? ''));

try {
    if (is_super_admin()) {
        // ---- Platform view: tenant counts + optional tenant name search ----
        $total     = (int)$pdo->query("SELECT COUNT(*) FROM tenants")->fetchColumn();
        $active    = (int)$pdo->query("SELECT COUNT(*) FROM tenants WHERE status = 'active'")->fetchColumn();
        $suspended = (int)$pdo->query("SELECT COUNT(*) FROM tenants WHERE status = 'suspended'")->fetchColumn();

        $tenants = [];
        if ($q !== '') {
            $stmt = $pdo->prepare(
                'SELECT id, name, status FROM tenants WHERE name LIKE ? ORDER BY name ASC LIMIT 10'
            );
            $stmt->execute(['%' . $q . '%']);
            foreach ($stmt->fetchAll() as $row) {
                $tenants[] = [
                    'id'     => (int)$row['id'],
                    'name'   => (string)$row['name'],
                    'status' => (string)$row['status'],
                ];
            }
        } else {
            $stmt = $pdo->query('SELECT id, name, status FROM tenants ORDER BY name ASC LIMIT 10');
            foreach ($stmt->fetchAll() as $row) {
                $tenants[] = [
                    'id'     => (int)$row['id'],
                    'name'   => (string)$row['name'],
                    'status' => (string)$row['status'],
                ];
            }
        }

        $apptStmt = $pdo->query('SELECT COUNT(*) FROM appointments WHERE appointment_date = CURDATE()');
        $appointmentsToday = (int)$apptStmt->fetchColumn();

        echo json_encode([
            'query'              => $q,
            'tenants'            => $tenants,
            'counts'             => [
                'total'     => $total,
                'active'    => $active,
                'suspended' => $suspended,
            ],
            'appointments_today' => $appointmentsToday,
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    // ---- Tenant view ----
    $tenantId = require_tenant();

    if ($q === '') {
        $countStmt = $pdo->prepare(
            'SELECT COUNT(*) FROM appointments WHERE tenant_id = ? AND appointment_date = CURDATE()'
        );
        $countStmt->execute([$tenantId]);

        echo json_encode([
            'query'              => '',
            'patients'           => [],
            'doctors'            => [],
            'appointments_today' => (int)$countStmt->fetchColumn(),
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    $like = '%' . $q . '%';

    $patientStmt = $pdo->prepare(
        'SELECT id, name, patient_code
           FROM patients
          WHERE tenant_id = ? AND (name LIKE ? OR patient_code LIKE ?)
          ORDER BY name ASC
          LIMIT 10'
    );
    $patientStmt->execute([$tenantId, $like, $like]);
    $patients = [];
    foreach ($patientStmt->fetchAll() as $row) {
        $patients[] = [
            'id'   => (int)$row['id'],
            'name' => (string)$row['name'],
            'code' => (string)$row['patient_code'],
        ];
    }

    $doctorStmt = $pdo->prepare(
        'SELECT id, name, specialization
           FROM doctors
          WHERE tenant_id = ? AND name LIKE ?
          ORDER BY name ASC
          LIMIT 10'
    );
    $doctorStmt->execute([$tenantId, $like]);
    $doctors = [];
    foreach ($doctorStmt->fetchAll() as $row) {
        $doctors[] = [
            'id'             => (int)$row['id'],
            'name'           => (string)$row['name'],
            'specialization' => $row['specialization'] !== null ? (string)$row['specialization'] : null,
        ];
    }

    $countStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM appointments WHERE tenant_id = ? AND appointment_date = CURDATE()'
    );
    $countStmt->execute([$tenantId]);

    echo json_encode([
        'query'              => $q,
        'patients'           => $patients,
        'doctors'            => $doctors,
        'appointments_today' => (int)$countStmt->fetchColumn(),
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Search failed.']);
}
