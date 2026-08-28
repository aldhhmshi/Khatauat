<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$files = [
    $root . '/app/Services/AiDraftService.php',
    $root . '/app/Services/ArticleDraftMapper.php',
];

foreach ($files as $file) {
    if (!is_file($file)) {
        fwrite(STDERR, "MISSING {$file}\n");
        exit(1);
    }
    $out = [];
    $code = 0;
    exec('php -l ' . escapeshellarg($file) . ' 2>&1', $out, $code);
    if ($code !== 0) {
        fwrite(STDERR, "PHP syntax check failed: {$file}\n" . implode("\n", $out) . "\n");
        exit(1);
    }
    echo "OK syntax " . basename($file) . "\n";
}

echo "Khatauat v1.3.5.1 service SEO content pack applied.\n";
echo "SEO questions 5-20 + related services 5-10 + official source/links + steps/conditions/fees/documents/updates + FAQ + Schema + internal links enabled.\n";
