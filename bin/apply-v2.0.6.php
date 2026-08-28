<?php

declare(strict_types=1);

require dirname(__DIR__).'/app/bootstrap.php';

use Khatauat\Core\Database;
use Khatauat\Core\Settings;

$dbPath=(string)config('db_path');
$backup=preg_replace('/\.sqlite$/','.before-v2.0.6.'.date('Ymd_His').'.sqlite',$dbPath) ?: ($dbPath.'.before-v2.0.6');
if (is_file($dbPath) && !copy($dbPath,$backup)) {
    fwrite(STDERR,"Failed to backup database\n"); exit(1);
}
$pdo=Database::connection();
$pdo->exec("CREATE TABLE IF NOT EXISTS user_ai_daily_usage (
    user_id INTEGER NOT NULL,
    usage_date TEXT NOT NULL,
    request_count INTEGER NOT NULL DEFAULT 0,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY(user_id,usage_date),
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
)");
$pdo->exec('CREATE INDEX IF NOT EXISTS idx_user_ai_usage_date ON user_ai_daily_usage(usage_date,user_id)');
Settings::set('public_ai_enabled','1');
Settings::set('free_ai_daily_limit','3');

echo json_encode([
    'ok'=>true,
    'version'=>'2.0.6',
    'db_backup'=>basename($backup),
    'public_ai_enabled'=>true,
    'free_ai_daily_limit'=>3,
],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
