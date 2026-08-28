<?php
$defaultStorageRoot = dirname(__DIR__) . '/storage';
return [
    'name' => getenv('APP_NAME') ?: 'خطوات',
    'env' => getenv('APP_ENV') ?: 'production',
    'debug' => filter_var(getenv('APP_DEBUG') ?: 'false', FILTER_VALIDATE_BOOL),
    'base_url' => rtrim(getenv('APP_URL') ?: '', '/'),
    'timezone' => getenv('APP_TIMEZONE') ?: 'Asia/Riyadh',
    'storage_root' => rtrim(getenv('STORAGE_ROOT') ?: $defaultStorageRoot, '/\\'),
    'secure_root' => rtrim(getenv('SECURE_ROOT') ?: $defaultStorageRoot . '/secure', '/\\'),
    'uploads_root' => rtrim(getenv('UPLOADS_ROOT') ?: dirname(__DIR__) . '/public/assets/uploads', '/\\'),
    'public_uploads_root' => rtrim(getenv('PUBLIC_UPLOADS_ROOT') ?: dirname(__DIR__) . '/public/assets/uploads', '/\\'),
    'db_path' => getenv('DB_PATH') ?: $defaultStorageRoot . '/database/khatauat.sqlite',
    'session_name' => getenv('SESSION_NAME') ?: 'khatauat_session',
    'monitor_timeout' => (int) (getenv('MONITOR_TIMEOUT') ?: 12),
];
