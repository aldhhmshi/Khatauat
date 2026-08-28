<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

use Khatauat\Core\Database;
use Khatauat\Services\MoyasarGateway;

$pdo=Database::connection();
$domainRoot=dirname(dirname(__DIR__));
$index=$domainRoot.'/public_html/index.php';
$requiredRoutes=[
 "$"."router->get('/plans', [BillingController::class, 'plans']);",
 "$"."router->get('/billing', [BillingController::class, 'account']);",
 "$"."router->get('/billing/checkout/{code}', [BillingController::class, 'checkout']);",
 "$"."router->post('/billing/checkout', [BillingController::class, 'startCheckout']);",
];
$missing=[];
$router=is_file($index)?(file_get_contents($index)?:''):'';
foreach($requiredRoutes as $route){if(!str_contains($router,$route))$missing[]=$route;}
@file_put_contents(dirname(__DIR__).'/VERSION',"2.0.20\n");
$integrity=(string)$pdo->query('PRAGMA integrity_check')->fetchColumn();
$fk=$pdo->query('PRAGMA foreign_key_check')->fetchAll();
$gateway=new MoyasarGateway();
echo json_encode([
 'ok'=>$integrity==='ok'&&count($fk)===0&&count($missing)===0,
 'version'=>'2.0.20',
 'public_payment_entry'=>'main_nav_packages + user_balance + ask_ai_purchase_cta',
 'plans_url'=>'/plans',
 'billing_url'=>'/billing',
 'checkout_url_pattern'=>'/billing/checkout/{product_code}',
 'gateway_mode'=>$gateway->mode(),
 'gateway_configured'=>$gateway->isConfigured(),
 'webhook_configured'=>$gateway->webhookConfigured(),
 'billing_products'=>(int)$pdo->query("SELECT COUNT(*) FROM billing_products WHERE status='active'")->fetchColumn(),
 'missing_billing_routes'=>count($missing),
 'integrity'=>$integrity,
 'foreign_key_errors'=>count($fk),
],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT).PHP_EOL;
