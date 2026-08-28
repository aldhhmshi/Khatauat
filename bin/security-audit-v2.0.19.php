<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use Khatauat\Core\Database;
use Khatauat\Services\MoyasarGateway;
$root=realpath(root_path()) ?: root_path();$public=realpath(dirname(dirname(__DIR__)).'/public_html') ?: '';$db=realpath((string)config('db_path')) ?: (string)config('db_path');$key=realpath(secure_path('app_security.key')) ?: secure_path('app_security.key');$gateway=new MoyasarGateway();
$r=[
 'db_outside_public_html'=>$public===''||!str_starts_with($db,$public),
 'security_key_exists'=>is_file($key),
 'security_key_outside_public_html'=>$public===''||!str_starts_with($key,$public),
 'security_key_permissions'=>is_file($key)?substr(sprintf('%o',fileperms($key)),-4):null,
 'moyasar_mode'=>$gateway->mode(),
 'moyasar_webhook_secret'=>$gateway->webhookConfigured(),
 'append_only_triggers'=>(int)Database::scalar("SELECT COUNT(*) FROM sqlite_master WHERE type='trigger' AND name LIKE 'trg_billing_ledger_no_%'"),
 'foreign_key_errors'=>count(Database::fetchAll('PRAGMA foreign_key_check')),
 'integrity'=>Database::scalar('PRAGMA integrity_check'),
 'raw_card_columns'=>(int)Database::scalar("SELECT COUNT(*) FROM pragma_table_info('billing_orders') WHERE lower(name) LIKE '%card%' OR lower(name) LIKE '%cvv%' OR lower(name) LIKE '%pan%'"),
];$r['ok']=$r['db_outside_public_html']&&$r['security_key_exists']&&$r['security_key_outside_public_html']&&$r['append_only_triggers']===2&&$r['foreign_key_errors']===0&&$r['integrity']==='ok'&&$r['raw_card_columns']===0;echo json_encode($r,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT).PHP_EOL;exit($r['ok']?0:1);
