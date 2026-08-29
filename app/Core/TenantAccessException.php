<?php

declare(strict_types=1);

namespace Khatauat\Core;

use RuntimeException;

final class TenantAccessException extends RuntimeException
{
    public function __construct(string $message = 'لا توجد صلاحية للوصول إلى المنظمة المطلوبة.')
    {
        parent::__construct($message, 403);
    }
}
