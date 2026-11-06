<?php
declare(strict_types=1);

/*
 * /api/notifications.php — JSON notifications endpoint.
 *
 * GET  → { "notifications": [...], "unread": 3, "csrf_token": "..." }
 *        Latest 10 notifications for the current user: personal rows
 *        (user_id = current user) plus tenant broadcasts
 *        (tenant_id = user's tenant AND user_id IS NULL). Super admins
 *        see their personal rows plus platform broadcasts (tenant_id IS NULL).
 *
 * POST → action=read marks all of the current user's notifications read.
 *        Requires a valid CSRF token, sent either as the `X-CSRF` header
 *        or as the `_csrf` POST field (same token as csrf_token()).
 *        Optional `id` marks a single notification read.
 *        Response: { "ok": true, "updated": 3, "unread": 0 }
 *
 * Requires an authenticated session (includes/auth.php → require_login).
 * All queries are prepared statements and tenant-scoped.
 */

require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'POST'], true)) {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed. Use GET or POST.']);
    exit;
}

$user     = require_login('/auth/login.php');
$pdo      = db();
$userId   = (int)$user['id'];
$tenantId = tenant_id(); // null for super admin

if (!is_super_admin()) {
    check_tenant_active($pdo);
}

/**
 * Scope clause shared by the read / count / update queries.
 * Returns [sqlFragment, params] matching personal + broadcast rows.
 */
$scope = static function () use ($userId, $tenantId): array {
    if ($tenantId === null) {
        // Super admin: personal rows + platform-wide broadcasts.
        return ['(user_id = ? OR (tenant_id IS NULL AND user_id IS NULL))', [$userId]];
    }
    return ['(user_id = ? OR (tenant_id = ? AND user_id IS NULL))', [$userId, $tenantId]];
};

try {
    if ($method === 'GET') {
        [$where, $params] = $scope();

        $stmt = $pdo->prepare(
            "SELECT id, tenant_id, title, body, type, is_read, created_at
               FROM notifications
              WHERE {$where}
              ORDER BY id DESC
              LIMIT 10"
        );
        $stmt->execute($params);
        $notifications = [];
        foreach ($stmt->fetchAll() as $row) {
            $notifications[] = [
                'id'         => (int)$row['id'],
                'tenant_id'  => $row['tenant_id'] !== null ? (int)$row['tenant_id'] : null,
                'title'      => (string)$row['title'],
                'body'       => $row['body'] !== null ? (string)$row['body'] : null,
                'type'       => (string)$row['type'],
                'is_read'    => (int)$row['is_read'] === 1,
                'created_at' => (string)$row['created_at'],
            ];
        }

        $countStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM notifications WHERE {$where} AND is_read = 0"
        );
        $countStmt->execute($params);
        $unread = (int)$countStmt->fetchColumn();

        echo json_encode([
            'notifications' => $notifications,
            'unread'        => $unread,
            'csrf_token'    => csrf_token(),
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    // ---- POST: mark read ----
    // CSRF check mirroring verify_csrf() in includes/functions.php, extended
    // to accept the X-CSRF header so fetch()/XHR callers can use it. The HTML
    // helper exits with a plain-text 419; the API returns JSON instead.
    $supplied = (string)($_SERVER['HTTP_X_CSRF'] ?? ($_POST['_csrf'] ?? ''));
    if (!empty($_SERVER['CONTENT_TYPE'])
        && str_contains((string)$_SERVER['CONTENT_TYPE'], 'application/json')) {
        $raw  = file_get_contents('php://input') ?: '';
        $json = json_decode($raw, true);
        if (is_array($json)) {
            if ($supplied === '') {
                $supplied = (string)($json['_csrf'] ?? '');
            }
            // Allow JSON callers to pass the action/id in the body too.
            $_POST = array_merge($_POST, $json);
        }
    }
    if (!hash_equals($_SESSION['csrf'] ?? '', $supplied)) {
        http_response_code(419);
        echo json_encode(['error' => 'CSRF token mismatch.']);
        exit;
    }

    $action = (string)($_POST['action'] ?? '');
    if ($action !== 'read') {
        http_response_code(422);
        echo json_encode(['error' => 'Unsupported action. Use action=read.']);
        exit;
    }

    [$where, $params] = $scope();

    $singleId = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    if ($singleId > 0) {
        $stmt = $pdo->prepare(
            "UPDATE notifications SET is_read = 1 WHERE id = ? AND {$where} AND is_read = 0"
        );
        $stmt->execute(array_merge([$singleId], $params));
    } else {
        $stmt = $pdo->prepare(
            "UPDATE notifications SET is_read = 1 WHERE {$where} AND is_read = 0"
        );
        $stmt->execute($params);
    }
    $updated = (int)$stmt->rowCount();

    $countStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM notifications WHERE {$where} AND is_read = 0"
    );
    $countStmt->execute($params);

    echo json_encode([
        'ok'      => true,
        'updated' => $updated,
        'unread'  => (int)$countStmt->fetchColumn(),
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Notifications request failed.']);
}
