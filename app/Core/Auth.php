<?php

declare(strict_types=1);

namespace Khatauat\Core;

use Khatauat\Services\SecurityService;
use Throwable;

final class Auth
{
    private static ?array $user = null;
    private static bool $loaded = false;

    public static function user(): ?array
    {
        if (self::$loaded) return self::$user;
        self::$loaded = true;
        $id = $_SESSION['user_id'] ?? null;
        if (!$id) return null;
        self::$user = Database::fetch('SELECT id, name, email, role, notifications_enabled, notification_frequency FROM users WHERE id = ?', [(int)$id]);
        return self::$user;
    }

    public static function id(): ?int { return self::user()['id'] ?? null; }
    public static function check(): bool { return self::user() !== null; }
    public static function isOwner(): bool { return (self::user()['role'] ?? '') === 'owner'; }

    public static function attempt(string $email, string $password): bool
    {
        try { SecurityService::rateLimit('login_ip', SecurityService::ipHash(), 12, 900); } catch (Throwable) {}
        try { SecurityService::rateLimit('login_account', mb_strtolower(trim($email)), 8, 900); } catch (Throwable) {}

        $user = Database::fetch('SELECT * FROM users WHERE lower(email) = lower(?) LIMIT 1', [$email]);
        if (!$user || !password_verify($password, $user['password_hash'])) {
            SecurityService::event('login_failed','warning',['email_hash'=>SecurityService::hashValue(mb_strtolower(trim($email)))],isset($user['id'])?(int)$user['id']:null);
            return false;
        }
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['_security_created_at']=time();
        $_SESSION['_security_last_seen']=time();
        $_SESSION['_security_last_rotate']=time();
        $_SESSION['_security_ua_hash']=SecurityService::userAgentHash();
        self::$loaded = false; self::$user = null;
        SecurityService::event('login_success','info',[],(int)$user['id']);
        return true;
    }

    public static function logout(): void
    {
        $uid=self::id();
        $_SESSION = [];
        session_regenerate_id(true);
        self::$loaded = false; self::$user = null;
        SecurityService::event('logout','info',[],$uid);
    }

    public static function requireUser(): void
    {
        if (!self::check()) { \flash('info', 'سجّل الدخول للوصول إلى هذه الصفحة.'); \redirect('login'); }
    }

    public static function requireOwner(): void
    {
        if (!self::isOwner()) {
            SecurityService::event('owner_access_denied','warning',[],self::id());
            http_response_code(403); View::render('errors/403', ['title' => 'غير مصرح']); exit;
        }
    }
}
