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

        [$body, $status, $etag, $lastModified] = $this->fetchUrl($url, $source);

        if ($status === 304) {
            Database::execute(
                'UPDATE sources SET last_checked_at=CURRENT_TIMESTAMP WHERE id=?',
                [(int)$source['id']]
            );

            Database::execute(
                'INSERT INTO source_checks(source_id,status,http_status,checked_at) VALUES(?,?,?,CURRENT_TIMESTAMP)',
                [(int)$source['id'], 'unchanged', 304]
            );

            return;
        }

        if ($status === 403) {
            throw new \RuntimeException(
                'BLOCKED_403: المصدر الرسمي يرفض الوصول الآلي. HTTP 403'
            );
        }

        if ($status >= 400 || $status === 0) {
            throw new \RuntimeException(
                'HTTP_ERROR: تعذر جلب المصدر. HTTP ' . $status
            );
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

    /**
     * KHATAUAT_CURL_FETCH_V1
     * Fetch official sources using cURL with strict TLS verification.
     */
    private function fetchUrl(string $url, array $source): array
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException(
                'CURL_UNAVAILABLE: امتداد PHP cURL غير متوفر.'
            );
        }

        $headers = [
            'Accept: text/html,application/xhtml+xml,application/json;q=0.9,text/plain;q=0.8,*/*;q=0.5',
            'Accept-Language: ar-SA,ar;q=0.9,en;q=0.6',
            'Cache-Control: no-cache',
        ];

        if (!empty($source['etag'])) {
            $headers[]='If-None-Match: '.$source['etag'];
        }

        if (!empty($source['last_modified'])) {
            $headers[]='If-Modified-Since: '.$source['last_modified'];
        }

        $responseHeaders=[];

        $ch=curl_init($url);

        if ($ch===false) {
            throw new \RuntimeException(
                'CURL_INIT_FAILED: تعذر تهيئة اتصال المراقبة.'
            );
        }

        curl_setopt_array($ch,[
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 4,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_TIMEOUT => max(
                8,
                (int)\config('monitor_timeout',12)
            ),
            CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERAGENT =>
                'Khatauat-Monitor/2.0 (+https://khatauat.com/)',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,

            CURLOPT_HEADERFUNCTION =>
                static function ($ch,string $line) use (&$responseHeaders): int {
                    $len=strlen($line);
                    $trim=trim($line);

                    if ($trim==='') {
                        return $len;
                    }

                    if (preg_match('#^HTTP/\S+\s+\d+#i',$trim)) {
                        $responseHeaders=[];
                    } else {
                        $responseHeaders[]=$trim;
                    }

                    return $len;
                },
        ]);

        $body=curl_exec($ch);

        $errno=curl_errno($ch);
        $error=trim(curl_error($ch));

        $status=(int)curl_getinfo(
            $ch,
            CURLINFO_RESPONSE_CODE
        );

        curl_close($ch);

        if ($errno!==0) {

            $class=match($errno) {
                60 => // CURLE_PEER_FAILED_VERIFICATION
                    'TLS_CERTIFICATE',
                28 => // CURLE_OPERATION_TIMEDOUT
                    'TIMEOUT',
                6 => // CURLE_COULDNT_RESOLVE_HOST
                    'DNS_ERROR',
                7 => // CURLE_COULDNT_CONNECT
                    'CONNECT_ERROR',
                default =>
                    'CURL_ERROR_'.$errno,
            };

            throw new \RuntimeException(
                $class.': '.
                ($error!=='' ? $error : 'فشل اتصال cURL')
            );
        }

        $etag=null;
        $lastModified=null;

        foreach ($responseHeaders as $header) {

            if (stripos($header,'ETag:')===0) {
                $etag=trim(substr($header,5));
            }

            if (stripos($header,'Last-Modified:')===0) {
                $lastModified=trim(substr($header,14));
            }
        }

        return [
            $body===false ? '' : (string)$body,
            $status,
            $etag,
            $lastModified,
        ];
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
