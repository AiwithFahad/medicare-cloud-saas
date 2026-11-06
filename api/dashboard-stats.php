<?php
declare(strict_types=1);
/*
 * GET /api/dashboard-stats.php — real dashboard aggregates + Chart.js series.
 *
 * Tenant scope (default): every number is a live query against the caller's
 * tenant_id. Optional query params:
 *   from=YYYY-MM-DD & to=YYYY-MM-DD  → revenue chart window (default: 6 months)
 *   doctor_id=N                      → restrict appointment series to a doctor
 *
 * Platform scope (?scope=platform, super admin only): tenant counts, MRR/ARR,
 * verified + pending platform payments, revenue by month, subscriptions by plan.
 */

require_once __DIR__ . '/common.php';

$user = api_user();
$pdo = db();

try {
    if (($_GET['scope'] ?? '') === 'platform') {
        if (!is_super_admin()) {
            api_fail(403, 'Platform scope requires a super admin account.');
        }
        $scalar = static fn(string $sql) => $pdo->query($sql)->fetchColumn();
        $mrr = (float)$scalar(
            "SELECT COALESCE(SUM(CASE WHEN s.billing_cycle = 'yearly' THEN p.yearly_price / 12 ELSE p.monthly_price END), 0)
               FROM subscriptions s JOIN subscription_plans p ON p.id = s.plan_id
              WHERE s.status IN ('active','trial')"
        );
        $revRows = $pdo->query(
            "SELECT DATE_FORMAT(COALESCE(paid_at, created_at), '%Y-%m') AS ym, COALESCE(SUM(amount),0) AS total
               FROM payments WHERE status = 'verified'
                 AND COALESCE(paid_at, created_at) >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 5 MONTH), '%Y-%m-01')
              GROUP BY ym"
        )->fetchAll();
        $revMap = [];
        foreach ($revRows as $r) {
            $revMap[$r['ym']] = (float)$r['total'];
        }
        $revLabels = $revData = [];
        for ($i = 5; $i >= 0; $i--) {
            $ts = strtotime("-{$i} months");
            $revLabels[] = date('M Y', $ts);
            $revData[] = $revMap[date('Y-m', $ts)] ?? 0;
        }
        $planRows = $pdo->query(
            'SELECT p.name AS plan_name, COUNT(*) AS c FROM subscriptions s
               JOIN subscription_plans p ON p.id = s.plan_id GROUP BY p.id, p.name ORDER BY c DESC'
        )->fetchAll();
        api_ok([
            'stats' => [
                'total_tenants'     => (int)$scalar('SELECT COUNT(*) FROM tenants'),
                'active_tenants'    => (int)$scalar("SELECT COUNT(*) FROM tenants WHERE status = 'active'"),
                'pending_tenants'   => (int)$scalar("SELECT COUNT(*) FROM tenants WHERE status = 'pending'"),
                'suspended_tenants' => (int)$scalar("SELECT COUNT(*) FROM tenants WHERE status = 'suspended'"),
                'total_users'       => (int)$scalar('SELECT COUNT(*) FROM users'),
                'active_subs'       => (int)$scalar("SELECT COUNT(*) FROM subscriptions WHERE status IN ('active','trial')"),
                'trial_subs'        => (int)$scalar("SELECT COUNT(*) FROM subscriptions WHERE status = 'trial'"),
                'mrr'               => round($mrr, 2),
                'arr'               => round($mrr * 12, 2),
                'verified_revenue'  => (float)$scalar("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status = 'verified'"),
                'pending_payments'  => (int)$scalar("SELECT COUNT(*) FROM payments WHERE status = 'pending'"),
                'open_tickets'      => (int)$scalar("SELECT COUNT(*) FROM support_tickets WHERE status IN ('open','in_progress')"),
            ],
            'charts' => [
                'revenue' => ['labels' => $revLabels, 'data' => $revData],
                'plans'   => [
                    'labels' => array_map(static fn($r) => (string)$r['plan_name'], $planRows),
                    'data'   => array_map(static fn($r) => (int)$r['c'], $planRows),
                ],
            ],
        ]);
    }

    /* ------------------------- Tenant (hospital) scope ------------------------- */
    $tenantId = api_tenant();
    $doctorFilter = isset($_GET['doctor_id']) ? (int)$_GET['doctor_id'] : 0;

    $one = static function (string $sql, array $params = []) use ($pdo) {
        $s = $pdo->prepare($sql);
        $s->execute($params);
        return $s->fetchColumn();
    };

    $apptScope = 'tenant_id = ?';
    $apptParams = [$tenantId];
    if ($doctorFilter > 0) {
        $apptScope .= ' AND doctor_id = ?';
        $apptParams[] = $doctorFilter;
    }

    $stats = [
        'total_patients'        => (int)$one('SELECT COUNT(*) FROM patients WHERE tenant_id = ?', [$tenantId]),
        'new_patients_today'    => (int)$one('SELECT COUNT(*) FROM patients WHERE tenant_id = ? AND DATE(created_at) = CURDATE()', [$tenantId]),
        'total_doctors'         => (int)$one("SELECT COUNT(*) FROM doctors WHERE tenant_id = ?", [$tenantId]),
        'available_doctors'     => (int)$one("SELECT COUNT(DISTINCT d.id) FROM doctors d JOIN doctor_schedules ds ON ds.doctor_id = d.id AND ds.tenant_id = d.tenant_id WHERE d.tenant_id = ? AND d.status = 'active' AND ds.day_of_week = MOD(WEEKDAY(CURDATE()) + 1, 7) AND ds.is_available = 1", [$tenantId]),
        'appointments_today'    => (int)$one("SELECT COUNT(*) FROM appointments WHERE {$apptScope} AND appointment_date = CURDATE()", $apptParams),
        'appointments_pending'  => (int)$one("SELECT COUNT(*) FROM appointments WHERE {$apptScope} AND status = 'scheduled'", $apptParams),
        'appointments_completed' => (int)$one("SELECT COUNT(*) FROM appointments WHERE {$apptScope} AND status = 'completed'", $apptParams),
        'appointments_cancelled' => (int)$one("SELECT COUNT(*) FROM appointments WHERE {$apptScope} AND status = 'cancelled'", $apptParams),
        'total_revenue'         => (float)$one('SELECT COALESCE(SUM(amount),0) FROM patient_payments WHERE tenant_id = ?', [$tenantId]),
        'revenue_this_month'    => (float)$one('SELECT COALESCE(SUM(amount),0) FROM patient_payments WHERE tenant_id = ? AND DATE_FORMAT(paid_at, "%Y-%m") = DATE_FORMAT(CURDATE(), "%Y-%m")', [$tenantId]),
        'pending_invoices'      => (int)$one("SELECT COUNT(*) FROM patient_invoices WHERE tenant_id = ? AND status IN ('unpaid','partial')", [$tenantId]),
        'pending_invoice_amount' => (float)$one("SELECT COALESCE(SUM(total - amount_paid),0) FROM patient_invoices WHERE tenant_id = ? AND status IN ('unpaid','partial')", [$tenantId]),
        'beds_total'            => (int)$one('SELECT COUNT(*) FROM beds WHERE tenant_id = ?', [$tenantId]),
        'beds_available'        => (int)$one("SELECT COUNT(*) FROM beds WHERE tenant_id = ? AND status = 'available'", [$tenantId]),
        'beds_occupied'         => (int)$one("SELECT COUNT(*) FROM beds WHERE tenant_id = ? AND status = 'occupied'", [$tenantId]),
        'admissions_active'     => (int)$one("SELECT COUNT(*) FROM admissions WHERE tenant_id = ? AND status = 'admitted'", [$tenantId]),
        'emergency_today'       => (int)$one('SELECT COUNT(*) FROM emergency_cases WHERE tenant_id = ? AND DATE(arrival_at) = CURDATE()', [$tenantId]),
        'emergency_active'      => (int)$one("SELECT COUNT(*) FROM emergency_cases WHERE tenant_id = ? AND status IN ('waiting','in_treatment')", [$tenantId]),
        'stock_alerts'          => (int)$one('SELECT COUNT(*) FROM medicines WHERE tenant_id = ? AND stock <= reorder_level', [$tenantId]),
        'expired_medicines'     => (int)$one('SELECT COUNT(*) FROM medicines WHERE tenant_id = ? AND expiry_date IS NOT NULL AND expiry_date < CURDATE()', [$tenantId]),
        'lab_pending'           => (int)$one("SELECT COUNT(*) FROM lab_results WHERE tenant_id = ? AND status IN ('pending','in_progress')", [$tenantId]),
        'reminders_due'         => (int)$one("SELECT COUNT(*) FROM reminders WHERE tenant_id = ? AND status = 'pending' AND remind_at <= DATE_ADD(NOW(), INTERVAL 24 HOUR)", [$tenantId]),
        'expenses_this_month'   => (float)$one('SELECT COALESCE(SUM(amount),0) FROM expenses WHERE tenant_id = ? AND DATE_FORMAT(expense_date, "%Y-%m") = DATE_FORMAT(CURDATE(), "%Y-%m")', [$tenantId]),
    ];

    /* Weekly patient registrations — last 7 days, zero-filled. */
    $s = $pdo->prepare('SELECT DATE(created_at) AS d, COUNT(*) AS c FROM patients WHERE tenant_id = ? AND created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) GROUP BY d');
    $s->execute([$tenantId]);
    $regMap = [];
    foreach ($s->fetchAll() as $r) {
        $regMap[$r['d']] = (int)$r['c'];
    }
    $wkLabels = $wkData = [];
    for ($i = 6; $i >= 0; $i--) {
        $ts = strtotime("-{$i} days");
        $wkLabels[] = date('D', $ts);
        $wkData[] = $regMap[date('Y-m-d', $ts)] ?? 0;
    }

    /* Monthly appointments — last 6 months, zero-filled. */
    $s = $pdo->prepare("SELECT DATE_FORMAT(appointment_date, '%Y-%m') AS ym, COUNT(*) AS c FROM appointments WHERE {$apptScope} AND appointment_date >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 5 MONTH), '%Y-%m-01') GROUP BY ym");
    $s->execute($apptParams);
    $moMap = [];
    foreach ($s->fetchAll() as $r) {
        $moMap[$r['ym']] = (int)$r['c'];
    }
    $moLabels = $moData = [];
    for ($i = 5; $i >= 0; $i--) {
        $ts = strtotime("-{$i} months");
        $moLabels[] = date('M Y', $ts);
        $moData[] = $moMap[date('Y-m', $ts)] ?? 0;
    }

    /* Revenue analytics — patient payments per month over the requested window. */
    $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['from'] ?? '')) ? (string)$_GET['from'] : date('Y-m-01', strtotime('-5 months'));
    $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['to'] ?? '')) ? (string)$_GET['to'] : date('Y-m-d');
    $s = $pdo->prepare("SELECT DATE_FORMAT(paid_at, '%Y-%m') AS ym, COALESCE(SUM(amount),0) AS total FROM patient_payments WHERE tenant_id = ? AND paid_at BETWEEN ? AND ? GROUP BY ym");
    $s->execute([$tenantId, $from, $to]);
    $revMap = [];
    foreach ($s->fetchAll() as $r) {
        $revMap[$r['ym']] = (float)$r['total'];
    }
    $revLabels = $revData = [];
    $cursor = strtotime(date('Y-m-01', strtotime($from)));
    $last = strtotime(date('Y-m-01', strtotime($to)));
    while ($cursor <= $last) {
        $revLabels[] = date('M Y', $cursor);
        $revData[] = $revMap[date('Y-m', $cursor)] ?? 0;
        $cursor = strtotime('+1 month', $cursor);
    }

    /* Department performance — appointments + patients per department. */
    $deptRows = $pdo->prepare(
        'SELECT dep.name,
                (SELECT COUNT(*) FROM appointments a WHERE a.tenant_id = dep.tenant_id AND a.department_id = dep.id) AS appts,
                (SELECT COUNT(*) FROM doctors d WHERE d.tenant_id = dep.tenant_id AND d.department_id = dep.id) AS docs
           FROM departments dep WHERE dep.tenant_id = ? ORDER BY appts DESC'
    );
    $deptRows->execute([$tenantId]);
    $deptRows = $deptRows->fetchAll();

    /* Doctor performance — completed appointments per doctor. */
    $docRows = $pdo->prepare(
        "SELECT d.name,
                COUNT(a.id) AS total,
                SUM(a.status = 'completed') AS completed
           FROM doctors d
           LEFT JOIN appointments a ON a.doctor_id = d.id AND a.tenant_id = d.tenant_id
          WHERE d.tenant_id = ? GROUP BY d.id, d.name ORDER BY completed DESC, total DESC LIMIT 10"
    );
    $docRows->execute([$tenantId]);
    $docRows = $docRows->fetchAll();

    /* Admission trends — admissions per week, last 6 weeks, zero-filled. */
    $s = $pdo->prepare('SELECT YEARWEEK(admission_date, 1) AS yw, COUNT(*) AS c FROM admissions WHERE tenant_id = ? AND admission_date >= DATE_SUB(CURDATE(), INTERVAL 6 WEEK) GROUP BY yw');
    $s->execute([$tenantId]);
    $admMap = [];
    foreach ($s->fetchAll() as $r) {
        $admMap[(string)$r['yw']] = (int)$r['c'];
    }
    $admLabels = $admData = [];
    for ($i = 5; $i >= 0; $i--) {
        $ts = strtotime("-{$i} weeks");
        $admLabels[] = 'Wk of ' . date('d M', $ts);
        $admData[] = $admMap[date('oW', $ts)] ?? 0;
    }

    api_ok([
        'stats' => $stats,
        'charts' => [
            'weekly_registrations' => ['labels' => $wkLabels, 'data' => $wkData],
            'monthly_appointments' => ['labels' => $moLabels, 'data' => $moData],
            'revenue' => ['labels' => $revLabels, 'data' => $revData],
            'department_performance' => [
                'labels' => array_map(static fn($r) => (string)$r['name'], $deptRows),
                'appointments' => array_map(static fn($r) => (int)$r['appts'], $deptRows),
                'doctors' => array_map(static fn($r) => (int)$r['docs'], $deptRows),
            ],
            'doctor_performance' => [
                'labels' => array_map(static fn($r) => (string)$r['name'], $docRows),
                'completed' => array_map(static fn($r) => (int)$r['completed'], $docRows),
                'total' => array_map(static fn($r) => (int)$r['total'], $docRows),
            ],
            'admission_trends' => ['labels' => $admLabels, 'data' => $admData],
        ],
    ]);
} catch (Throwable $e) {
    api_fail(500, 'Dashboard statistics failed to load.');
}
