<?php

declare(strict_types=1);

require_once __DIR__ . '/Core/helpers.php';

spl_autoload_register(function (string $class): void {
    $prefix = 'Khatauat\\';
    if (!str_starts_with($class, $prefix)) return;
    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = __DIR__ . '/' . $relative . '.php';
    if (is_file($file)) require_once $file;
});

load_env(dirname(__DIR__) . '/.env');
$GLOBALS['config'] = require dirname(__DIR__) . '/config/app.php';
date_default_timezone_set(config('timezone', 'Asia/Riyadh'));

if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(self), payment=()');
    header('Cross-Origin-Opener-Policy: same-origin-allow-popups');
    header('X-Permitted-Cross-Domain-Policies: none');
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    $path=parse_url($_SERVER['REQUEST_URI'] ?? '/',PHP_URL_PATH) ?: '/';
    if(str_starts_with($path,'/billing') || str_starts_with($path,'/admin')) header('Cache-Control: no-store, private');
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_name(config('session_name', 'khatauat_session'));
    session_set_cookie_params(['httponly'=>true,'secure'=>$secure,'samesite'=>'Lax','path'=>'/']);
    session_start();
}

try { \Khatauat\Services\SecurityService::hardenSession(); } catch (Throwable) {}
