<?php
return [
    'name' => getenv('APP_NAME') ?: 'خطوات',
    'env' => getenv('APP_ENV') ?: 'production',
    'debug' => filter_var(getenv('APP_DEBUG') ?: 'false', FILTER_VALIDATE_BOOL),
    'base_url' => rtrim(getenv('APP_URL') ?: '', '/'),
    'timezone' => getenv('APP_TIMEZONE') ?: 'Asia/Riyadh',
    'db_path' => getenv('DB_PATH') ?: dirname(__DIR__) . '/storage/database/khatauat.sqlite',
    'session_name' => getenv('SESSION_NAME') ?: 'khatauat_session',
    'monitor_timeout' => (int) (getenv('MONITOR_TIMEOUT') ?: 12),
];
