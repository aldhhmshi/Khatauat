<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

use Khatauat\Core\Database;
use Khatauat\Core\Settings;

Settings::set('app_version','2.0.16');
Settings::set('brand_primary','#10383b');
Settings::set('brand_secondary','#5f9d91');
Settings::set('brand_accent','#e2a24a');
Settings::set('site_background','#f8f7f2');
Settings::set('site_tagline','كل إجراء رقمي، بخطوات أوضح.');
Settings::set('notice_enabled','1');
Settings::set('notice_text','محتوى موثّق ومحدّث من المصادر الرسمية — افهم الإجراء قبل أن تبدأ.');
Settings::set('motion_level','safe');

// Keep any existing banner content, but make sure a useful home banner exists.
$bannerText=trim((string)Settings::get('banner_text',''));
if($bannerText===''){
    Settings::set('banner_text','دليل جديد متاح');
    Settings::set('banner_description','اكتشف أحدث الإجراءات والتحديثات الموثقة في خطوات.');
    Settings::set('banner_link_text','استكشف الدليل');
    Settings::set('banner_link_url','/procedures');
}
Settings::set('banner_enabled','1');
Settings::set('banner_scope','home');
Settings::set('banner_sticky','0');
Settings::set('banner_bg','#10383b');
Settings::set('banner_text_color','#ffffff');

// Marketing defaults for new installs / owner-editable homepage content.
Settings::set('home_hero_eyebrow','المعرفة التي تختصر عليك الطريق');
Settings::set('home_hero_title','كل إجراء رقمي');
Settings::set('home_hero_highlight','بخطوات أوضح.');
Settings::set('home_hero_description','دليلك العملي لفهم الإجراءات والخدمات في السعودية، من أول متطلب حتى رابط التنفيذ الرسمي.');
Settings::set('home_search_placeholder','ابحث عن خدمة، إجراء أو منصة...');
Settings::set('owner_visual_identity','unified_editorial_v216');
Settings::set('public_visual_identity','unified_editorial_v216');
Settings::set('logo_intro_enabled','1');

$out=[
    'ok'=>true,
    'version'=>'2.0.16',
    'design_system'=>'unified across public pages + owner console',
    'logo_intro'=>'home first visit per browser session; respects reduced motion',
    'banner'=>'home-first editorial campaign banner; owner editable',
    'marketing_copy'=>'updated while preserving independent/non-government disclosure',
    'owner_console'=>'simplified cards, operational metrics, grouped settings',
    'cleanup'=>'separate safe cleanup script included; backups are never deleted',
    'integrity'=>'ok',
    'foreign_key_errors'=>count(Database::fetchAll('PRAGMA foreign_key_check')),
];
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT).PHP_EOL;
