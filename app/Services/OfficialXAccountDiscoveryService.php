<?php

declare(strict_types=1);

namespace Khatauat\Services;

use Khatauat\Core\Database;
use Khatauat\Core\Settings;

/**
 * Discovers official X/Twitter accounts only from links published on already
 * approved official source/support pages.
 *
 * No web search is required for discovery: this uses ordinary HTTPS GETs to
 * the official pages already stored in Khatauat, reducing Exa/API cost.
 */
final class OfficialXAccountDiscoveryService
{
    public function discoverBatch(?int $limit = null): array
    {
        $limit ??= (int)Settings::get('x_handle_discovery_daily_limit', '20');
        $limit = max(1, min(74, $limit));

        $targets = Database::fetchAll(
            "SELECT w.id target_id,w.source_id,w.entity_name,w.handle_status,w.official_handle,
                    s.name source_name,s.domain,s.url source_url,
                    c.support_url,c.branches_url,c.source_url contact_source_url
             FROM social_watch_targets w
             JOIN source_registry s ON s.id=w.source_id
             LEFT JOIN official_source_support m ON m.source_id=s.id
             LEFT JOIN official_entity_contacts c ON c.id=m.contact_id
             WHERE w.active=1 AND w.network='x'
               AND (w.handle_status<>'verified' OR COALESCE(w.official_handle,'')='')
             ORDER BY CASE WHEN w.handle_status='needs_review' THEN 0 ELSE 1 END,w.priority DESC,w.id
             LIMIT " . (int)$limit
        );

        $stats = ['ok'=>true,'checked'=>0,'verified_targets'=>0,'accounts_added'=>0,'no_match'=>0,'errors'=>0];
        foreach ($targets as $target) {
            $stats['checked']++;
            try {
                $r = $this->discoverForTarget($target);
                $stats['accounts_added'] += (int)($r['accounts_added'] ?? 0);
                if (($r['verified'] ?? false) === true) $stats['verified_targets']++;
                else $stats['no_match']++;
            } catch (\Throwable $e) {
                $stats['errors']++;
                $this->logAttempt((int)$target['target_id'], '', '', 'error', $e->getMessage());
            }
        }
        return $stats;
    }

    public function discoverTarget(int $targetId): array
    {
        $target = Database::fetch(
            "SELECT w.id target_id,w.source_id,w.entity_name,w.handle_status,w.official_handle,
                    s.name source_name,s.domain,s.url source_url,
                    c.support_url,c.branches_url,c.source_url contact_source_url
             FROM social_watch_targets w
             JOIN source_registry s ON s.id=w.source_id
             LEFT JOIN official_source_support m ON m.source_id=s.id
             LEFT JOIN official_entity_contacts c ON c.id=m.contact_id
             WHERE w.id=? AND w.active=1 AND w.network='x' LIMIT 1",
            [$targetId]
        );
        if (!$target) return ['ok'=>false,'error'=>'هدف X غير موجود.'];
        return $this->discoverForTarget($target);
    }

