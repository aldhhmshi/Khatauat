<?php

declare(strict_types=1);

namespace Khatauat\Services;

use Khatauat\Core\Database;

final class SourceMonitor
{
    public function run(): array
    {
        $sources = Database::fetchAll('SELECT * FROM sources WHERE monitor_enabled = 1 ORDER BY id');
        $stats = ['checked' => 0, 'changed' => 0, 'failed' => 0];
        foreach ($sources as $source) {
            $stats['checked']++;
            try {
                $this->checkSource($source, $stats);
            } catch (\Throwable $e) {
                $stats['failed']++;
                Database::execute(
                    'INSERT INTO source_checks(source_id,status,http_status,error_message,checked_at) VALUES(?,?,?,?,CURRENT_TIMESTAMP)',
                    [(int)$source['id'], 'failed', null, mb_substr($e->getMessage(), 0, 500)]
                );
            }
        }
        return $stats;
    }

    private function checkSource(array $source, array &$stats): void
    {
        $url = trim((string)($source['url'] ?? ''));
        if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array(strtolower((string)parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            throw new \RuntimeException('رابط المصدر غير صالح للمراقبة.');
        }

        $headers = [
            'User-Agent: Khatauat-Monitor/1.3 (+independent-guidance-platform)',
            'Accept: text/html,application/xhtml+xml,application/json;q=0.9,text/plain;q=0.8,*/*;q=0.5',
        ];
        if (!empty($source['etag'])) $headers[] = 'If-None-Match: ' . $source['etag'];
        if (!empty($source['last_modified'])) $headers[] = 'If-Modified-Since: ' . $source['last_modified'];

        $context = stream_context_create(['http' => [
            'method' => 'GET',
            'timeout' => (int)\config('monitor_timeout', 12),
            'header' => implode("\r\n", $headers),
            'ignore_errors' => true,
            'follow_location' => 1,
            'max_redirects' => 4,
        ]]);

        $body = @file_get_contents($url, false, $context);
        $responseHeaders = $http_response_header ?? [];
        $status = 0;
        $etag = null;
        $lastModified = null;
        foreach ($responseHeaders as $h) {
            if (preg_match('#HTTP/\S+\s+(\d+)#', $h, $m)) $status = (int)$m[1];
            if (stripos($h, 'ETag:') === 0) $etag = trim(substr($h, 5));
            if (stripos($h, 'Last-Modified:') === 0) $lastModified = trim(substr($h, 14));
        }

        if ($status === 304) {
            Database::execute('UPDATE sources SET last_checked_at=CURRENT_TIMESTAMP WHERE id=?', [(int)$source['id']]);
            Database::execute('INSERT INTO source_checks(source_id,status,http_status,checked_at) VALUES(?,?,?,CURRENT_TIMESTAMP)', [(int)$source['id'], 'unchanged', 304]);
            return;
        }

        if ($body === false || $status >= 400 || $status === 0) {
            throw new \RuntimeException('تعذر جلب المصدر. HTTP ' . $status);
        }

        $clean = $this->normalizeContent((string)$body);
        if (mb_strlen($clean) < 120) {
            throw new \RuntimeException('المحتوى المستخرج قصير جدًا للمقارنة الموثوقة.');
        }

        $hash = hash('sha256', $clean);
        $previousHash = trim((string)($source['content_hash'] ?? ''));
        $isFirstCapture = $previousHash === '';
        $changed = !$isFirstCapture && !hash_equals($previousHash, $hash);

        Database::execute(
            'UPDATE sources SET content_hash=?, etag=?, last_modified=?, last_checked_at=CURRENT_TIMESTAMP WHERE id=?',
            [$hash, $etag, $lastModified, (int)$source['id']]
        );
        Database::execute(
            'INSERT INTO source_checks(source_id,status,http_status,content_hash,checked_at) VALUES(?,?,?,?,CURRENT_TIMESTAMP)',
            [(int)$source['id'], $changed ? 'changed' : ($isFirstCapture ? 'baseline' : 'ok'), $status, $hash]
        );

        if ($isFirstCapture || $changed) {
            $this->saveSnapshot((int)$source['id'], $hash, $clean);
        }

        if ($changed) {
            $stats['changed']++;
            Database::execute(
                'INSERT INTO updates(title,entity,old_text,new_text,impact,source_id,status,detected_at,created_at) VALUES(?,?,?,?,?,?,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)',
                [
                    'تغيير مكتشف في مصدر: ' . $source['title'],
                    $source['entity'],
                    'تمت مقارنة البصمة مع النسخة السابقة.',
                    'اختلف المحتوى النصي المطبّع للمصدر الرسمي.',
                    'يحتاج مراجعة بشرية ومقارنة اللقطات قبل تعديل أي خدمة أو مقال.',
                    (int)$source['id'],
                    'draft',
                ]
            );
        }
    }

    private function saveSnapshot(int $sourceId, string $hash, string $clean): void
    {
        $snapshotDir = \root_path('storage/snapshots');
        if (!is_dir($snapshotDir)) mkdir($snapshotDir, 0775, true);
        $file = $snapshotDir . '/source-' . $sourceId . '-' . date('YmdHis') . '.txt';
        file_put_contents($file, $clean, LOCK_EX);
        Database::execute(
            'INSERT INTO source_snapshots(source_id,content_hash,storage_path,captured_at) VALUES(?,?,?,CURRENT_TIMESTAMP)',
            [$sourceId, $hash, $file]
        );
    }

    private function normalizeContent(string $body): string
    {
        $text = $body;
        if (class_exists(\DOMDocument::class)) {
            $dom = new \DOMDocument();
            $previous = libxml_use_internal_errors(true);
            if (@$dom->loadHTML('<?xml encoding="utf-8" ?>' . $body, LIBXML_NOWARNING | LIBXML_NOERROR)) {
                $xpath = new \DOMXPath($dom);
                foreach (['//script', '//style', '//noscript', '//svg', '//nav', '//footer'] as $query) {
                    foreach ($xpath->query($query) ?: [] as $node) {
                        $node->parentNode?->removeChild($node);
                    }
                }
                $text = (string)$dom->textContent;
            }
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        } else {
            $text = preg_replace('#<(script|style|noscript|svg|nav|footer)\b[^>]*>.*?</\1>#isu', ' ', $body) ?? $body;
            $text = strip_tags($text);
        }

        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        return trim($text);
    }
}
