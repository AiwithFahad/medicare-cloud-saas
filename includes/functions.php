<?php
declare(strict_types=1);

/* -------------------------------------------------------------------------
 * Shared helpers for MediCare Cloud. Every page loads these via
 * includes/auth.php (which requires config/database.php first).
 * ---------------------------------------------------------------------- */

/** HTML-escape any value for output (XSS prevention). */
function e(?string $v): string
{
    return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/* ---------------- CSRF ---------------- */
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}
function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}
function verify_csrf(): void
{
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['_csrf'] ?? ''))) {
        http_response_code(419);
        exit('CSRF token mismatch. Please go back and try again.');
    }
}

/* ---------------- Flash messages ---------------- */
function flash(string $key, ?string $msg = null): ?string
{
    if ($msg !== null) {
        $_SESSION['flash'][$key] = $msg;
        return $msg;
    }
    $v = $_SESSION['flash'][$key] ?? null;
    unset($_SESSION['flash'][$key]);
    return $v;
}

/* ---------------- Session / identity ---------------- */
function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}
function tenant_id(): ?int
{
    $u = current_user();
    return isset($u['tenant_id']) && $u['tenant_id'] !== null ? (int)$u['tenant_id'] : null;
}
function is_super_admin(): bool
{
    return (current_user()['role'] ?? '') === 'super_admin';
}
/** Permission slugs are loaded into the session at login from role_permissions. */
function has_permission(string $slug): bool
{
    $u = current_user();
    if (!$u) return false;
    if (($u['role'] ?? '') === 'super_admin') return true;
    return in_array($slug, $u['permissions'] ?? [], true);
}

/* ---------------- Formatting ---------------- */
function money($n): string
{
    return '$' . number_format((float)$n, 2);
}
function fmt_date(?string $d): string
{
    if (!$d || $d === '0000-00-00') return '—';
    $t = strtotime($d);
    return $t ? date('d M Y', $t) : e($d);
}
function fmt_datetime(?string $d): string
{
    if (!$d) return '—';
    $t = strtotime($d);
    return $t ? date('d M Y, h:i A', $t) : e($d);
}
function initials(string $name): string
{
    $ini = '';
    foreach (array_slice(preg_split('/\s+/', trim($name)) ?: [], 0, 2) as $w) {
        if ($w !== '') $ini .= strtoupper(substr($w, 0, 1));
    }
    return $ini === '' ? 'U' : $ini;
}
function status_badge(string $status): string
{
    $map = [
        'active' => 'success', 'paid' => 'success', 'completed' => 'success', 'confirmed' => 'success',
        'available' => 'success', 'verified' => 'success', 'dispensed' => 'success', 'discharged' => 'secondary',
        'pending' => 'warning', 'trial' => 'info', 'scheduled' => 'info', 'partial' => 'warning',
        'partially_dispensed' => 'warning', 'in_progress' => 'info', 'occupied' => 'danger',
        'admitted' => 'primary', 'unpaid' => 'danger', 'overdue' => 'danger', 'expired' => 'danger',
        'cancelled' => 'secondary', 'suspended' => 'danger', 'failed' => 'danger', 'open' => 'primary',
        'resolved' => 'success', 'closed' => 'secondary', 'reserved' => 'warning', 'maintenance' => 'secondary',
        'no_show' => 'dark', 'refunded' => 'secondary', 'past_due' => 'warning',
    ];
    $cls = $map[strtolower($status)] ?? 'secondary';
    return '<span class="badge text-bg-' . $cls . '">' . e(ucwords(str_replace('_', ' ', $status))) . '</span>';
}

