<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use Khatauat\Services\BillingService;
try{echo json_encode(['ok'=>true,'result'=>(new BillingService())->maintenance(),'at'=>date('c')],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT).PHP_EOL;}catch(Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);}
