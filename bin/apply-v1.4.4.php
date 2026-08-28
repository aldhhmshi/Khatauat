<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$domainRoot=dirname(dirname($root));
$publicRoot=$domainRoot.'/public_html';
$stamp=date('YmdHis');
function o144(string $m):void{echo $m.PHP_EOL;}
function c144(string $src,string $dst,string $stamp):void{
    if(!is_file($src)){o144('MISSING '.$src);exit(1);}
    @mkdir(dirname($dst),0755,true);
    if(is_file($dst))@copy($dst,$dst.'.pre-v1.4.4-'.$stamp.'.bak');
    if(!@copy($src,$dst)){o144('COPY FAILED '.$dst);exit(1);}
    o144('COPIED '.$dst);
}
o144('Khatauat v1.4.4 public navigation UX fix starting...');
c144($root.'/public/assets/css/app.css',$publicRoot.'/assets/css/app.css',$stamp);
c144($root.'/public/assets/js/app.js',$publicRoot.'/assets/js/app.js',$stamp);
$checks=[
 $root.'/resources/views/layout.php'=>['v1.4.4','?v=144','aria-current="page"','التنقل الرئيسي'],
 $root.'/public/assets/css/app.css'=>['Khatauat v1.4.4','main-nav a.is-current','nav-admin{display:none'],
 $root.'/public/assets/js/app.js'=>['v1.4.4 navigation-state fallback'],
];
foreach($checks as $f=>$markers){
    $c=is_file($f)?(string)file_get_contents($f):'';
    $ok=$c!=='';
    foreach($markers as $m)$ok=$ok&&str_contains($c,$m);
    o144(($ok?'OK ':'WARNING ').$f);
    if(!$ok)exit(1);
}
o144('Khatauat v1.4.4 applied: active public nav state is explicit; public Owner Dashboard button removed.');
o144('No database migration, no .env reset, no AI/source/ad settings reset.');
