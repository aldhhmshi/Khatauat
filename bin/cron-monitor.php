<?php

declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

use Khatauat\Services\SourceMonitor;

try {
    $stats=(new SourceMonitor())->run();
    echo sprintf("checked=%d changed=%d failed=%d\n",$stats['checked'],$stats['changed'],$stats['failed']);
} catch (Throwable $e) {
    file_put_contents(dirname(__DIR__).'/storage/logs/monitor.log','['.date('c').'] '.$e->getMessage().PHP_EOL,FILE_APPEND);
    fwrite(STDERR,$e->getMessage().PHP_EOL);
    exit(1);
}
