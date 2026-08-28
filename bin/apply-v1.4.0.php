<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
$projectRoot=dirname(__DIR__); $domainRoot=dirname(dirname($projectRoot)); $public=$domainRoot.'/public_html';
@mkdir($projectRoot.'/public/assets/uploads',0755,true); @mkdir($public.'/assets/uploads',0755,true); @mkdir($public.'/assets/css',0755,true);
$srcCss=$projectRoot.'/public/assets/css/app.css'; $dstCss=$public.'/assets/css/app.css';
if(is_file($srcCss)){if(is_file($dstCss))@copy($dstCss,$dstCss.'.pre-v1.4.0-'.date('YmdHis').'.bak'); if(@copy($srcCss,$dstCss)) echo "COPIED public_html/assets/css/app.css\n";}
$index=$public.'/index.php';
$routes=[
 "$"."router->get('/admin/appearance', [AdminController::class, 'appearance']);",
 "$"."router->post('/admin/appearance', [AdminController::class, 'appearance']);",
 "$"."router->post('/admin/home-block/save', [AdminController::class, 'homepageBlockSave']);",
 "$"."router->post('/admin/home-block/delete', [AdminController::class, 'homepageBlockDelete']);",
 "$"."router->post('/admin/banner/save', [AdminController::class, 'bannerSave']);",
 "$"."router->post('/admin/banner/delete', [AdminController::class, 'bannerDelete']);",
];
if(is_file($index)){
 $s=file_get_contents($index); $orig=$s; $anchor="$"."router->post('/admin/ad/status', [AdminController::class, 'adStatus']);";
 foreach($routes as $route){preg_match("#'(/admin/[^']+)'#",$route,$m);$path=$m[1]??$route;if(str_contains($s,$route)){echo "OK route {$path}\n";continue;} if(str_contains($s,$anchor)){$s=str_replace($anchor,$anchor.PHP_EOL.$route,$s);$anchor=$route;} else echo "WARNING route anchor missing {$path}\n";}
 if($s!==$orig){$bak=$index.'.pre-v1.4.0-'.date('YmdHis').'.bak';@copy($index,$bak);file_put_contents($index,$s,LOCK_EX);echo "PATCHED public_html/index.php\nBACKUP {$bak}\n";}
}
echo "Khatauat v1.4.0 modern UI + appearance CMS + top banner applied.\n";
