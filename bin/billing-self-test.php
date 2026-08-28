<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use Khatauat\Core\Database;
$checks=[];
$checks['integrity']=Database::scalar('PRAGMA integrity_check');
$checks['foreign_keys']=count(Database::fetchAll('PRAGMA foreign_key_check'));
$checks['products']=(int)Database::scalar("SELECT COUNT(*) FROM billing_products WHERE status='active'");
$checks['ledger_triggers']=(int)Database::scalar("SELECT COUNT(*) FROM sqlite_master WHERE type='trigger' AND name IN ('trg_billing_ledger_no_update','trg_billing_ledger_no_delete')");
$checks['negative_credit_guard']='not-tested';
try{Database::execute("INSERT INTO billing_products(code,product_type,name,description,price_minor,currency,included_cases,case_message_limit,validity_days,status,sort_order) VALUES('__selftest','problem_pack','x','x',100,'SAR',1,12,1,'inactive',9999)");Database::execute("UPDATE billing_products SET included_cases=0 WHERE code='__selftest'");$checks['negative_credit_guard']='FAILED';}catch(Throwable){$checks['negative_credit_guard']='ok';}finally{try{Database::execute("DELETE FROM billing_products WHERE code='__selftest'");}catch(Throwable){}}
$checks['ok']=$checks['integrity']==='ok'&&$checks['foreign_keys']===0&&$checks['products']>=7&&$checks['ledger_triggers']===2&&$checks['negative_credit_guard']==='ok';
echo json_encode($checks,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT).PHP_EOL;exit($checks['ok']?0:1);