/* ---------------- Logging ---------------- */
function audit_log(PDO $pdo, string $action, string $entity = '', ?int $entityId = null, string $details = ''): void
{
    try {
        $u = current_user();
        $s = $pdo->prepare('INSERT INTO audit_logs(tenant_id,user_id,action,entity,entity_id,details,ip_address,created_at) VALUES(?,?,?,?,?,?,?,NOW())');
        $s->execute([$u['tenant_id'] ?? null, $u['id'] ?? null, $action, $entity, $entityId, $details, $_SERVER['REMOTE_ADDR'] ?? null]);
    } catch (Throwable $ignored) {
    }
}
function log_activity(PDO $pdo, string $text): void
{
    try {
        $s = $pdo->prepare('INSERT INTO activity_logs(tenant_id,user_id,description,ip_address,created_at) VALUES(?,?,?,?,NOW())');
        $s->execute([tenant_id(), current_user()['id'] ?? null, $text, $_SERVER['REMOTE_ADDR'] ?? null]);
    } catch (Throwable $ignored) {
    }
}
function notify_user(PDO $pdo, ?int $tenantId, ?int $userId, string $title, string $body, string $type = 'info'): void
{
    try {
        $s = $pdo->prepare('INSERT INTO notifications(tenant_id,user_id,title,body,type,is_read,created_at) VALUES(?,?,?,?,?,0,NOW())');
        $s->execute([$tenantId, $userId, $title, $body, $type]);
    } catch (Throwable $ignored) {
    }
}

/* ---------------- Subscriptions & plan limits ---------------- */
/** Current subscription for a tenant joined with its plan (trial/active/past_due). */
function active_subscription(PDO $pdo, int $tenantId): ?array
{
    $s = $pdo->prepare(
        'SELECT s.*, p.name AS plan_name, p.slug AS plan_slug, p.monthly_price, p.yearly_price,
                p.max_doctors, p.max_staff, p.max_patients, p.max_departments, p.max_storage_mb, p.features
           FROM subscriptions s
           JOIN subscription_plans p ON p.id = s.plan_id
          WHERE s.tenant_id = ? AND s.status IN ("trial","active","past_due")
          ORDER BY s.id DESC LIMIT 1'
    );
    $s->execute([$tenantId]);
    $row = $s->fetch();
    return $row ?: null;
}

/** Live usage counts for the metered resources of a tenant. */
function usage_counts(PDO $pdo, int $tenantId): array
{
    $count = function (string $sql) use ($pdo, $tenantId): int {
        $s = $pdo->prepare($sql);
        $s->execute([$tenantId]);
        return (int)$s->fetchColumn();
    };
    return [
        'doctors'     => $count('SELECT COUNT(*) FROM doctors WHERE tenant_id=?'),
        'staff'       => $count('SELECT COUNT(*) FROM users WHERE tenant_id=? AND status="active"'),
        'patients'    => $count('SELECT COUNT(*) FROM patients WHERE tenant_id=?'),
        'departments' => $count('SELECT COUNT(*) FROM departments WHERE tenant_id=?'),
    ];
}

/** True when the tenant has reached the plan limit for a metered resource. */
function plan_limit_reached(PDO $pdo, int $tenantId, string $metric): bool
{
    $sub = active_subscription($pdo, $tenantId);
    if (!$sub) return true; // no subscription -> nothing may be created
    $col = ['doctors' => 'max_doctors', 'staff' => 'max_staff', 'patients' => 'max_patients', 'departments' => 'max_departments'][$metric] ?? null;
    if ($col === null) return false;
    $limit = (int)($sub[$col] ?? 0);
    if ($limit < 0) return false; // -1 = unlimited (Enterprise)
    $usage = usage_counts($pdo, $tenantId);
    return ($usage[$metric] ?? 0) >= $limit;
}

/* ---------------- Misc ---------------- */
function export_csv(array $rows, string $filename): void
{
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    foreach ($rows as $i => $r) {
        if ($i === 0) fputcsv($out, array_keys($r));
        fputcsv($out, $r);
    }
    fclose($out);
    exit;
}
function slugify(string $text): string
{
    $slug = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $text) ?? ''));
    return trim($slug, '-') ?: 'tenant';
}
/** Simple pagination helper: returns [limit, offset, page]. */
function page_params(int $perPage = 15): array
{
    $page = max(1, (int)($_GET['page'] ?? 1));
    return [$perPage, ($page - 1) * $perPage, $page];
}
function pager_html(int $total, int $perPage, int $page, string $param = 'page'): string
{
    $pages = (int)ceil($total / max(1, $perPage));
    if ($pages <= 1) return '';
    $q = $_GET;
    $html = '<nav><ul class="pagination pagination-sm mb-0">';
    for ($i = 1; $i <= $pages; $i++) {
        $q[$param] = $i;
        $html .= '<li class="page-item' . ($i === $page ? ' active' : '') . '"><a class="page-link" href="?' . e(http_build_query($q)) . '">' . $i . '</a></li>';
    }
    return $html . '</ul></nav>';
}
