<?php
declare(strict_types=1);

/*
 * MediCare Cloud — application configuration.
 * Values come from environment variables (see .env.example); the fallbacks
 * below are local-development placeholders only, never production secrets.
 */
define('APP_NAME', 'MediCare Cloud');
define('APP_TAGLINE', 'Hospital Management SaaS');
define('APP_ENV', getenv('APP_ENV') ?: 'production');
define('APP_URL', getenv('APP_URL') ?: '');

define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_NAME', getenv('DB_NAME') ?: 'medicare_cloud');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_CHARSET', 'utf8mb4');

define('TRIAL_DAYS', 14);
define('GRACE_DAYS', 7);
define('LOGIN_MAX_ATTEMPTS', 5);
define('LOGIN_LOCK_MINUTES', 15);
define('CURRENCY', 'USD');

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
}
date_default_timezone_set(getenv('APP_TZ') ?: 'Asia/Karachi');
