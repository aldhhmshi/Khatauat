<?php

declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

use Khatauat\Core\Router;
use Khatauat\Core\View;
use Khatauat\Controllers\HomeController;
use Khatauat\Controllers\ServiceController;
use Khatauat\Controllers\PathController;
use Khatauat\Controllers\AuthController;
use Khatauat\Controllers\AccountController;
use Khatauat\Controllers\ContentController;
use Khatauat\Controllers\AdminController;
use Khatauat\Controllers\OwnerOpsController;
use Khatauat\Controllers\AiController;

$router = new Router();

$router->get('/', [HomeController::class, 'index']);
$router->get('/search', [HomeController::class, 'search']);
$router->get('/about', [HomeController::class, 'about']);
$router->get('/privacy', [HomeController::class, 'privacy']);
$router->get('/terms', [HomeController::class, 'terms']);
$router->get('/contact', [HomeController::class, 'contact']);
$router->post('/contact', [HomeController::class, 'contact']);
$router->get('/sitemap.xml', [HomeController::class, 'sitemap']);
$router->get('/robots.txt', [HomeController::class, 'robots']);
$router->post('/ad/event', [HomeController::class, 'adEvent']);
$router->post('/analytics/event', [HomeController::class, 'analyticsEvent']);

$router->get('/procedures', [ServiceController::class, 'index']);
$router->get('/service/{slug}', [ServiceController::class, 'show']);
$router->get('/trust/{slug}', [ServiceController::class, 'trust']);
$router->post('/trust/{slug}/report', [ServiceController::class, 'report']);

$router->get('/path/{slug}', [PathController::class, 'builder']);
$router->post('/path/{slug}/save', [PathController::class, 'save']);
$router->post('/account/step/toggle', [PathController::class, 'toggleStep']);
$router->get('/export/{slug}/print', [PathController::class, 'print']);
$router->get('/export/{slug}/pdf', [PathController::class, 'pdf']);

$router->get('/blog', [ContentController::class, 'blog']);
$router->get('/article/{slug}', [ContentController::class, 'article']);
$router->get('/calculators', [ContentController::class, 'calculators']);
$router->get('/updates', [ContentController::class, 'updates']);

$router->get('/login', [AuthController::class, 'login']);
$router->post('/login', [AuthController::class, 'login']);
$router->get('/register', [AuthController::class, 'register']);
$router->post('/register', [AuthController::class, 'register']);
$router->post('/logout', [AuthController::class, 'logout']);
$router->get('/account', [AccountController::class, 'index']);
$router->post('/account/settings', [AccountController::class, 'settings']);
$router->post('/account/follow', [AccountController::class, 'follow']);

$router->get('/ask-ai', [AiController::class, 'index']);
$router->post('/ask-ai', [AiController::class, 'ask']);

