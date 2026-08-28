<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Khatauat\Services\MarketingPublishService;

$summary=(new MarketingPublishService())->dispatchDue(25);
echo json_encode($summary,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
