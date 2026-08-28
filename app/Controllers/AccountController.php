<?php

declare(strict_types=1);

namespace Khatauat\Controllers;

use Khatauat\Core\Auth;
use Khatauat\Core\Csrf;
use Khatauat\Core\Database;
use Khatauat\Core\View;
use Khatauat\Services\SmartPathEvaluator;

final class AccountController
{
    public function index(): void
    {
        if (Auth::isOwner()) { \redirect('admin'); }
        Auth::requireUser();
        $paths=Database::fetchAll("SELECT up.*, s.name service_name,s.slug FROM user_paths up JOIN services s ON s.id=up.service_id WHERE up.user_id=? ORDER BY up.updated_at DESC",[Auth::id()]);
        $evaluator = new SmartPathEvaluator();
        foreach ($paths as &$path) {
            $answers = json_decode((string)$path['answers_json'], true) ?: [];
            $steps = $evaluator->steps((int)$path['service_id'], $answers);
            $path['total_steps'] = count($steps);
            $path['completed_steps'] = count(array_filter($steps, fn($s)=>(bool)($s['completed'] ?? false)));
            $path['answers'] = $answers;
        }
        unset($path);
        $notifications=Database::fetchAll('SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 20',[Auth::id()]);
        $follows=Database::fetchAll('SELECT f.*, s.name service_name,s.slug FROM follows f LEFT JOIN services s ON f.follow_type="service" AND s.id=f.follow_id WHERE f.user_id=? ORDER BY f.created_at DESC',[Auth::id()]);
        View::render('account/index',['title'=>'حسابي','paths'=>$paths,'notifications'=>$notifications,'follows'=>$follows]);
    }

    public function settings(): void
    {
        Auth::requireUser(); Csrf::verify();
        $enabled=isset($_POST['notifications_enabled'])?1:0; $freq=(string)($_POST['notification_frequency']??'weekly'); if(!in_array($freq,['instant','daily','weekly'],true))$freq='weekly';
        Database::execute('UPDATE users SET notifications_enabled=?,notification_frequency=?,updated_at=CURRENT_TIMESTAMP WHERE id=?',[$enabled,$freq,Auth::id()]);
        \flash('success','تم حفظ تفضيلات التنبيهات.'); \redirect('account');
    }

    public function follow(): void
    {
        Auth::requireUser(); Csrf::verify();
        $type=(string)($_POST['follow_type']??'service'); $id=(int)($_POST['follow_id']??0); $allowed=['service','category','entity','topic']; if(!in_array($type,$allowed,true)||$id<1){http_response_code(422);exit('Invalid');}
        $existing=Database::fetch('SELECT id FROM follows WHERE user_id=? AND follow_type=? AND follow_id=?',[Auth::id(),$type,$id]);
        if($existing) Database::execute('DELETE FROM follows WHERE id=?',[(int)$existing['id']]); else Database::execute('INSERT INTO follows(user_id,follow_type,follow_id,frequency,created_at) VALUES(?,?,?,?,CURRENT_TIMESTAMP)',[Auth::id(),$type,$id,Auth::user()['notification_frequency']??'weekly']);
        \redirect(trim((string)($_POST['return_to']??'account'),'/'));
    }
}
