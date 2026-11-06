<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';

/*
 * Authentication & authorization guards.
 * Role slugs: super_admin, hospital_admin, doctor, receptionist, nurse,
 * accountant, pharmacist, lab_technician, patient.
 * Every protected page calls require_login()/require_role() and, for tenant
 * pages, require_tenant() so a user can never act outside their tenant.
 */

function load_user_permissions(PDO $pdo, int $roleId): array
{
    $s = $pdo->prepare('SELECT p.slug FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = ?');
    $s->execute([$roleId]);
    return $s->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

function establish_session(array $row, array $permissions): void
{
    $_SESSION['user'] = [
        'id'          => (int)$row['id'],
        'tenant_id'   => $row['tenant_id'] !== null ? (int)$row['tenant_id'] : null,
        'name'        => $row['name'],
        'email'       => $row['email'],
        'role'        => $row['role_slug'],
        'permissions' => $permissions,
    ];
}

function require_login(?string $loginUrl = null): array
{
    $u = current_user();
    if (!$u) {
        // Remember-me auto-login (token stored as sha256 hash in users.remember_token)
        if (!empty($_COOKIE['remember'])) {
            [$id, $token] = array_pad(explode(':', (string)$_COOKIE['remember'], 2), 2, '');
            if (ctype_digit($id) && $token !== '') {
                $s = db()->prepare('SELECT u.*, r.slug AS role_slug FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ? AND u.status = "active"');
                $s->execute([(int)$id]);
                $row = $s->fetch();
                if ($row && $row['remember_token'] && hash_equals((string)$row['remember_token'], hash('sha256', $token))) {
                    establish_session($row, load_user_permissions(db(), (int)$row['role_id']));
                    return $_SESSION['user'];
                }
            }
        }
        redirect($loginUrl ?? '/auth/login.php');
    }
    return $u;
}

function require_role(array $roles, ?string $loginUrl = null): array
{
    $u = require_login($loginUrl);
    if (!in_array($u['role'], $roles, true)) {
        http_response_code(403);
        exit('Access denied for your role.');
    }
    return $u;
}

/** Tenant context is mandatory for hospital pages; super admins have none. */
function require_tenant(): int
{
    $t = tenant_id();
    if ($t === null) {
        http_response_code(403);
        exit('Tenant context required.');
    }
    return $t;
}

/** Suspended hospitals lose access immediately (platform-level kill switch). */
function check_tenant_active(PDO $pdo): void
{
    $t = tenant_id();
    if ($t === null) return;
    $s = $pdo->prepare('SELECT status FROM tenants WHERE id = ?');
    $s->execute([$t]);
    if ($s->fetchColumn() === 'suspended') {
        $_SESSION = [];
        session_destroy();
        redirect('/auth/login.php?error=suspended');
    }
}
