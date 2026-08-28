<?php

declare(strict_types=1);

namespace Khatauat\Controllers;

use Khatauat\Core\Auth;
use Khatauat\Core\Csrf;
use Khatauat\Core\Database;
use Khatauat\Core\View;
use Khatauat\Services\BillingService;
use Khatauat\Services\MoyasarGateway;
use Khatauat\Services\SecurityService;

final class AdminBillingController
{
    public function index(): void
    {
        Auth::requireOwner();
        $gateway=new MoyasarGateway();
        View::render('admin/billing',[
            'title'=>'الفوترة والباقات',
            'products'=>Database::fetchAll('SELECT * FROM billing_products ORDER BY sort_order,id'),
            'orders'=>Database::fetchAll('SELECT o.*,u.email user_email FROM billing_orders o LEFT JOIN users u ON u.id=o.user_id ORDER BY o.id DESC LIMIT 60'),
            'subscriptions'=>Database::fetchAll("SELECT s.*,u.email user_email,p.name product_name FROM billing_subscriptions s LEFT JOIN users u ON u.id=s.user_id LEFT JOIN billing_products p ON p.id=s.product_id ORDER BY s.id DESC LIMIT 40"),
            'gateway_mode'=>$gateway->mode(),
            'webhook_configured'=>$gateway->webhookConfigured(),
        ]);
    }

    public function saveProduct(): void
    {
        Auth::requireOwner(); Csrf::verify();
        SecurityService::rateLimit('owner_billing_save',(string)Auth::id(),30,300);
        $id=(int)($_POST['id'] ?? 0);
        $priceRiyal=(float)($_POST['price_riyal'] ?? 0);
        $cases=(int)($_POST['included_cases'] ?? 0);
        $messages=(int)($_POST['case_message_limit'] ?? 12);
        $validity=(int)($_POST['validity_days'] ?? 30);
        $status=((string)($_POST['status'] ?? 'active'))==='inactive'?'inactive':'active';
        if($id<1 || $priceRiyal<1 || $priceRiyal>10000 || $cases<1 || $cases>1000 || $messages<3 || $messages>100 || $validity<1 || $validity>730){
            \flash('error','تحقق من السعر وعدد المشاكل وحد الرسائل ومدة الصلاحية.');\redirect('admin/billing');
        }
        Database::execute('UPDATE billing_products SET price_minor=?,included_cases=?,case_message_limit=?,validity_days=?,status=?,updated_at=CURRENT_TIMESTAMP WHERE id=?',[(int)round($priceRiyal*100),$cases,$messages,$validity,$status,$id]);
        SecurityService::event('billing_product_updated','info',['product_id'=>$id],(int)Auth::id());
        \flash('success','تم تحديث الباقة.');\redirect('admin/billing');
    }
}
