<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Khatauat\Services\ExaResearchService;
use Khatauat\Services\OpenAiAgentService;
use Khatauat\Services\SaudiSourceRegistry;

$focus=trim((string)($argv[1]??''));
if($focus===''){
    $focuses=[
        'الأعمال والتجارة والتراخيص',
        'الهوية والداخلية والجوازات',
        'العمل والتوظيف والدعم',
        'العقار والإسكان والبلديات والبناء',
        'الصحة والتعليم والتدريب',
        'النقل والسفر والحج والعمرة والتأشيرات',
        'الاستثمار والصناعة والبيئة والطاقة',
    ];
    $focus=$focuses[(int)date('z') % count($focuses)];
}
$exa=new ExaResearchService();$ai=new OpenAiAgentService();$registry=new SaudiSourceRegistry();
$result=null;$provider='';
if($exa->isConfigured()){
    $research=$exa->searchSaudiSources($focus,12);
    if($research['ok']??false){
        if($ai->isConfigured()){$result=$ai->sourceDiscoveryFromEvidence($focus,(array)($research['results']??[]));$provider='exa+openai';}
        else {$result=['ok'=>true,'data'=>['sources'=>$exa->asRegistryCandidates((array)($research['results']??[]),$focus)]];$provider='exa';}
    }
}
if(!$result && $ai->isConfigured()){$result=$ai->sourceDiscoveryPrompt($focus);$provider='openai_web_search';}
if(!$result || !($result['ok']??false)){
    fwrite(STDERR,"No discovery provider configured or discovery failed. Configure EXA_API_KEY and/or OPENAI_API_KEY + OPENAI_MODEL.\n");
    exit(2);
}
$rows=is_array($result['data']['sources']??null)?$result['data']['sources']:[];
$added=$registry->importCandidates($rows);
echo json_encode(['ok'=>true,'provider'=>$provider,'focus'=>$focus,'found'=>count($rows),'added_candidates'=>$added],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
