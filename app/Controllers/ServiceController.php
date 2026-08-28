<?php

declare(strict_types=1);

namespace Khatauat\Controllers;

use Khatauat\Core\Database;
use Khatauat\Core\View;
use Khatauat\Core\Auth;
use Khatauat\Services\SmartPathEvaluator;
use Khatauat\Services\Seo;

final class ServiceController
{
    public function index(): void
    {
        $category = trim((string)($_GET['category'] ?? ''));
        $entity = trim((string)($_GET['entity'] ?? ''));
        $where = ["s.status='published'"];
        $params = [];
        if ($category !== '') { $where[] = 'c.slug=?'; $params[] = $category; }
        if ($entity !== '') { $where[] = 's.official_entity=?'; $params[] = $entity; }
        $services = Database::fetchAll('SELECT s.*, c.name category_name, c.slug category_slug FROM services s LEFT JOIN categories c ON c.id=s.category_id WHERE '.implode(' AND ',$where).' ORDER BY s.name', $params);
        $categories = Database::fetchAll('SELECT * FROM categories ORDER BY name');
        View::render('services/index', ['title'=>'دليل الإجراءات','services'=>$services,'categories'=>$categories,'category'=>$category,'entity'=>$entity]);
    }

    public function show(string $slug): void
    {
        $service = Database::fetch("SELECT s.*, c.name category_name, c.slug category_slug FROM services s LEFT JOIN categories c ON c.id=s.category_id WHERE s.slug=? AND s.status='published'", [$slug]);
        if (!$service) { http_response_code(404); View::render('errors/404',['title'=>'الخدمة غير موجودة']); return; }
        $evaluator = new SmartPathEvaluator();
        $steps = $evaluator->steps((int)$service['id']);
        $sources = Database::fetchAll('SELECT DISTINCT src.* FROM sources src JOIN service_steps ss ON ss.source_id=src.id WHERE ss.service_id=? ORDER BY src.verified_at DESC', [(int)$service['id']]);
        $changes = Database::fetchAll("SELECT * FROM content_changes WHERE service_id=? AND status='approved' ORDER BY changed_at DESC LIMIT 10", [(int)$service['id']]);
        $questions = $evaluator->questions((int)$service['id']);
        $isFollowed = Auth::id() ? (bool)Database::fetch('SELECT id FROM follows WHERE user_id=? AND follow_type="service" AND follow_id=?',[Auth::id(),(int)$service['id']]) : false;
        $breadcrumbs = Seo::breadcrumbJsonLd([
            ['name'=>'الرئيسية','url'=>\url('')],
            ['name'=>'دليل الإجراءات','url'=>\url('procedures')],
            ['name'=>$service['name'],'url'=>\url('service/'.$service['slug'])],
        ]);
        $serviceJsonLd = Seo::serviceJsonLd($service);
        View::render('services/show', compact('service','steps','sources','changes','questions','breadcrumbs','serviceJsonLd','isFollowed') + ['title'=>$service['seo_title'] ?: $service['name'], 'metaDescription'=>$service['seo_description'] ?: $service['summary']]);
    }

    public function trust(string $slug): void
    {
        $service = Database::fetch("SELECT * FROM services WHERE slug=? AND status='published'",[$slug]);
        if (!$service) { http_response_code(404); View::render('errors/404',['title'=>'غير موجود']); return; }
        $steps = Database::fetchAll('SELECT ss.*, src.title source_title, src.url source_url, src.verified_at source_verified_at, src.verified_by source_verified_by FROM service_steps ss LEFT JOIN sources src ON src.id=ss.source_id WHERE ss.service_id=? ORDER BY ss.position',[(int)$service['id']]);
        $changes = Database::fetchAll("SELECT cc.*, ss.title step_title FROM content_changes cc LEFT JOIN service_steps ss ON ss.id=cc.step_id WHERE cc.service_id=? AND cc.status='approved' ORDER BY cc.changed_at DESC",[(int)$service['id']]);
        View::render('services/trust', ['title'=>'مركز الثقة — '.$service['name'],'service'=>$service,'steps'=>$steps,'changes'=>$changes]);
    }

    public function report(string $slug): void
    {
        \Khatauat\Core\Csrf::verify();
        $service = Database::fetch("SELECT id FROM services WHERE slug=? AND status='published'",[$slug]);
        if (!$service) { http_response_code(404); exit('Not found'); }
        $message = trim((string)($_POST['message'] ?? ''));
        if (mb_strlen($message) < 10) { \flash('error','اكتب وصفًا أوضح للمعلومة التي تحتاج مراجعة.'); \redirect('trust/'.$slug); }
        Database::execute('INSERT INTO trust_reports(service_id,step_id,user_id,message,status,created_at) VALUES(?,?,?,?,?,CURRENT_TIMESTAMP)', [(int)$service['id'], !empty($_POST['step_id'])?(int)$_POST['step_id']:null, \Khatauat\Core\Auth::id(), $message,'new']);
        \flash('success','تم إرسال البلاغ للمراجعة. لن يتغير المحتوى المنشور تلقائيًا.');
        \redirect('trust/'.$slug);
    }
}
