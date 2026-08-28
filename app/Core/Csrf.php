<?php

declare(strict_types=1);

namespace Khatauat\Core;

use Khatauat\Services\SecurityService;

final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['_csrf'])) $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        return $_SESSION['_csrf'];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . \e(self::token()) . '">';
    }

    public static function verify(): void
    {
        SecurityService::assertSameOrigin();
        $token = $_POST['_csrf'] ?? '';
        if (!is_string($token) || !hash_equals(self::token(), $token)) {
            SecurityService::event('csrf_rejected','warning');
            http_response_code(419);
            exit('انتهت صلاحية الطلب أو رمز الحماية غير صحيح. أعد تحميل الصفحة.');
        }
    }
}