    private function discoverForTarget(array $target): array
    {
        $urls = array_values(array_unique(array_filter([
            trim((string)($target['support_url'] ?? '')),
            trim((string)($target['contact_source_url'] ?? '')),
            trim((string)($target['source_url'] ?? '')),
            trim((string)($target['branches_url'] ?? '')),
        ], static fn($v) => $v !== '')));

        $accounts = [];
        foreach (array_slice($urls, 0, 4) as $url) {
            if (!$this->isAllowedOfficialUrl($url, (string)$target['domain'])) continue;
            $page = $this->fetchOfficialHtml($url, (string)$target['domain']);
            if (!($page['ok'] ?? false)) {
                $this->logAttempt((int)$target['target_id'], '', $url, 'fetch_failed', (string)($page['error'] ?? 'fetch failed'));
                continue;
            }
            foreach ($this->extractAccounts((string)$page['html'], (string)$page['final_url']) as $a) {
                $key = strtolower($a['handle']);
                if (!isset($accounts[$key]) || $a['score'] > $accounts[$key]['score']) $accounts[$key] = $a;
            }
            if ($accounts !== []) break; // one official page with direct X links is sufficient evidence
        }

        if ($accounts === []) {
            $this->logAttempt((int)$target['target_id'], '', $urls[0] ?? '', 'no_match', 'No direct X/Twitter account link found on checked official pages.');
            return ['ok'=>true,'verified'=>false,'accounts_added'=>0];
        }

        uasort($accounts, static fn($a,$b) => ($b['score'] <=> $a['score']) ?: strcmp($a['handle'],$b['handle']));
        $added = 0;
        $primary = null;
        foreach ($accounts as $account) {
            Database::execute(
                "INSERT INTO official_x_accounts(target_id,source_id,handle,x_url,account_role,verification_status,verified_from_url,verification_method,verified_at,active,created_at,updated_at)
                 VALUES(?,?,?,?,?,'verified',?,'official_html_link',CURRENT_TIMESTAMP,1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)
                 ON CONFLICT(target_id,handle) DO UPDATE SET x_url=excluded.x_url,account_role=excluded.account_role,verification_status='verified',verified_from_url=excluded.verified_from_url,verification_method='official_html_link',verified_at=CURRENT_TIMESTAMP,active=1,updated_at=CURRENT_TIMESTAMP",
                [(int)$target['target_id'],(int)$target['source_id'],$account['handle'],$account['x_url'],$account['role'],$account['source_page']]
            );
            $added++;
            $this->logAttempt((int)$target['target_id'], $account['handle'], $account['source_page'], 'verified', $account['role']);
            if ($primary === null) $primary = $account;
            if ($account['role'] === 'support') $primary = $account;
        }

        if ($primary !== null) {
            Database::execute(
                "UPDATE social_watch_targets
                 SET official_handle=?,official_x_url=?,handle_status='verified',handle_source_url=?,updated_at=CURRENT_TIMESTAMP
                 WHERE id=?",
                [$primary['handle'],$primary['x_url'],$primary['source_page'],(int)$target['target_id']]
            );
        }

        return ['ok'=>true,'verified'=>true,'accounts_added'=>$added,'primary_handle'=>$primary['handle'] ?? null];
    }

    private function extractAccounts(string $html, string $sourcePage): array
    {
        if ($html === '') return [];
        $out = [];
        if (class_exists(\DOMDocument::class)) {
            $doc = new \DOMDocument();
            $previous = libxml_use_internal_errors(true);
            try {
                $doc->loadHTML($html, LIBXML_NOWARNING|LIBXML_NOERROR|LIBXML_NONET);
                foreach ($doc->getElementsByTagName('a') as $a) {
                    $href = html_entity_decode(trim((string)$a->getAttribute('href')), ENT_QUOTES|ENT_HTML5, 'UTF-8');
                    if ($href === '') continue;
                    if (str_starts_with($href, '//')) $href = 'https:'.$href;
                    if (!preg_match('~^https?://(?:www\.)?(?:x\.com|twitter\.com)/([^/?#]+)~i', $href, $m)) continue;
                    $handle = trim($m[1]);
                    if (!$this->validHandle($handle)) continue;
                    $label = trim((string)$a->textContent.' '.(string)$a->getAttribute('aria-label').' '.(string)$a->getAttribute('title'));
                    $role = $this->accountRole($handle, $label);
                    $score = $role === 'support' ? 100 : 60;
                    $out[] = ['handle'=>$handle,'x_url'=>'https://x.com/'.$handle,'role'=>$role,'score'=>$score,'source_page'=>$sourcePage];
                }
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($previous);
            }
        } else {
            preg_match_all('~https?://(?:www\.)?(?:x\.com|twitter\.com)/([A-Za-z0-9_]{1,30})~i', html_entity_decode($html, ENT_QUOTES|ENT_HTML5, 'UTF-8'), $matches);
            foreach(array_unique($matches[1] ?? []) as $handle){
                if(!$this->validHandle((string)$handle))continue;
                $role=$this->accountRole((string)$handle,'');
                $out[]=['handle'=>(string)$handle,'x_url'=>'https://x.com/'.$handle,'role'=>$role,'score'=>$role==='support'?90:50,'source_page'=>$sourcePage];
            }
        }
        return $out;
    }

