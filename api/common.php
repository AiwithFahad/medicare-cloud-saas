<?php
declare(strict_types=1);
/* Upgrade v2: shared bootstrap for the JSON API endpoints.
 * Mirrors the conventions of api/search.php / api/notifications.php:
 * session auth via includes/auth.php, JSON errors, tenant kill switch,
 * CSRF accepted via the X-CSRF header or the _csrf field. */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/panel.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function api_fail(int $code, string $message): void
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_SLASHES);
    exit;
}

function api_ok(array $data = []): void
{
    echo json_encode(array_merge(['ok' => true], $data), JSON_UNESCAPED_SLASHES);
    exit;
}

/** Authenticated user + PDO; blocks suspended tenants like the HTML app. */
function api_user(): array
{
    $user = require_login('/auth/login.php');
    if (!is_super_admin()) {
        check_tenant_active(db());
    }
    return $user;
}

/** Tenant id for tenant-scoped endpoints (super admins get a 403). */
function api_tenant(): int
{
    $tid = tenant_id();
    if ($tid === null) {
        api_fail(403, 'This endpoint is hospital-scoped. Sign in with a hospital account.');
    }
    return (int)$tid;
}

function api_require_permission(string $slug): void
{
    if (!has_permission($slug)) {
        api_fail(403, 'You do not have permission to perform this action.');
    }
}

/** CSRF check for POST endpoints (JSON 419 instead of the HTML helper). */
function api_verify_csrf(): void
{
    $supplied = (string)($_SERVER['HTTP_X_CSRF'] ?? ($_POST['_csrf'] ?? ''));
    if ($supplied === '' && str_contains((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
        $json = json_decode((string)file_get_contents('php://input'), true);
        if (is_array($json)) {
            $supplied = (string)($json['_csrf'] ?? '');
            $_POST = array_merge($_POST, $json);
        }
    }
    if (!hash_equals($_SESSION['csrf'] ?? '', $supplied)) {
        api_fail(419, 'CSRF token mismatch. Please reload the page and try again.');
    }
}

function api_page_params(int $defaultPerPage = 10): array
{
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = min(100, max(1, (int)($_GET['per_page'] ?? $defaultPerPage)));
    return [$page, $perPage, ($page - 1) * $perPage];
}