$router->get('/admin', [AdminController::class, 'dashboard']);
$router->get('/admin/services', [AdminController::class, 'services']);
$router->post('/admin/category/save', [AdminController::class, 'categorySave']);
$router->post('/admin/category/delete', [AdminController::class, 'categoryDelete']);
$router->get('/admin/service/new', [AdminController::class, 'serviceNew']);
$router->get('/admin/service/{id}', [AdminController::class, 'serviceEdit']);
$router->post('/admin/service/save', [AdminController::class, 'serviceSave']);
$router->post('/admin/step/save', [AdminController::class, 'stepSave']);
$router->post('/admin/step/delete', [AdminController::class, 'stepDelete']);
$router->post('/admin/question/save', [AdminController::class, 'questionSave']);
$router->post('/admin/rule/save', [AdminController::class, 'ruleSave']);
$router->post('/admin/rule/delete', [AdminController::class, 'ruleDelete']);
$router->get('/admin/sources', [AdminController::class, 'sources']);
$router->post('/admin/source/save', [AdminController::class, 'sourceSave']);
$router->get('/admin/trust-reports', [AdminController::class, 'trustReports']);
$router->post('/admin/trust-report/status', [AdminController::class, 'trustStatus']);
$router->get('/admin/content', [AdminController::class, 'content']);
$router->post('/admin/article/save', [AdminController::class, 'articleSave']);
$router->post('/admin/calculator/save', [AdminController::class, 'calculatorSave']);
$router->post('/admin/update/publish', [AdminController::class, 'updatePublish']);
$router->get('/admin/ads', [AdminController::class, 'ads']);
$router->post('/admin/ad/save', [AdminController::class, 'adSave']);
$router->post('/admin/ad/status', [AdminController::class, 'adStatus']);
$router->post('/admin/banner/save', [AdminController::class, 'bannerSave']);
$router->post('/admin/banner/delete', [AdminController::class, 'bannerDelete']);
$router->get('/admin/analytics', [AdminController::class, 'analytics']);
$router->get('/admin/appearance', [AdminController::class, 'appearance']);
$router->post('/admin/appearance', [AdminController::class, 'appearance']);
$router->post('/admin/home-block/save', [AdminController::class, 'homepageBlockSave']);
$router->post('/admin/home-block/delete', [AdminController::class, 'homepageBlockDelete']);
$router->get('/admin/settings', [AdminController::class, 'settings']);
$router->post('/admin/settings', [AdminController::class, 'settings']);
$router->get('/admin/ai-drafts', [AdminController::class, 'aiDrafts']);
$router->post('/admin/ai-generate', [AdminController::class, 'aiGenerate']);
$router->post('/admin/ai-draft/to-article', [AdminController::class, 'aiDraftToArticle']);
$router->post('/admin/ai-draft/publish', [AdminController::class, 'aiDraftPublish']);
$router->post('/admin/ai-draft/delete', [AdminController::class, 'aiDraftDelete']);
$router->post('/admin/article/status', [AdminController::class, 'articleStatus']);
$router->post('/admin/article/delete', [AdminController::class, 'articleDelete']);

$router->get('/admin/ai-ops', [OwnerOpsController::class, 'aiOps']);
$router->post('/admin/ai-ops/run', [OwnerOpsController::class, 'aiRun']);
$router->post('/admin/ai-ops/status', [OwnerOpsController::class, 'aiOperationStatus']);
$router->get('/admin/integrations', [OwnerOpsController::class, 'integrations']);
$router->post('/admin/integrations/save', [OwnerOpsController::class, 'integrationSave']);
$router->get('/admin/marketing', [OwnerOpsController::class, 'marketing']);
$router->post('/admin/marketing/generate', [OwnerOpsController::class, 'marketingGenerate']);
$router->post('/admin/marketing/asset-status', [OwnerOpsController::class, 'marketingAssetStatus']);
$router->post('/admin/marketing/publish', [OwnerOpsController::class, 'marketingPublish']);
$router->get('/admin/source-registry', [OwnerOpsController::class, 'sourceRegistry']);
$router->post('/admin/source-registry/seed', [OwnerOpsController::class, 'sourceRegistrySeed']);
$router->post('/admin/source-registry/discover', [OwnerOpsController::class, 'sourceRegistryDiscover']);
$router->post('/admin/source-registry/approve', [OwnerOpsController::class, 'sourceRegistryApprove']);

try {
    $router->dispatch(strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'), $_SERVER['REQUEST_URI'] ?? '/');
} catch (Throwable $e) {
    if (config('debug', false)) throw $e;
    http_response_code(500);
    $message = e($e->getMessage());
    $css = e(asset('css/app.css'));
    echo '<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>تعذر تشغيل المنصة</title><link rel="stylesheet" href="'.$css.'"></head><body><section class="error-page"><div class="container narrow"><span>500</span><h1>تعذر تشغيل المنصة</h1><p>'.$message.'</p><p>تحقق من متطلبات PHP وخصوصًا <code>pdo_sqlite</code> و<code>mbstring</code>.</p></div></section></body></html>';
}
