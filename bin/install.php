<?php

declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

use Khatauat\Core\Database;
use Khatauat\Core\Settings;
use Khatauat\Services\IntegrationCatalog;
use Khatauat\Services\SaudiSourceRegistry;
use Khatauat\Services\ProductionSeeder;

if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "ERROR: ext-pdo_sqlite is required. Enable PHP SQLite first.\n");
    exit(1);
}
$pdo = Database::connection();
$schema = file_get_contents(dirname(__DIR__) . '/database/schema.sql');
$pdo->exec($schema);
if (!Khatauat\Core\Database::fetch('SELECT id FROM users WHERE role="owner" LIMIT 1')) {
    $email = getenv('OWNER_EMAIL') ?: 'owner@khatauat.local';
    $password = getenv('OWNER_PASSWORD') ?: 'ChangeMe123!';
    Khatauat\Core\Database::execute('INSERT INTO users(name,email,password_hash,role,notifications_enabled,notification_frequency,created_at) VALUES(?,?,?,?,?,?,CURRENT_TIMESTAMP)', ['مالك المنصة',$email,password_hash($password,PASSWORD_DEFAULT),'owner',1,'instant']);
    echo "Owner created: {$email}\n";
    if (!getenv('OWNER_PASSWORD')) echo "Temporary password: ChangeMe123! (CHANGE IT BEFORE PRODUCTION)\n";
}
echo "Database ready: " . config('db_path') . "\n";

$defaults = [
    'openai_responses_url'=>'https://api.openai.com/v1/responses',
    'openai_model'=>'',
    'home_hero_eyebrow'=>'محرك الإجراءات والخدمات في السعودية',
    'home_hero_title'=>'قل لنا ماذا تريد أن تنجز',
    'home_hero_highlight'=>'وسنرتب الطريق أمامك.',
    'home_hero_description'=>'بدل أن تبحث بين عشرات الجهات والمنصات، ابدأ من هدفك. خطوات تجمع المصادر الرسمية وتربط الإجراءات والاشتراطات والمنصات في رحلة واحدة واضحة.',
    'home_search_placeholder'=>'ماذا تريد أن تنجز؟ مثال: أفتح مؤسسة مقاولات',
    'footer_description'=>'منصة إرشادية مستقلة تربط الإجراءات والمصادر والمنصات الرسمية. لا تمثل جهة حكومية ولا تنفذ المعاملة بدلًا عن المستخدم.',
];
foreach($defaults as $k=>$v) if(Settings::get($k,null)===null) Settings::set($k,$v);
(new SaudiSourceRegistry())->seedCore();
(new IntegrationCatalog())->seed();
$production=(new ProductionSeeder())->seed();
echo "Khatauat 2.0 baseline seeded: {$production['categories']} categories, {$production['services']} verified journeys.\n";