    private function accountRole(string $handle, string $label): string
    {
        $q = mb_strtolower($handle.' '.$label);
        foreach (['care','support','help','customer','ecare','_cs','cs_','عناية','العنايه','دعم','خدمة العملاء','خدمه العملاء'] as $term) {
            if (mb_stripos($q, $term) !== false) return 'support';
        }
        return 'general';
    }

    private function validHandle(string $handle): bool
    {
        if (!preg_match('/^[A-Za-z0-9_]{1,30}$/', $handle)) return false;
        return !in_array(strtolower($handle), ['home','search','share','intent','i','hashtag','compose','messages','settings'], true);
    }

    private function fetchOfficialHtml(string $url, string $sourceDomain): array
    {
        if (!function_exists('curl_init')) return ['ok'=>false,'error'=>'cURL غير متاح.'];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_FOLLOWLOCATION=>true,
            CURLOPT_MAXREDIRS=>3,
            CURLOPT_CONNECTTIMEOUT=>8,
            CURLOPT_TIMEOUT=>20,
            CURLOPT_USERAGENT=>'KhatauatOfficialSourceMonitor/2.0 (+https://khatauat.com)',
            CURLOPT_SSL_VERIFYPEER=>true,
            CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_HTTPHEADER=>['Accept: text/html,application/xhtml+xml'],
            CURLOPT_RANGE=>'0-900000',
        ]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $final = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $type = strtolower((string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE));
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false || $code < 200 || $code >= 400) return ['ok'=>false,'error'=>$err !== '' ? $err : 'HTTP '.$code];
        if (!$this->isAllowedOfficialUrl($final, $sourceDomain)) return ['ok'=>false,'error'=>'Redirect left the approved official domain.'];
        if ($type !== '' && !str_contains($type, 'text/html') && !str_contains($type, 'application/xhtml')) return ['ok'=>false,'error'=>'Official page is not HTML.'];
        return ['ok'=>true,'html'=>(string)$raw,'final_url'=>$final];
    }

    private function isAllowedOfficialUrl(string $url, string $sourceDomain): bool
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) return false;
        $p = parse_url($url);
        if (strtolower((string)($p['scheme'] ?? '')) !== 'https') return false;
        $host = strtolower((string)($p['host'] ?? ''));
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP)) return false;
        $sourceDomain = strtolower(preg_replace('/^www\./','',trim($sourceDomain)) ?? trim($sourceDomain));
        $hostNoWww = preg_replace('/^www\./','',$host) ?? $host;
        if ($sourceDomain !== '' && ($hostNoWww === $sourceDomain || str_ends_with($hostNoWww, '.'.$sourceDomain))) return true;
        if (str_ends_with($hostNoWww, '.gov.sa') || str_ends_with($hostNoWww, '.edu.sa')) return true;
        // Non-gov.sa execution platforms are allowed only if their domain is
        // already present in the approved active source registry.
        try {
            $rows=Database::fetchAll("SELECT domain FROM source_registry WHERE status='active' AND COALESCE(domain,'')<>''");
            foreach($rows as $r){
                $d=strtolower(preg_replace('/^www\./','',trim((string)($r['domain']??''))) ?? trim((string)($r['domain']??'')));
                if($d!=='' && ($hostNoWww===$d || str_ends_with($hostNoWww,'.'.$d))) return true;
            }
        } catch (\Throwable) {}
        return false;
    }

    private function logAttempt(int $targetId, string $handle, string $url, string $status, string $note): void
    {
        try {
            Database::execute(
                "INSERT INTO x_handle_discovery_log(target_id,discovered_handle,official_page_url,status,note,created_at)
                 VALUES(?,?,?,?,?,CURRENT_TIMESTAMP)",
                [$targetId,$handle,$url,$status,mb_substr($note,0,500)]
            );
        } catch (\Throwable) {}
    }
}
