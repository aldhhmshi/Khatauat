<?php

declare(strict_types=1);

namespace Khatauat\Services;

use Khatauat\Core\Database;
use RuntimeException;
use Throwable;

final class SecurityService
{
    private static ?string $key = null;

    public static function key(): string
    {
        if (self::$key !== null) return self::$key;
        $env = trim((string)(getenv('APP_SECURITY_KEY') ?: ''));
        if ($env !== '') return self::$key = $env;

        $dir = \root_path('storage/secure');
        $path = $dir . '/app_security.key';
        if (is_file($path)) {
            $value = trim((string)file_get_contents($path));
            if ($value !== '') return self::$key = $value;
        }
        if (!is_dir($dir)) @mkdir($dir, 0770, true);
        $value = bin2hex(random_bytes(32));
        if (@file_put_contents($path, $value . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('تعذر إنشاء مفتاح الأمان داخل storage/secure.');
        }
        @chmod($path, 0600);
        return self::$key = $value;
    }

    public static function hashValue(string $value): string
    {
        return hash_hmac('sha256', $value, self::key());
    }

    public static function ipHash(): string
    {
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        return self::hashValue('ip|' . $ip);
    }

    public static function userAgentHash(): string
    {
        return self::hashValue('ua|' . (string)($_SERVER['HTTP_USER_AGENT'] ?? 'unknown'));
    }

    public static function assertSameOrigin(): void
    {
        if (PHP_SAPI === 'cli') return;
        $host = strtolower(preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')) ?? '');
        if ($host === '') return;
        foreach (['HTTP_ORIGIN','HTTP_REFERER'] as $header) {
            $raw = trim((string)($_SERVER[$header] ?? ''));
            if ($raw === '') continue;
            $urlHost = strtolower((string)parse_url($raw, PHP_URL_HOST));
            if ($urlHost !== '' && !hash_equals($host, $urlHost)) {
                self::event('same_origin_rejected', 'warning', ['header' => $header, 'host' => $urlHost]);
                http_response_code(403);
                exit('تم رفض الطلب لأسباب أمنية.');
            }
            break;
        }
    }

    public static function rateLimit(string $scope, string $identifier, int $max, int $windowSeconds): void
    {
        $max = max(1, $max);
        $windowSeconds = max(10, $windowSeconds);
        try {
            if (!Database::tableExists('rate_limit_buckets')) return;
            $keyHash = self::hashValue($scope . '|' . $identifier);
            $windowStart = intdiv(time(), $windowSeconds) * $windowSeconds;
            Database::execute(
                'INSERT INTO rate_limit_buckets(scope,key_hash,window_start,request_count,updated_at) VALUES(?,?,?,?,CURRENT_TIMESTAMP) '
                . 'ON CONFLICT(scope,key_hash,window_start) DO UPDATE SET request_count=request_count+1,updated_at=CURRENT_TIMESTAMP',
                [$scope,$keyHash,$windowStart,1]
            );
            $row = Database::fetch('SELECT request_count FROM rate_limit_buckets WHERE scope=? AND key_hash=? AND window_start=?', [$scope,$keyHash,$windowStart]);
            if ((int)($row['request_count'] ?? 0) > $max) {
                self::event('rate_limit_exceeded', 'warning', ['scope' => $scope]);
                http_response_code(429);
                header('Retry-After: ' . $windowSeconds);
                exit('تم تجاوز الحد المؤقت للطلبات. حاول بعد قليل.');
            }
        } catch (Throwable) {
            // Fail open for availability; security event may be unavailable before migration.
        }
    }

    public static function event(string $type, string $severity = 'info', array $metadata = [], ?int $userId = null): void
    {
        try {
            if (!Database::tableExists('security_events')) return;
            $safe = [];
            foreach ($metadata as $k => $v) {
                if (is_scalar($v) || $v === null) $safe[(string)$k] = $v;
            }
            Database::execute(
                'INSERT INTO security_events(event_type,severity,user_id,ip_hash,user_agent_hash,metadata_json,created_at) VALUES(?,?,?,?,?,?,CURRENT_TIMESTAMP)',
                [$type,$severity,$userId,self::ipHash(),self::userAgentHash(),json_encode($safe, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]
            );
        } catch (Throwable) {}
    }

    public static function hardenSession(): void
    {
        if (PHP_SAPI === 'cli' || session_status() !== PHP_SESSION_ACTIVE) return;
        $now = time();
        $idle = max(900, (int)(getenv('SESSION_IDLE_SECONDS') ?: 7200));
        $absolute = max($idle, (int)(getenv('SESSION_ABSOLUTE_SECONDS') ?: 86400));
        $ua = self::userAgentHash();

        $created = (int)($_SESSION['_security_created_at'] ?? 0);
        $last = (int)($_SESSION['_security_last_seen'] ?? 0);
        $savedUa = (string)($_SESSION['_security_ua_hash'] ?? '');
        $invalid = ($created > 0 && ($now - $created) > $absolute)
            || ($last > 0 && ($now - $last) > $idle)
            || ($savedUa !== '' && !hash_equals($savedUa, $ua));

        if ($invalid) {
            self::event('session_rotated_security', 'info', ['reason' => 'timeout_or_user_agent_change'], isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null);
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $p = session_get_cookie_params();
                setcookie(session_name(), '', time()-42000, $p['path'] ?? '/', $p['domain'] ?? '', (bool)($p['secure'] ?? false), (bool)($p['httponly'] ?? true));
            }
            session_regenerate_id(true);
        }

        if (empty($_SESSION['_security_created_at'])) $_SESSION['_security_created_at'] = $now;
        $_SESSION['_security_last_seen'] = $now;
        $_SESSION['_security_ua_hash'] = $ua;

        $lastRotate = (int)($_SESSION['_security_last_rotate'] ?? 0);
        if ($lastRotate === 0 || ($now - $lastRotate) > 1800) {
            session_regenerate_id(true);
            $_SESSION['_security_last_rotate'] = $now;
        }
    }
}
