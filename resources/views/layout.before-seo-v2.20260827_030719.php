<?php
use Khatauat\Core\Auth;
use Khatauat\Core\Settings;
$siteName = (string)Settings::get('site_name', config('name','خطوات'));
$siteTagline = (string)Settings::get('site_tagline','الدليل الإجرائي الحكومي');
$flashes = pull_flashes();
$metaDescription = $metaDescription ?? $siteTagline;
$ga4 = trim((string)Settings::get('ga4_measurement_id',''));
$internalAnalytics = (string)Settings::get('internal_analytics_enabled','1') === '1';
$motionLevel = (string)Settings::get('motion_level','safe');
$canonical = rtrim((string)config('base_url',''),'/') . current_url_path();
$brandPrimary = safe_hex_color((string)Settings::get('brand_primary','#0c2f55'),'#0c2f55');
$brandSecondary = safe_hex_color((string)Settings::get('brand_secondary','#0e8b95'),'#0e8b95');
$brandAccent = safe_hex_color((string)Settings::get('brand_accent','#c8881a'),'#c8881a');
$siteBackground = safe_hex_color((string)Settings::get('site_background','#f4f7f8'),'#f4f7f8');
$fontKey = (string)Settings::get('font_family','ibm-plex');
$fontMap = [
  'ibm-plex' => ["'IBM Plex Sans Arabic','Segoe UI',Tahoma,Arial,sans-serif", 'https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&display=swap'],
  'tajawal' => ["'Tajawal','Segoe UI',Tahoma,Arial,sans-serif", 'https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800&display=swap'],
  'noto-kufi' => ["'Noto Kufi Arabic','Segoe UI',Tahoma,Arial,sans-serif", 'https://fonts.googleapis.com/css2?family=Noto+Kufi+Arabic:wght@400;500;600;700&display=swap'],
  'system' => ["'Segoe UI',Tahoma,Arial,sans-serif", ''],
];
[$fontStack,$fontUrl] = $fontMap[$fontKey] ?? $fontMap['ibm-plex'];
$logoPath = trim((string)Settings::get('site_logo_path',''));
$noticeEnabled = (string)Settings::get('notice_enabled','1') === '1';
$noticeText = trim((string)Settings::get('notice_text','خطوات منصة دلالية مستقلة وليست جهة حكومية؛ نوضح تسلسل الإجراءات ونربط كل مرحلة بموقعها الرسمي.'));
$bannerEnabled = (string)Settings::get('banner_enabled','0') === '1';
$bannerText = trim((string)Settings::get('banner_text',''));
$bannerDescription = trim((string)Settings::get('banner_description',''));
$bannerScope = (string)Settings::get('banner_scope','all');
$bannerBg = safe_hex_color((string)Settings::get('banner_bg','#0c2f55'),'#0c2f55');
$bannerTextColor = safe_hex_color((string)Settings::get('banner_text_color','#ffffff'),'#ffffff');
$bannerLinkText = trim((string)Settings::get('banner_link_text',''));
$bannerLink = safe_href((string)Settings::get('banner_link_url',''),'#');
$bannerMedia = trim((string)Settings::get('banner_media_path', Settings::get('banner_image_path','')));
$bannerMediaType = (string)Settings::get('banner_media_type','image');
$bannerSticky = (string)Settings::get('banner_sticky','1') === '1';
$showBanner = $bannerEnabled && ($bannerText !== '' || $bannerMedia !== '') && ($bannerScope === 'all' || current_url_path() === '/');

// v1.4.4 — server-side public navigation state.
$currentPublicPath = rtrim(current_url_path(), '/') ?: '/';
$navHomeActive = $currentPublicPath === '/';
$navProceduresActive = str_starts_with($currentPublicPath, '/procedures') || str_starts_with($currentPublicPath, '/service') || str_starts_with($currentPublicPath, '/services');
$navUpdatesActive = str_starts_with($currentPublicPath, '/updates');
$navCalculatorsActive = str_starts_with($currentPublicPath, '/calculators');
$navBlogActive = str_starts_with($currentPublicPath, '/blog') || str_starts_with($currentPublicPath, '/article');
$navAiActive = str_starts_with($currentPublicPath, '/ask-ai');
$navPlansActive = str_starts_with($currentPublicPath, '/plans');
$navBillingActive = str_starts_with($currentPublicPath, '/billing');
$navState = static fn(bool $active): string => $active ? ' class="is-current" aria-current="page"' : '';
$isOwnerConsole = Auth::isOwner() && str_starts_with($currentPublicPath, '/admin');
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<?php
$gaMeasurementId = trim(
    (string)(getenv('GA_MEASUREMENT_ID') ?: '')
);

$gaPath = parse_url(
    $_SERVER['REQUEST_URI'] ?? '/',
    PHP_URL_PATH
) ?: '/';

/*
 * Do not count owner/admin activity as public traffic.
 */
$gaExcluded =
    str_starts_with($gaPath, '/admin')
    || str_starts_with($gaPath, '/owner');

$gaEnabled =
    !$gaExcluded
    && preg_match(
        '/^G-[A-Z0-9]{6,20}$/',
        $gaMeasurementId
    );
