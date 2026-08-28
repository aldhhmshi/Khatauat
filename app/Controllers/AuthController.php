<?php

declare(strict_types=1);

namespace Khatauat\Controllers;

use Khatauat\Core\Auth;
use Khatauat\Core\Csrf;
use Khatauat\Core\Database;
use Khatauat\Core\View;

final class AuthController
{
    public function login(): void
    {
        if(Auth::check()) \redirect(Auth::isOwner()?'admin':'account');
        if(\is_post()){
            Csrf::verify(); $email=trim((string)($_POST['email']??'')); $password=(string)($_POST['password']??'');
            if(Auth::attempt($email,$password)){ \flash('success','مرحبًا بعودتك.'); \redirect(Auth::isOwner()?'admin':'account'); }
            \flash('error','بيانات الدخول غير صحيحة أو توجد محاولات كثيرة.');
        }
        View::render('auth/login',['title'=>'تسجيل الدخول']);
    }

    public function register(): void
    {
        if(Auth::check()) \redirect('account');
        if(\is_post()){
            Csrf::verify(); $name=trim((string)($_POST['name']??'')); $email=trim((string)($_POST['email']??'')); $password=(string)($_POST['password']??'');
            if(mb_strlen($name)<2 || !filter_var($email,FILTER_VALIDATE_EMAIL) || mb_strlen($password)<10){ \flash('error','استخدم اسمًا صحيحًا وبريدًا صالحًا وكلمة مرور من 10 أحرف على الأقل.'); }
            elseif(Database::fetch('SELECT id FROM users WHERE lower(email)=lower(?)',[$email])) { \flash('error','البريد مستخدم بالفعل.'); }
            else { Database::execute('INSERT INTO users(name,email,password_hash,role,notifications_enabled,notification_frequency,created_at) VALUES(?,?,?,?,?,?,CURRENT_TIMESTAMP)',[$name,$email,password_hash($password,PASSWORD_DEFAULT),'user',1,'weekly']); Auth::attempt($email,$password); \flash('success','تم إنشاء الحساب.'); \redirect('account'); }
        }
        View::render('auth/register',['title'=>'إنشاء حساب']);
    }

    public function logout(): void { if(\is_post()) Csrf::verify(); Auth::logout(); \flash('success','تم تسجيل الخروج.'); \redirect(''); }
}
