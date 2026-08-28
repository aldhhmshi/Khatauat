<?php
declare(strict_types=1);
$root=dirname(__DIR__);$domainRoot=dirname(dirname($root));$publicRoot=$domainRoot.'/public_html';$stamp=date('YmdHis');
function o143(string $m):void{echo $m.PHP_EOL;}
function c143(string $src,string $dst,string $stamp):void{if(!is_file($src)){o143('MISSING '.$src);exit(1);}@mkdir(dirname($dst),0755,true);if(is_file($dst))@copy($dst,$dst.'.pre-v1.4.3-'.$stamp.'.bak');if(!@copy($src,$dst)){o143('COPY FAILED '.$dst);exit(1);}o143('COPIED '.$dst);}
o143('Khatauat v1.4.3 design reconstruction starting...');
c143($root.'/public/assets/css/app.css',$publicRoot.'/assets/css/app.css',$stamp);
c143($root.'/public/assets/js/app.js',$publicRoot.'/assets/js/app.js',$stamp);
$checks=[
 $root.'/resources/views/layout.php'=>['?v=143','--navy:','v143-site-header'],
 $root.'/resources/views/home.php'=>['v143-home-hero','v143-feature-grid'],
 $root.'/resources/views/admin/ads.php'=>['v143-banner-panel','v143-form-grid'],
 $root.'/resources/views/admin/analytics.php'=>['v143-kpis','v143-bars'],
 $root.'/resources/views/partials/admin_nav.php'=>['v143-admin-sidebar','v143-admin-nav'],
 $root.'/public/assets/css/app.css'=>['Khatauat v1.4.3','v143-admin-page','v143-form input'],
];
foreach($checks as $f=>$markers){$c=is_file($f)?(string)file_get_contents($f):'';$ok=$c!=='';foreach($markers as $m)$ok=$ok&&str_contains($c,$m);o143(($ok?'OK ':'WARNING ').$f);if(!$ok)exit(1);}
o143('Khatauat v1.4.3 applied: reconstructed templates + cache-busted assets + approved navy/teal/gold design.');
o143('No database migration, no .env reset, no AI/source/ad settings reset.');
