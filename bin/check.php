<?php

declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

$checks = [
    'PHP >= 8.2' => version_compare(PHP_VERSION, '8.2.0', '>='),
    'PDO' => extension_loaded('pdo'),
    'pdo_sqlite' => extension_loaded('pdo_sqlite'),
    'mbstring' => extension_loaded('mbstring'),
    'openssl' => extension_loaded('openssl'),
    'curl (AI optional)' => extension_loaded('curl'),
    'storage/database writable' => is_writable(dirname((string)config('db_path'))),
    'storage/logs writable' => is_writable(dirname(__DIR__) . '/storage/logs'),
    'storage/snapshots writable' => is_writable(dirname(__DIR__) . '/storage/snapshots'),
];
$failed = 0;
foreach ($checks as $name => $ok) {
    echo ($ok ? '[OK]   ' : '[FAIL] ') . $name . PHP_EOL;
    if (!$ok && !str_contains($name, 'optional')) $failed++;
}
echo PHP_EOL . 'PHP ' . PHP_VERSION . PHP_EOL;
exit($failed ? 1 : 0);
