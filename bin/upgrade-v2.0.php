<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Khatauat\Core\Database;
use Khatauat\Core\Settings;
use Khatauat\Services\IntegrationCatalog;
use Khatauat\Services\SaudiSourceRegistry;
use Khatauat\Services\ProductionSeeder;

$root = dirname(__DIR__);
$dbPath = (string)config('db_path');
if (is_file($dbPath)) {
    $backup = $dbPath . '.pre-v2.0-' . date('YmdHis') . '.bak';
    if (!copy($dbPath, $backup)) { fwrite(STDERR,"تعذر إنشاء نسخة احتياطية من قاعدة البيانات.\n"); exit(1); }
    echo "BACKUP {$backup}\n";
}

// Safe additive schema: every table uses IF NOT EXISTS.
$schema = (string)file_get_contents($root . '/database/schema.sql');
Database::connection()->exec($schema);

function addColumnV20(string $table,string $column,string $definition): void {
    foreach (Database::fetchAll('PRAGMA table_info('.$table.')') as $c) if (($c['name']??'')===$column) return;
    Database::connection()->exec('ALTER TABLE '.$table.' ADD COLUMN '.$column.' '.$definition);
    echo "ADDED {$table}.{$column}\n";
}
addColumnV20('ai_drafts','structured_json','TEXT');
addColumnV20('ai_drafts','provider','TEXT');
addColumnV20('ai_drafts','error_detail','TEXT');
addColumnV20('articles','source_urls',"TEXT NOT NULL DEFAULT ''");
addColumnV20('articles','verification_notes',"TEXT NOT NULL DEFAULT ''");
addColumnV20('articles','verified_at','TEXT');
addColumnV20('articles','verified_by','TEXT');
addColumnV20('articles','ai_draft_id','INTEGER');

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

$sources=(new SaudiSourceRegistry())->seedCore();
$integrations=(new IntegrationCatalog())->seed();
$production=(new ProductionSeeder())->seed();
echo "SEEDED source_registry={$sources} integrations={$integrations} categories={$production['categories']} verified_services={$production['services']}\n";
echo "Khatauat 2.0 additive upgrade completed. Review /admin/source-registry and /admin/integrations before enabling external automation.\n";
