<?php
declare(strict_types=1);
/*
 * /api/patients.php — patient list + AJAX CRUD (tenant-scoped).
 *
 * GET  ?q=&gender=&page=&per_page=   → { rows, total, page, pages }  (patients.view)
 * POST action=create|update         → register / edit a patient     (patients.manage)
 *      action=delete                 → delete a patient              (patients.manage)
 * Patient IDs are generated server-side as PAT-0001, PAT-0002, …
 * New registrations respect the subscription plan's patient limit.
 */

require_once __DIR__ . '/common.php';

$user = api_user();
$pdo = db();
$tenantId = api_tenant();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($method === 'GET') {
        api_require_permission('patients.view');
        [$page, $perPage, $offset] = api_page_params();
        $q = trim((string)($_GET['q'] ?? ''));
        $gender = trim((string)($_GET['gender'] ?? ''));

        $where = 'tenant_id = ?';
        $params = [$tenantId];
        if ($q !== '') {
            $where .= ' AND (name LIKE ? OR patient_code LIKE ? OR phone LIKE ? OR email LIKE ?)';
            $like = '%' . $q . '%';
            array_push($params, $like, $like, $like, $like);
        }
        if (in_array($gender, ['male', 'female', 'other'], true)) {
            $where .= ' AND gender = ?';
            $params[] = $gender;
        }
        $count = $pdo->prepare("SELECT COUNT(*) FROM patients WHERE {$where}");
        $count->execute($params);
        $total = (int)$count->fetchColumn();

        $stmt = $pdo->prepare(
            "SELECT p.*,
                    (SELECT COUNT(*) FROM appointments a WHERE a.patient_id = p.id AND a.tenant_id = p.tenant_id) AS visits,
                    (SELECT COUNT(*) FROM admissions ad WHERE ad.patient_id = p.id AND ad.tenant_id = p.tenant_id AND ad.status = 'admitted') AS admitted_now
               FROM patients p WHERE {$where} ORDER BY p.id DESC LIMIT {$perPage} OFFSET {$offset}"
        );
        $stmt->execute($params);
        $rows = [];
        foreach ($stmt->fetchAll() as $r) {
            $rows[] = [
                'id' => (int)$r['id'],
                'patient_code' => (string)$r['patient_code'],
                'name' => (string)$r['name'],
                'gender' => $r['gender'],
                'dob' => $r['dob'],
                'age' => patient_age($r['dob']),
                'blood_group' => $r['blood_group'],
                'phone' => $r['phone'],
                'email' => $r['email'],
                'address' => $r['address'],
                'emergency_contact' => $r['emergency_contact'],
                'allergies' => $r['allergies'],
                'visits' => (int)$r['visits'],
                'admitted_now' => (int)$r['admitted_now'] === 1,
                'created_at' => (string)$r['created_at'],
            ];
        }
        api_ok(['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => max(1, (int)ceil($total / $perPage))]);
    }

    if ($method !== 'POST') {
        api_fail(405, 'Method not allowed.');
    }
    api_verify_csrf();
    api_require_permission('patients.manage');

    $action = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);

    if ($action === 'delete') {
        if ($id <= 0) {
            api_fail(422, 'Missing patient id.');
        }
        $del = $pdo->prepare('DELETE FROM patients WHERE id = ? AND tenant_id = ?');
        $del->execute([$id, $tenantId]);
        if ($del->rowCount() === 0) {
            api_fail(404, 'Patient not found.');
        }
        audit_log($pdo, 'patient_deleted', 'patients', $id, 'Patient deleted via API');
        api_ok(['message' => 'Patient deleted.']);
    }

    if (!in_array($action, ['create', 'update'], true)) {
        api_fail(422, 'Unsupported action.');
    }

    $name = trim((string)($_POST['name'] ?? ''));
    if ($name === '') {
        api_fail(422, 'Patient name is required.');
    }
    $gender = in_array(($_POST['gender'] ?? ''), ['male', 'female', 'other'], true) ? (string)$_POST['gender'] : null;
    $dob = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['dob'] ?? '')) ? (string)$_POST['dob'] : null;
    $fields = [
        'name' => $name,
        'gender' => $gender,
        'dob' => $dob,
        'blood_group' => trim((string)($_POST['blood_group'] ?? '')) ?: null,
        'phone' => trim((string)($_POST['phone'] ?? '')) ?: null,
        'email' => trim((string)($_POST['email'] ?? '')) ?: null,
        'address' => trim((string)($_POST['address'] ?? '')) ?: null,
        'emergency_contact' => trim((string)($_POST['emergency_contact'] ?? '')) ?: null,
        'allergies' => trim((string)($_POST['allergies'] ?? '')) ?: null,
    ];

    if ($action === 'create') {
        if (plan_limit_reached($pdo, $tenantId, 'patients')) {
            api_fail(422, 'Your plan\'s patient limit has been reached. Upgrade the subscription to register more patients.');
        }
        $ins = $pdo->prepare(
            'INSERT INTO patients (tenant_id, patient_code, name, gender, dob, blood_group, phone, email, address, emergency_contact, allergies)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $ins->execute([$tenantId, 'TMP-' . bin2hex(random_bytes(4)), $fields['name'], $fields['gender'], $fields['dob'], $fields['blood_group'], $fields['phone'], $fields['email'], $fields['address'], $fields['emergency_contact'], $fields['allergies']]);
        $newId = (int)$pdo->lastInsertId();
        $code = 'PAT-' . str_pad((string)$newId, 4, '0', STR_PAD_LEFT);
        $pdo->prepare('UPDATE patients SET patient_code = ? WHERE id = ?')->execute([$code, $newId]);
        audit_log($pdo, 'patient_created', 'patients', $newId, 'Patient registered: ' . $name . ' (' . $code . ')');
        log_activity($pdo, 'Patient registered: ' . $name);
        api_ok(['message' => 'Patient registered with ID ' . $code . '.', 'id' => $newId, 'patient_code' => $code]);
    }

    // update
    if ($id <= 0) {
        api_fail(422, 'Missing patient id.');
    }
    $upd = $pdo->prepare(
        'UPDATE patients SET name = ?, gender = ?, dob = ?, blood_group = ?, phone = ?, email = ?, address = ?, emergency_contact = ?, allergies = ?
          WHERE id = ? AND tenant_id = ?'
    );
    $upd->execute([$fields['name'], $fields['gender'], $fields['dob'], $fields['blood_group'], $fields['phone'], $fields['email'], $fields['address'], $fields['emergency_contact'], $fields['allergies'], $id, $tenantId]);
    if ($upd->rowCount() === 0) {
        // Row may exist with identical values; verify existence before 404.
        $chk = $pdo->prepare('SELECT COUNT(*) FROM patients WHERE id = ? AND tenant_id = ?');
        $chk->execute([$id, $tenantId]);
        if ((int)$chk->fetchColumn() === 0) {
            api_fail(404, 'Patient not found.');
        }
    }
    audit_log($pdo, 'patient_updated', 'patients', $id, 'Patient updated: ' . $name);
    api_ok(['message' => 'Patient updated.']);
} catch (Throwable $e) {
    api_fail(500, 'Patient request failed.');
}
