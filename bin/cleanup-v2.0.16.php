<?php
declare(strict_types=1);

// Safe deployment cleanup for khatauat.com.
// - Never deletes public_html, khatauat_app_v2, backups, .env or the live SQLite DB.
// - Deletes temporary extraction folders and old patch archives.
// - Moves legacy rollback folders into backups instead of deleting them.

$domainRoot=dirname(__DIR__,2);
$backupRoot=$domainRoot.'/backups';
$stamp=date('Ymd_His');
$legacyTarget=$backupRoot.'/legacy_cleanup_'.$stamp;
@mkdir($backupRoot,0775,true);

$deleted=[];$moved=[];$skipped=[];

foreach(glob($domainRoot.'/tmp_extract_*') ?: [] as $p){
    if(is_dir($p)){
        $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($p,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach($it as $f){$f->isDir()?@rmdir($f->getPathname()):@unlink($f->getPathname());}
        if(@rmdir($p))$deleted[]=basename($p); else $skipped[]=basename($p);
    }
}

// Old deployment patch archives only. Keep the current v2.0.16 package until the user chooses to remove it.
$patterns=['Khatauat_v2.0.*.zip','Khatauat_v2.0.*.sha256','Khatauat_MVP_*.zip'];
foreach($patterns as $pattern){
    foreach(glob($domainRoot.'/'.$pattern) ?: [] as $p){
        $b=basename($p);
        if(str_contains($b,'v2.0.16')) continue;
        if(is_file($p) && @unlink($p)) $deleted[]=$b; else $skipped[]=$b;
    }
}

// Keep rollback material, but remove it from the domain root.
foreach(['v1.4.1','Khatauat-v2.0.1','public_html_old_20260826','kh'] as $name){
    $src=$domainRoot.'/'.$name;
    if(!file_exists($src)) continue;
    @mkdir($legacyTarget,0775,true);
    $dst=$legacyTarget.'/'.$name;
    if(@rename($src,$dst))$moved[]=$name; else $skipped[]=$name;
}

$out=[
  'ok'=>true,
  'domain_root'=>$domainRoot,
  'deleted'=>$deleted,
  'moved_to_backups'=>$moved,
  'skipped'=>$skipped,
  'protected'=>['public_html','khatauat_app_v2','backups','.env','storage/database/khatauat.sqlite'],
];
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT).PHP_EOL;
