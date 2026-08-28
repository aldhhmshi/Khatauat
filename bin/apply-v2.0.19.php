<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Khatauat\Core\Database;
use Khatauat\Core\Settings;
use Khatauat\Services\BillingService;
use Khatauat\Services\SecurityService;

$pdo=Database::connection();
$dbPath=(string)config('db_path',root_path('storage/database/khatauat.sqlite'));
$stamp=date('Ymd_His');
$backup=root_path('storage/database/khatauat.before-v2.0.19.'.$stamp.'.sqlite');
if(is_file($dbPath) && !@copy($dbPath,$backup)){fwrite(STDERR,"Failed to backup database\n");exit(1);}

$schema=<<<'SQL'
CREATE TABLE IF NOT EXISTS billing_products (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 code TEXT NOT NULL UNIQUE,
 product_type TEXT NOT NULL CHECK(product_type IN ('problem_pack','subscription_monthly','subscription_annual')),
 name TEXT NOT NULL,
 description TEXT NOT NULL DEFAULT '',
 price_minor INTEGER NOT NULL CHECK(price_minor>=100),
 currency TEXT NOT NULL DEFAULT 'SAR' CHECK(length(currency)=3),
 included_cases INTEGER NOT NULL CHECK(included_cases>0 AND included_cases<=1000),
 case_message_limit INTEGER NOT NULL DEFAULT 12 CHECK(case_message_limit BETWEEN 3 AND 100),
 validity_days INTEGER NOT NULL CHECK(validity_days BETWEEN 1 AND 730),
 status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active','inactive')),
 sort_order INTEGER NOT NULL DEFAULT 100,
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS billing_orders (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 order_uuid TEXT NOT NULL UNIQUE,
 user_id INTEGER NOT NULL,
 product_id INTEGER NOT NULL,
 product_code_snapshot TEXT NOT NULL,
 product_name_snapshot TEXT NOT NULL,
 product_type_snapshot TEXT NOT NULL CHECK(product_type_snapshot IN ('problem_pack','subscription_monthly','subscription_annual')),
 amount_minor INTEGER NOT NULL CHECK(amount_minor>=100),
 currency TEXT NOT NULL DEFAULT 'SAR' CHECK(length(currency)=3),
 included_cases_snapshot INTEGER NOT NULL CHECK(included_cases_snapshot>0),
 case_message_limit_snapshot INTEGER NOT NULL CHECK(case_message_limit_snapshot BETWEEN 3 AND 100),
 validity_days_snapshot INTEGER NOT NULL CHECK(validity_days_snapshot BETWEEN 1 AND 730),
 status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','paid','failed','canceled','refunded','partially_refunded','expired')),
 provider TEXT NOT NULL DEFAULT 'moyasar',
 provider_invoice_id TEXT UNIQUE,
 provider_invoice_url TEXT,
 provider_payment_id TEXT,
 idempotency_key TEXT NOT NULL UNIQUE,
 terms_version TEXT NOT NULL,
 privacy_version TEXT NOT NULL,
 terms_accepted_at TEXT NOT NULL,
 privacy_accepted_at TEXT NOT NULL,
 expires_at TEXT NOT NULL,
 paid_at TEXT,
 refunded_minor INTEGER NOT NULL DEFAULT 0 CHECK(refunded_minor>=0 AND refunded_minor<=amount_minor),
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE RESTRICT,
 FOREIGN KEY(product_id) REFERENCES billing_products(id) ON DELETE RESTRICT
);
CREATE INDEX IF NOT EXISTS idx_billing_orders_user ON billing_orders(user_id,id DESC);
CREATE INDEX IF NOT EXISTS idx_billing_orders_status ON billing_orders(status,created_at);

CREATE TABLE IF NOT EXISTS billing_subscriptions (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 user_id INTEGER NOT NULL,
 product_id INTEGER NOT NULL,
 order_id INTEGER NOT NULL UNIQUE,
 status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active','expired','canceled')),
 starts_at TEXT NOT NULL,
 ends_at TEXT NOT NULL,
 auto_renew INTEGER NOT NULL DEFAULT 0 CHECK(auto_renew IN (0,1)),
 cases_included INTEGER NOT NULL CHECK(cases_included>0),
 case_message_limit INTEGER NOT NULL CHECK(case_message_limit BETWEEN 3 AND 100),
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE RESTRICT,
 FOREIGN KEY(product_id) REFERENCES billing_products(id) ON DELETE RESTRICT,
 FOREIGN KEY(order_id) REFERENCES billing_orders(id) ON DELETE RESTRICT
);
CREATE INDEX IF NOT EXISTS idx_billing_subscriptions_user ON billing_subscriptions(user_id,status,ends_at);

CREATE TABLE IF NOT EXISTS case_credit_grants (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 user_id INTEGER NOT NULL,
 order_id INTEGER NOT NULL,
 subscription_id INTEGER,
 source_type TEXT NOT NULL,
 total_cases INTEGER NOT NULL CHECK(total_cases>0),
 remaining_cases INTEGER NOT NULL CHECK(remaining_cases>=0 AND remaining_cases<=total_cases),
 case_message_limit INTEGER NOT NULL CHECK(case_message_limit BETWEEN 3 AND 100),
 valid_from TEXT NOT NULL,
 expires_at TEXT,
 status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active','exhausted','expired','revoked')),
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE RESTRICT,
 FOREIGN KEY(order_id) REFERENCES billing_orders(id) ON DELETE RESTRICT,
 FOREIGN KEY(subscription_id) REFERENCES billing_subscriptions(id) ON DELETE RESTRICT
);
CREATE INDEX IF NOT EXISTS idx_case_grants_user ON case_credit_grants(user_id,status,expires_at);

CREATE TABLE IF NOT EXISTS problem_cases (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 case_ref TEXT NOT NULL UNIQUE,
 user_id INTEGER NOT NULL,
 grant_id INTEGER NOT NULL,
 status TEXT NOT NULL DEFAULT 'open' CHECK(status IN ('open','resolved','escalated','expired')),
 message_limit INTEGER NOT NULL CHECK(message_limit BETWEEN 3 AND 100),
 message_count INTEGER NOT NULL DEFAULT 0 CHECK(message_count>=0 AND message_count<=message_limit),
 opened_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 expires_at TEXT NOT NULL,
 closed_at TEXT,
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE RESTRICT,
 FOREIGN KEY(grant_id) REFERENCES case_credit_grants(id) ON DELETE RESTRICT
);
CREATE INDEX IF NOT EXISTS idx_problem_cases_user ON problem_cases(user_id,status,expires_at);

CREATE TABLE IF NOT EXISTS billing_ledger (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 user_id INTEGER NOT NULL,
 order_id INTEGER,
 grant_id INTEGER,
 case_id INTEGER,
 entry_type TEXT NOT NULL CHECK(entry_type IN ('credit','consume_case','refund_reversal','admin_adjustment')),
 delta_cases INTEGER NOT NULL CHECK(delta_cases<>0),
 balance_after INTEGER NOT NULL CHECK(balance_after>=0),
 reference TEXT NOT NULL,
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE RESTRICT,
 FOREIGN KEY(order_id) REFERENCES billing_orders(id) ON DELETE RESTRICT,
 FOREIGN KEY(grant_id) REFERENCES case_credit_grants(id) ON DELETE RESTRICT,
 FOREIGN KEY(case_id) REFERENCES problem_cases(id) ON DELETE RESTRICT
);
CREATE INDEX IF NOT EXISTS idx_billing_ledger_user ON billing_ledger(user_id,id DESC);

CREATE TRIGGER IF NOT EXISTS trg_billing_ledger_no_update BEFORE UPDATE ON billing_ledger BEGIN SELECT RAISE(ABORT,'billing_ledger is append-only'); END;
CREATE TRIGGER IF NOT EXISTS trg_billing_ledger_no_delete BEFORE DELETE ON billing_ledger BEGIN SELECT RAISE(ABORT,'billing_ledger is append-only'); END;

CREATE TABLE IF NOT EXISTS policy_acceptances (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 user_id INTEGER NOT NULL,
 order_uuid TEXT,
 terms_version TEXT NOT NULL,
 privacy_version TEXT NOT NULL,
 context TEXT NOT NULL CHECK(context IN ('checkout','account','registration')),
 ip_hash TEXT,
 user_agent_hash TEXT,
 accepted_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE RESTRICT
);
CREATE INDEX IF NOT EXISTS idx_policy_acceptances_user ON policy_acceptances(user_id,accepted_at);

CREATE TABLE IF NOT EXISTS billing_webhook_events (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 provider TEXT NOT NULL,
 event_id TEXT NOT NULL,
 event_type TEXT NOT NULL,
 payload_hash TEXT NOT NULL,
 order_uuid TEXT,
 invoice_id TEXT,
 payment_id TEXT,
 amount_minor INTEGER NOT NULL DEFAULT 0 CHECK(amount_minor>=0),
 currency TEXT NOT NULL DEFAULT 'SAR',
 refunded_minor INTEGER NOT NULL DEFAULT 0 CHECK(refunded_minor>=0),
 status TEXT NOT NULL DEFAULT 'queued' CHECK(status IN ('queued','processed','ignored','failed')),
 error_message TEXT,
 received_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 processed_at TEXT,
 UNIQUE(provider,event_id)
);
CREATE INDEX IF NOT EXISTS idx_billing_webhook_status ON billing_webhook_events(status,received_at);

CREATE TABLE IF NOT EXISTS rate_limit_buckets (
 scope TEXT NOT NULL,
 key_hash TEXT NOT NULL,
 window_start INTEGER NOT NULL,
 request_count INTEGER NOT NULL DEFAULT 0 CHECK(request_count>=0),
 updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(scope,key_hash,window_start)
) WITHOUT ROWID;

CREATE TABLE IF NOT EXISTS security_events (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 event_type TEXT NOT NULL,
 severity TEXT NOT NULL DEFAULT 'info' CHECK(severity IN ('info','warning','critical')),
 user_id INTEGER,
 ip_hash TEXT,
 user_agent_hash TEXT,
 metadata_json TEXT,
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_security_events_type ON security_events(event_type,created_at);
CREATE INDEX IF NOT EXISTS idx_security_events_severity ON security_events(severity,created_at);
SQL;
$pdo->exec($schema);

$products=[
 ['problem_2','problem_pack','حل مشكلتين','جلستان مستقلتان لحل مشكلتين عبر خطوات AI.',1000,'SAR',2,12,365,10],
 ['problem_3','problem_pack','حل 3 مشاكل','ثلاث جلسات مستقلة لحل ثلاث مشاكل.',1500,'SAR',3,12,365,20],
 ['problem_6','problem_pack','حل 6 مشاكل','ست جلسات مستقلة بأفضل قيمة للشراء المباشر.',2000,'SAR',6,12,365,30],
 ['monthly_basic','subscription_monthly','اشتراك أساسي شهري','8 مشاكل شهريًا مع تجديد يدوي.',1900,'SAR',8,12,30,40],
 ['monthly_plus','subscription_monthly','اشتراك بلس شهري','20 مشكلة شهريًا للمستخدم المتكرر.',3900,'SAR',20,12,30,50],
 ['annual_basic','subscription_annual','اشتراك أساسي سنوي','96 مشكلة خلال سنة مع سعر سنوي مخفض.',19000,'SAR',96,12,365,60],
 ['annual_plus','subscription_annual','اشتراك بلس سنوي','240 مشكلة خلال سنة للمستخدم المكثف.',39000,'SAR',240,12,365,70],
];
$sql="INSERT INTO billing_products(code,product_type,name,description,price_minor,currency,included_cases,case_message_limit,validity_days,status,sort_order,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,'active',?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP) ON CONFLICT(code) DO UPDATE SET product_type=excluded.product_type,name=excluded.name,description=excluded.description,sort_order=excluded.sort_order,updated_at=CURRENT_TIMESTAMP";
foreach($products as $p) Database::execute($sql,$p);

Settings::set('billing_enabled','1');
Settings::set('billing_provider','moyasar');
Settings::set('billing_auto_renew','0');
Settings::set('terms_version',BillingService::TERMS_VERSION);
Settings::set('privacy_version',BillingService::PRIVACY_VERSION);
Settings::set('paid_case_default_message_limit','12');
Settings::set('paid_case_default_days','7');
SecurityService::key();

// Add routes safely to the production router without replacing the whole file.
$domainRoot=dirname(dirname(__DIR__));
$index=$domainRoot.'/public_html/index.php';
$routeAdded=[];
if(is_file($index)){
 $code=file_get_contents($index) ?: '';
 $uses=[
  "use Khatauat\\Controllers\\BillingController;",
  "use Khatauat\\Controllers\\AdminBillingController;",
 ];
 foreach($uses as $use){if(!str_contains($code,$use)){$needle="use Khatauat\\Controllers\\OwnerOpsController;";$code=str_replace($needle,$needle.PHP_EOL.$use,$code);}}
 $routes=[
  "$"."router->get('/plans', [BillingController::class, 'plans']);",
  "$"."router->get('/billing', [BillingController::class, 'account']);",
  "$"."router->get('/billing/checkout/{code}', [BillingController::class, 'checkout']);",
  "$"."router->post('/billing/checkout', [BillingController::class, 'startCheckout']);",
  "$"."router->get('/billing/success', [BillingController::class, 'success']);",
  "$"."router->get('/billing/back', [BillingController::class, 'back']);",
  "$"."router->post('/billing/case/start', [BillingController::class, 'startCase']);",
  "$"."router->post('/webhooks/moyasar', [BillingController::class, 'webhook']);",
  "$"."router->get('/admin/billing', [AdminBillingController::class, 'index']);",
  "$"."router->post('/admin/billing/product', [AdminBillingController::class, 'saveProduct']);",
 ];
 $anchor="$"."router->get('/admin', [AdminController::class, 'dashboard']);";
 $publicAnchor="$"."router->get('/login', [AuthController::class, 'login']);";
 foreach($routes as $route){
  if(str_contains($code,$route))continue;
  $target=str_contains($route,"/admin/")?$anchor:$publicAnchor;
  if(str_contains($code,$target)){$code=str_replace($target,$route.PHP_EOL.$target,$code);$routeAdded[]=$route;}
 }
 @copy($index,$index.'.before-v2.0.19.'.$stamp.'.bak');
 file_put_contents($index,$code);
}

@file_put_contents(root_path('VERSION'),"2.0.19\n");
$integrity=(string)$pdo->query('PRAGMA integrity_check')->fetchColumn();
$fk=$pdo->query('PRAGMA foreign_key_check')->fetchAll();
$secureKey=root_path('storage/secure/app_security.key');

echo json_encode([
 'ok'=>$integrity==='ok'&&count($fk)===0,
 'version'=>'2.0.19',
 'db_backup'=>basename($backup),
 'billing_products'=>(int)$pdo->query("SELECT COUNT(*) FROM billing_products WHERE status='active'")->fetchColumn(),
 'problem_packs'=>['2 problems / 10 SAR','3 problems / 15 SAR','6 problems / 20 SAR'],
 'subscription_renewal'=>'manual_only_no_auto_charge',
 'policy_terms_version'=>BillingService::TERMS_VERSION,
 'policy_privacy_version'=>BillingService::PRIVACY_VERSION,
 'payment_card_storage'=>'none_hosted_provider_checkout',
 'append_only_ledger'=>'enabled',
 'persistent_rate_limit'=>'enabled',
 'security_key_outside_public_html'=>is_file($secureKey),
 'routes_added'=>count($routeAdded),
 'integrity'=>$integrity,
 'foreign_key_errors'=>count($fk),
],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT).PHP_EOL;
