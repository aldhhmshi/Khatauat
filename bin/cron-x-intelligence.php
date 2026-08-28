<?php

declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';

use Khatauat\Services\SocialIntelligenceService;
use Khatauat\Services\OfficialXAccountDiscoveryService;
use Khatauat\Core\Settings;

try{
    $service=new SocialIntelligenceService();
    $sourceId=null;$force=false;
    foreach(array_slice($argv,1) as $arg){
        if(str_starts_with($arg,'--source=')) $sourceId=(int)substr($arg,9);
        if($arg==='--force') $force=true;
    }
    if($sourceId!==null&&$sourceId>0){
        $row=\Khatauat\Core\Database::fetch("SELECT id FROM social_watch_targets WHERE source_id=? AND active=1 AND network='x' LIMIT 1",[$sourceId]);
        if(!$row) throw new RuntimeException('No X watch target for source_id='.$sourceId);
        if((string)Settings::get('x_handle_discovery_enabled','1')==='1'){
            try{(new OfficialXAccountDiscoveryService())->discoverTarget((int)$row['id']);}catch(Throwable){}
        }
        $result=$service->scanTarget((int)$row['id'],$force);
    }else{
        $discovery=['ok'=>true,'skipped'=>true];
        if((string)Settings::get('x_handle_discovery_enabled','1')==='1'){
            $discovery=(new OfficialXAccountDiscoveryService())->discoverBatch();
        }
        $scan=$service->dailyScan();
        $result=['ok'=>(bool)($scan['ok']??false),'handle_discovery'=>$discovery,'x_scan'=>$scan,'status'=>$service->statusSummary()];
    }
    echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT).PHP_EOL;
    if(!($result['ok']??false)) exit(1);
}catch(Throwable $e){
    $logDir = storage_path('logs');
    if (!is_dir($logDir)) @mkdir($logDir, 0770, true);
    $log=$logDir.'/x-intelligence.log';
    @file_put_contents($log,'['.date('c').'] '.$e->getMessage().PHP_EOL,FILE_APPEND);
    fwrite(STDERR,$e->getMessage().PHP_EOL);
    exit(1);
}
