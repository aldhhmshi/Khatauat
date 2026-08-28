<?php

declare(strict_types=1);

namespace Khatauat\Controllers;

use Khatauat\Core\Auth;
use Khatauat\Core\Csrf;
use Khatauat\Core\Database;
use Khatauat\Core\View;
use Khatauat\Services\SmartPathEvaluator;
use Khatauat\Services\PdfExportService;

final class PathController
{
    public function builder(string $slug): void
    {
        $service = Database::fetch("SELECT * FROM services WHERE slug=? AND status='published'",[$slug]);
        if (!$service) { http_response_code(404); View::render('errors/404',['title'=>'المسار غير موجود']); return; }
        $evaluator = new SmartPathEvaluator();
        $questions = $evaluator->questions((int)$service['id']);
        $answers = [];
        foreach ($questions as $q) if (isset($_GET[$q['question_key']])) $answers[$q['question_key']] = (string)$_GET[$q['question_key']];
        $complete = count($questions) > 0 && count($answers) >= count(array_filter($questions, fn($q)=>(int)$q['is_required']===1));
        $steps = $answers ? $evaluator->steps((int)$service['id'],$answers) : [];
        View::render('path/builder', ['title'=>'أنشئ مسارك — '.$service['name'],'service'=>$service,'questions'=>$questions,'answers'=>$answers,'steps'=>$steps,'complete'=>$complete]);
    }

    public function save(string $slug): void
    {
        Auth::requireUser(); Csrf::verify();
        $service = Database::fetch("SELECT * FROM services WHERE slug=? AND status='published'",[$slug]);
        if (!$service) { http_response_code(404); exit('Not found'); }
        $questions = (new SmartPathEvaluator())->questions((int)$service['id']);
        $answers=[]; foreach($questions as $q) if(isset($_POST[$q['question_key']])) $answers[$q['question_key']] = (string)$_POST[$q['question_key']];
        (new SmartPathEvaluator())->savePath((int)$service['id'],$answers);
        \flash('success','تم حفظ المسار في حسابك.');
        $query = http_build_query($answers);
        \redirect('path/'.$slug.($query?'?'.$query:''));
    }

    public function toggleStep(): void
    {
        Auth::requireUser(); Csrf::verify();
        $serviceId = (int)($_POST['service_id'] ?? 0); $stepId=(int)($_POST['step_id'] ?? 0);
        $step = Database::fetch('SELECT id FROM service_steps WHERE id=? AND service_id=?',[$stepId,$serviceId]);
        if(!$step) { http_response_code(422); exit('Invalid'); }
        $answersJson = trim((string)($_POST['answers_json'] ?? '{}'));
        if (json_decode($answersJson, true) === null && $answersJson !== 'null') $answersJson = '{}';
        $up=Database::fetch('SELECT id FROM user_paths WHERE user_id=? AND service_id=?',[Auth::id(),$serviceId]);
        if(!$up){ Database::execute('INSERT INTO user_paths(user_id,service_id,answers_json,created_at,updated_at) VALUES(?,?,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)',[Auth::id(),$serviceId,$answersJson]); $pathId=Database::lastInsertId(); }
        else { $pathId=(int)$up['id']; if($answersJson !== '{}') Database::execute('UPDATE user_paths SET answers_json=?,updated_at=CURRENT_TIMESTAMP WHERE id=?',[$answersJson,$pathId]); }
        $existing=Database::fetch('SELECT id,completed FROM user_step_progress WHERE user_path_id=? AND step_id=?',[$pathId,$stepId]);
        $new=$existing ? ((int)$existing['completed']===1?0:1) : 1;
        if($existing) Database::execute('UPDATE user_step_progress SET completed=?,completed_at=?,updated_at=CURRENT_TIMESTAMP WHERE id=?',[$new,$new?\now():null,(int)$existing['id']]);
        else Database::execute('INSERT INTO user_step_progress(user_path_id,step_id,completed,completed_at,updated_at) VALUES(?,?,?,?,CURRENT_TIMESTAMP)',[$pathId,$stepId,$new,$new?\now():null]);
        $return = trim((string)($_POST['return_to'] ?? 'account'));
        \redirect(ltrim($return,'/'));
    }

    public function pdf(string $slug): void
    {
        $service = Database::fetch("SELECT * FROM services WHERE slug=? AND status='published'",[$slug]);
        if(!$service){ http_response_code(404); exit('Not found'); }
        $eval=new SmartPathEvaluator(); $questions=$eval->questions((int)$service['id']); $answers=[];
        foreach($questions as $q) if(isset($_GET[$q['question_key']])) $answers[$q['question_key']] = (string)$_GET[$q['question_key']];
        $steps=$eval->steps((int)$service['id'],$answers);
        (new PdfExportService())->stream($service,$steps);
    }

    public function print(string $slug): void
    {
        $service = Database::fetch("SELECT * FROM services WHERE slug=? AND status='published'",[$slug]);
        if(!$service){ http_response_code(404); exit('Not found'); }
        $eval=new SmartPathEvaluator(); $questions=$eval->questions((int)$service['id']); $answers=[];
        foreach($questions as $q) if(isset($_GET[$q['question_key']])) $answers[$q['question_key']] = (string)$_GET[$q['question_key']];
        $steps=$eval->steps((int)$service['id'],$answers);
        View::render('path/print',['title'=>'تصدير المسار — '.$service['name'],'service'=>$service,'steps'=>$steps,'answers'=>$answers], 'print_layout');
    }
}
