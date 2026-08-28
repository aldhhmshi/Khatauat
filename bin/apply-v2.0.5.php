<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Khatauat\Core\Database;

$backup = dirname(__DIR__) . '/storage/database/khatauat.before-v2.0.5.' . date('Ymd_His') . '.sqlite';
$dbPath = dirname(__DIR__) . '/storage/database/khatauat.sqlite';
if (!copy($dbPath, $backup)) {
    fwrite(STDERR, "Could not create DB backup.\n");
    exit(1);
}

$canon = static function (string $host): string {
    $host = strtolower(trim($host));
    return preg_replace('/^www\./', '', $host) ?: $host;
};

$trusted = [];
foreach (Database::fetchAll('SELECT domain FROM source_registry WHERE status IN ("active","approved")') as $row) {
    $d = $canon((string)($row['domain'] ?? ''));
    if ($d !== '') $trusted[$d] = true;
}

$rows = Database::fetchAll('SELECT id,domain,url FROM source_registry WHERE status="candidate"');
$kept = 0;
$removed = 0;
foreach ($rows as $row) {
    $host = $canon((string)($row['domain'] ?? parse_url((string)$row['url'], PHP_URL_HOST) ?? ''));
    $safe = $host !== '' && str_ends_with($host, '.gov.sa');
    if (!$safe) {
        foreach (array_keys($trusted) as $known) {
            if ($host === $known || str_ends_with($host, '.'.$known)) {
                $safe = true;
                break;
            }
        }
    }
    if ($safe) {
        Database::execute('UPDATE source_registry SET auto_monitor=0, trust_level="candidate", updated_at=CURRENT_TIMESTAMP WHERE id=?', [(int)$row['id']]);
        $kept++;
    } else {
        Database::execute('DELETE FROM source_registry WHERE id=? AND status="candidate"', [(int)$row['id']]);
        $removed++;
    }
}

echo json_encode([
    'ok' => true,
    'db_backup' => basename($backup),
    'candidate_kept' => $kept,
    'candidate_removed' => $removed,
    'total_registry' => (int)(Database::fetch('SELECT COUNT(*) c FROM source_registry')['c'] ?? 0),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