?>

<?php if ($gaEnabled): ?>
<!-- Google tag (gtag.js) -->
<script async src="https://www.googletagmanager.com/gtag/js?id=<?= htmlspecialchars($gaMeasurementId, ENT_QUOTES, 'UTF-8') ?>"></script>
<script>
window.dataLayer = window.dataLayer || [];
function gtag(){dataLayer.push(arguments);}
gtag('js', new Date());
gtag('config', <?= json_encode($gaMeasurementId, JSON_UNESCAPED_SLASHES) ?>);
</script>
<?php endif; ?>

<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title><?= e($title ?? $siteName) ?></title><meta name="description" content="<?= e($metaDescription) ?>">
<?php if ($canonical !== current_url_path() && str_starts_with($canonical,'http')): ?><link rel="canonical" href="<?= e($canonical) ?>"><?php endif; ?>
<meta name="theme-color" content="<?= e($brandPrimary) ?>">
<?php if ($fontUrl): ?><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link rel="stylesheet" href="<?= e($fontUrl) ?>"><?php endif; ?>
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>?v=220">
<style>:root{--brand:<?= e($brandPrimary) ?>;--brand-2:<?= e($brandSecondary) ?>;--accent:<?= e($brandAccent) ?>;--site-bg:<?= e($siteBackground) ?>;--font-ui:<?= $fontStack ?>;--navy:<?= e($brandPrimary) ?>;--teal:<?= e($brandSecondary) ?>;--gold:<?= e($brandAccent) ?>;--bg:<?= e($siteBackground) ?>;--ink:#123536;--muted:#6e7f7d;--card:#fff;--surface:#fff;--ivory:#f7f6f0;--mint:#e8f1ec;--warm:#f4eadc}</style>
<?php if (!empty($breadcrumbs)): ?><script type="application/ld+json"><?= $breadcrumbs ?></script><?php endif; ?>
<?php if (!empty($articleJsonLd)): ?><script type="application/ld+json"><?= $articleJsonLd ?></script><?php endif; ?>
</head>
<body data-ga4-id="<?= e($ga4) ?>" data-internal-analytics="<?= $internalAnalytics?'1':'0' ?>" class="motion-<?= e($motionLevel) ?> v143-body <?= current_url_path()==='/'?'is-journey-home':'' ?> <?= $isOwnerConsole?'is-owner-console':'' ?>">
<?php if(!$isOwnerConsole && current_url_path()==='/'): ?>
<div class="kh-intro" data-kh-intro aria-hidden="true"><div class="kh-intro-inner">
  <?php if($logoPath): ?><span class="kh-intro-logo has-image"><img src="<?= e(url($logoPath)) ?>" alt=""></span><?php else: ?><span class="kh-intro-logo"><i></i><i></i><i></i></span><?php endif; ?>
  <strong><?= e($siteName) ?></strong><small>كل إجراء رقمي، بخطوات أوضح.</small>
</div></div>
<?php endif; ?>
<?php if(!$isOwnerConsole): ?>
<?php if($noticeEnabled && $noticeText): ?><div class="site-notice"><?= e($noticeText) ?></div><?php endif; ?>
<header class="site-header v143-site-header"><div class="container header-inner">
<a class="brand" href="<?= e(url('')) ?>" aria-label="الصفحة الرئيسية"><?php if($logoPath): ?><span class="brand-logo"><img src="<?= e(url($logoPath)) ?>" alt="<?= e($siteName) ?>"></span><?php else: ?><span class="brand-mark v216-brand-mark" aria-hidden="true"><i></i><i></i><i></i></span><?php endif; ?><span><strong><?= e($siteName) ?></strong><small><?= e($siteTagline) ?></small></span></a>
<button class="nav-toggle" type="button" data-nav-toggle aria-label="فتح القائمة">☰</button>
<nav class="main-nav" data-main-nav aria-label="التنقل الرئيسي"><a<?= $navState($navHomeActive) ?> href="<?= e(url('')) ?>">الرئيسية</a><a<?= $navState($navProceduresActive) ?> href="<?= e(url('procedures')) ?>">دليل الإجراءات</a><?php if (Auth::check()): ?><a<?= $navState($navAiActive) ?> href="<?= e(url('ask-ai')) ?>">اسأل خطوات AI</a><?php endif; ?><a<?= $navState($navPlansActive || $navBillingActive) ?> href="<?= e(url('plans')) ?>">الباقات</a><a<?= $navState($navUpdatesActive) ?> href="<?= e(url('updates')) ?>">التحديثات</a><a<?= $navState($navCalculatorsActive) ?> href="<?= e(url('calculators')) ?>">الحاسبات</a><a<?= $navState($navBlogActive) ?> href="<?= e(url('blog')) ?>">المقالات</a></nav>
<div class="header-actions"><?php if (Auth::check()): ?><?php if (Auth::isOwner()): ?><a class="btn btn-ghost btn-sm" href="<?= e(url('admin')) ?>"><?= $currentPublicPath==='/'?'حسابي':'لوحة المالك' ?></a><?php else: ?><a class="btn btn-soft btn-sm billing-header-credit" href="<?= e(url('billing')) ?>">رصيدي</a><a class="btn btn-ghost btn-sm" href="<?= e(url('account')) ?>">حسابي</a><?php endif; ?><form action="<?= e(url('logout')) ?>" method="post" class="inline-form"><?= csrf_field() ?><button class="btn btn-soft btn-sm" type="submit">خروج</button></form><?php else: ?><a class="btn btn-ghost btn-sm" href="<?= e(url('login')) ?>">دخول</a><a class="btn btn-primary btn-sm" href="<?= e(url('register')) ?>">إنشاء حساب</a><?php endif; ?></div>
</div></header>
<?php if ($showBanner): ?><div class="top-campaign-banner <?= $bannerSticky?'is-sticky':'' ?> v216-campaign" style="--banner-bg:<?= e($bannerBg) ?>;--banner-text:<?= e($bannerTextColor) ?>"><div class="container top-campaign-inner <?= $bannerMedia?'has-media':'' ?>"><?php if($bannerMedia): ?><div class="top-campaign-media"><?php if($bannerMediaType==='video'): ?><video src="<?= e(url($bannerMedia)) ?>" autoplay muted loop playsinline preload="metadata"></video><?php else: ?><img src="<?= e(url($bannerMedia)) ?>" alt="" loading="eager"><?php endif; ?></div><?php endif; ?><div class="top-campaign-copy"><span class="campaign-chip">جديد</span><div><strong><?= e($bannerText) ?></strong><?php if($bannerDescription): ?><small><?= e($bannerDescription) ?></small><?php endif; ?></div></div><?php if($bannerLinkText && $bannerLink !== '#'): ?><a class="top-campaign-action" href="<?= e($bannerLink) ?>"><?= e($bannerLinkText) ?> <span>←</span></a><?php endif; ?></div></div><?php endif; ?>
<?php endif; ?>
<?php foreach ($flashes as $flash): ?><div class="toast toast-<?= e($flash['type']) ?>" data-toast><?= e($flash['message']) ?><button type="button" aria-label="إغلاق">×</button></div><?php endforeach; ?>
<main class="<?= $isOwnerConsole?'owner-console-main':'' ?>"><?= $content ?></main>
<?php if(!$isOwnerConsole): ?>
<footer class="site-footer v217-footer">
  <div class="container v217-footer-top">
    <div class="v217-footer-brand">
      <a href="<?= e(url('')) ?>" class="v217-footer-logo" aria-label="<?= e($siteName) ?>">
        <span class="v217-footer-mark" aria-hidden="true"><i></i><i></i><i></i></span>
        <span><strong><?= e($siteName) ?></strong><small><?= e($siteTagline) ?></small></span>
      </a>
      <p><?= e((string)Settings::get('footer_description','دليلك العملي لفهم الإجراءات والخدمات في السعودية بخطوات أوضح وروابط مباشرة إلى المصادر الرسمية. خطوات منصة مستقلة وليست جهة حكومية ولا تنفذ المعاملات نيابة عن المستخدم.')) ?></p>
      <span class="v217-footer-trust">المصدر الرسمي أولًا · والقرار أوضح</span>
    </div>
    <div class="v217-footer-col"><h3>ابدأ من هنا</h3><a href="<?= e(url('procedures')) ?>">دليل الإجراءات</a><a href="<?= e(url('ask-ai')) ?>">اسأل خطوات AI</a><a href="<?= e(url('calculators')) ?>">الحاسبات</a><a href="<?= e(url('plans')) ?>">الباقات والأسعار</a></div>
    <div class="v217-footer-col"><h3>المعرفة</h3><a href="<?= e(url('updates')) ?>">التحديثات</a><a href="<?= e(url('blog')) ?>">المقالات</a><a href="<?= e(url('about')) ?>">من نحن</a></div>
    <div class="v217-footer-col"><h3>المنصة</h3><?php if (Auth::check() && !Auth::isOwner()): ?><a href="<?= e(url('billing')) ?>">الفوترة والرصيد</a><?php endif; ?><a href="<?= e(url('contact')) ?>">تواصل معنا</a><a href="<?= e(url('privacy')) ?>">الخصوصية والإعلانات</a><a href="<?= e(url('terms')) ?>">الشروط والأحكام</a></div>
  </div>
  <div class="container v217-footer-bottom"><span>© <?= date('Y') ?> <?= e($siteName) ?></span><span>المعلومة الإرشادية لا تستبدل المصدر الرسمي.</span></div>
</footer>
<div class="consent" data-consent hidden><div><strong>إعدادات القياس</strong><p>نستخدم أدوات قياس الأداء فقط بعد موافقتك، ولا نرسل بيانات هوية أو وثائق حكومية.</p></div><div class="consent-actions"><button class="btn btn-soft btn-sm" data-consent-deny>رفض غير الضروري</button><button class="btn btn-primary btn-sm" data-consent-allow>موافقة</button></div></div>
<?php endif; ?>
<script src="<?= e(asset('js/app.js')) ?>?v=219" defer></script>
</body></html>