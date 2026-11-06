<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';

$pdo  = db();
$user = current_user();

if ($user && !empty($user['id'])) {
    try {
        $pdo->prepare('UPDATE users SET remember_token = NULL WHERE id = ?')
            ->execute([(int)$user['id']]);
        audit_log($pdo, 'logout', 'users', (int)$user['id'], 'User signed out');
    } catch (Throwable $ignored) {
    }
}

// Expire the remember-me cookie.
setcookie('remember', '', [
    'expires'  => time() - 3600,
    'path'     => '/',
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool)($params['secure'] ?? false), (bool)($params['httponly'] ?? true));
}
session_destroy();

redirect('login.php');
