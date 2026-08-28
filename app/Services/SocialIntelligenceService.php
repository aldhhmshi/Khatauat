<?php

declare(strict_types=1);

namespace Khatauat\Services;

use Khatauat\Core\Database;
use Khatauat\Core\Settings;

/**
 * Best-effort public X intelligence using Exa indexing.
 *
 * Safety model:
 * - User posts are signals, never authoritative facts.
 * - Only X handles verified from an official source can create an official incident.
 * - A cyberattack/security incident is never inferred from user posts.
 * - No social signal is auto-published as permanent procedural knowledge.
 * - Current incidents expire automatically unless refreshed.
 */
final class SocialIntelligenceService
{
    private const EXA_ENDPOINT = 'https://api.exa.ai/search';

    public function isConfigured(): bool
    {
        return trim((string)(getenv('EXA_API_KEY') ?: '')) !== '';
    }

    public function dailyScan(): array
    {
        if (!$this->isConfigured()) {
            return ['ok'=>false,'error'=>'EXA_API_KEY غير مضبوط.'];
        }

        $targets = Database::fetchAll(
            "SELECT w.*, s.name source_name, s.domain source_domain, s.sector source_sector, s.source_role
             FROM social_watch_targets w
             LEFT JOIN source_registry s ON s.id=w.source_id
             WHERE w.active=1
             ORDER BY w.priority DESC, w.id"
        );
        if ($targets === []) return ['ok'=>true,'targets'=>0,'batches'=>0,'results'=>0,'signals'=>0,'incidents'=>0];

        $batchSize = max(3, min(8, (int)Settings::get('social_daily_batch_size', '6')));
        $maxBatches = max(1, min(30, (int)Settings::get('social_daily_max_batches', '20')));
        $chunks = array_slice(array_chunk($targets, $batchSize), 0, $maxBatches);

        $stats = ['ok'=>true,'targets'=>count($targets),'batches'=>0,'results'=>0,'signals'=>0,'errors'=>0,'incidents'=>0];
        foreach ($chunks as $chunk) {
            $stats['batches']++;
            $r = $this->scanBatch($chunk);
            if (!($r['ok'] ?? false)) {
                $stats['errors']++;
                continue;
            }
            $stats['results'] += (int)($r['results'] ?? 0);
            $stats['signals'] += (int)($r['signals'] ?? 0);
        }

        $this->expireOldSignalsAndIncidents();
        $stats['incidents'] = $this->rebuildIncidents();
        return $stats;
    }

    public function scanTarget(int $targetId, bool $force = false): array
    {
        $target = Database::fetch(
            "SELECT w.*, s.name source_name, s.domain source_domain, s.sector source_sector, s.source_role
             FROM social_watch_targets w
             LEFT JOIN source_registry s ON s.id=w.source_id
             WHERE w.id=? AND w.active=1 LIMIT 1",
            [$targetId]
        );
        if (!$target) return ['ok'=>false,'error'=>'هدف المراقبة غير موجود.'];
        if (!$this->isConfigured()) return ['ok'=>false,'error'=>'EXA_API_KEY غير مضبوط.'];

        $cacheMinutes = max(15, min(360, (int)Settings::get('social_live_check_cache_minutes', '60')));
        if (!$force && !empty($target['last_scanned_at'])) {
            $last = strtotime((string)$target['last_scanned_at']);
            if ($last !== false && $last >= time() - ($cacheMinutes * 60)) {
                return ['ok'=>true,'cached'=>true,'target_id'=>$targetId,'results'=>0,'signals'=>0];
            }
        }

        $entity = trim((string)($target['entity_name'] ?? $target['source_name'] ?? ''));
        $domain = trim((string)($target['source_domain'] ?? ''));
        $handles = $this->verifiedHandlesForTarget($targetId);
        $handleNote = $handles !== []
            ? ' Verified official/support X accounts on record: '.implode(', ', array_map(static fn($h) => '@'.$h, $handles)).'. Prioritize posts and replies from those handles and user reports addressed to them.'
            : '';
        $query = 'Recent public posts and customer-service replies on X in Saudi Arabia during the last 48 hours about '.$entity.
            ($domain !== '' ? ' (official domain '.$domain.')' : '').'. Focus on technical errors, service outages, maintenance, updates, login/authentication, data synchronization, payment problems, and official support answers.'.$handleNote;

        $scanId = $this->startScan($targetId, $query, 'targeted');
        $result = $this->exaSearch($query, 12);
        if (!($result['ok'] ?? false)) {
            $this->finishScan($scanId, 'failed', 0, (string)($result['error'] ?? 'Exa failed'));
            return $result;
        }

        $stored = 0;
        foreach ((array)($result['results'] ?? []) as $row) {
            if ($this->storeSignal($target, $row)) $stored++;
        }
        Database::execute('UPDATE social_watch_targets SET last_scanned_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=?', [$targetId]);
        $this->finishScan($scanId, 'ok', count((array)($result['results'] ?? [])), null);
        $this->expireOldSignalsAndIncidents();
        $this->rebuildIncidentForTarget($targetId);

        return ['ok'=>true,'cached'=>false,'target_id'=>$targetId,'results'=>count((array)($result['results'] ?? [])),'signals'=>$stored];
    }

    /**
     * Find a relevant target for a technical question, refresh only that target
     * when its shared cache is stale, and return the latest incident.
     */
    public function incidentForQuestion(string $question, array $services = [], bool $allowRefresh = true): ?array
    {
        if ((string)Settings::get('social_intelligence_enabled', '1') !== '1') return null;
        $target = $this->resolveTarget($question, $services);
        if (!$target) return null;

        if ($allowRefresh && (string)Settings::get('social_live_check_enabled', '1') === '1') {
            try { $this->scanTarget((int)$target['id'], false); } catch (\Throwable) {}
        }

        $incident = Database::fetch(
            "SELECT i.*, w.entity_name, w.official_handle, w.official_x_url
             FROM live_service_incidents i
             JOIN social_watch_targets w ON w.id=i.target_id
             WHERE i.target_id=? AND i.status IN ('confirmed','suspected')
               AND datetime(i.expires_at) > datetime('now')
             ORDER BY CASE i.status WHEN 'confirmed' THEN 0 ELSE 1 END, i.last_seen_at DESC, i.id DESC
             LIMIT 1",
            [(int)$target['id']]
        );
        if (!$incident) return ['target'=>$target,'incident'=>null];

        return ['target'=>$target,'incident'=>$incident];
    }


    /**
     * Returns current incident + recent official support solutions for a user's
     * technical question. Official solutions are temporary evidence and expire.
     */
    public function contextForQuestion(string $question, array $services = [], bool $allowRefresh = true): ?array
    {
        if ((string)Settings::get('social_intelligence_enabled', '1') !== '1') return null;
        $target = $this->resolveTarget($question, $services);
        if (!$target) return null;

        if ($allowRefresh && (string)Settings::get('social_live_check_enabled', '1') === '1') {
            try { $this->scanTarget((int)$target['id'], false); } catch (\Throwable) {}
        }

        $incident = Database::fetch(
            "SELECT i.*,w.entity_name,w.official_handle,w.official_x_url
             FROM live_service_incidents i
             JOIN social_watch_targets w ON w.id=i.target_id
             WHERE i.target_id=? AND i.status IN ('confirmed','suspected')
               AND datetime(i.expires_at)>datetime('now')
             ORDER BY CASE i.status WHEN 'confirmed' THEN 0 ELSE 1 END,i.last_seen_at DESC,i.id DESC
             LIMIT 1",
            [(int)$target['id']]
        );

        $issue = $this->classifyIssue($question, false);
        if ($issue === 'security_claim_unverified' || $issue === 'none') $issue = '';

        $sql = "SELECT id,issue_type,problem_excerpt,solution_text,evidence_url,official_handle,
                       first_seen_at,last_verified_at,valid_until,confidence
                FROM social_solution_knowledge
                WHERE target_id=? AND status='usable' AND datetime(valid_until)>datetime('now')";
        $params = [(int)$target['id']];
        if ($issue !== '') {
            $sql .= " AND issue_type=?";
            $params[] = $issue;
        }
        $sql .= " ORDER BY confidence DESC,datetime(last_verified_at) DESC,id DESC LIMIT 3";
        try { $solutions = Database::fetchAll($sql, $params); }
        catch (\Throwable) { $solutions = []; }

        return ['target'=>$target,'incident'=>$incident ?: null,'solutions'=>$solutions,'issue_type'=>$issue];
    }

    /**
     * Cached service pulse for public service pages.
     * This method never calls Exa and never refreshes a target. It reads only
     * the most recent scheduled/targeted intelligence already stored locally.
     */
    public function pulseForService(array $service): array
    {
        if ((string)Settings::get('service_pulse_enabled', '1') !== '1'
            || (string)Settings::get('social_intelligence_enabled', '1') !== '1') {
            return ['status'=>'disabled','label'=>'نبض الخدمة غير مفعل','solutions'=>[],'incident'=>null];
        }

        $question = trim(implode(' ', array_filter([
            (string)($service['name'] ?? ''),
            (string)($service['official_entity'] ?? ''),
            (string)($service['official_platform'] ?? ''),
            (string)($service['official_url'] ?? ''),
        ])));
        $target = $this->resolveTarget($question, [$service]);
        if (!$target) {
            return [
                'status'=>'unknown',
                'label'=>'لا توجد مراقبة مرتبطة بهذه الخدمة حتى الآن',
                'summary'=>'يمكنك متابعة الإجراء والمصادر الرسمية بصورة طبيعية، لكن لا نعرض حالة تقنية غير متحقق منها.',
                'solutions'=>[],
                'incident'=>null,
                'last_scanned_at'=>null,
                'verified_x_accounts'=>0,
            ];
        }

        $incident = Database::fetch(
            "SELECT i.*,w.entity_name,w.official_handle,w.official_x_url
             FROM live_service_incidents i
             JOIN social_watch_targets w ON w.id=i.target_id
             WHERE i.target_id=? AND i.status IN ('confirmed','suspected')
               AND datetime(i.expires_at)>datetime('now')
             ORDER BY CASE i.status WHEN 'confirmed' THEN 0 ELSE 1 END,datetime(i.last_seen_at) DESC,i.id DESC
             LIMIT 1",
            [(int)$target['id']]
        );

        try {
            $solutions = Database::fetchAll(
                "SELECT id,issue_type,problem_excerpt,solution_text,evidence_url,official_handle,last_verified_at,valid_until,confidence
                 FROM social_solution_knowledge
                 WHERE target_id=? AND status='usable' AND datetime(valid_until)>datetime('now')
                 ORDER BY confidence DESC,datetime(last_verified_at) DESC,id DESC LIMIT 2",
                [(int)$target['id']]
            );
        } catch (\Throwable) {
            $solutions = [];
        }

        try {
            $verifiedAccounts=(int)(Database::fetch(
                "SELECT COUNT(*) c FROM official_x_accounts WHERE target_id=? AND active=1 AND verification_status='verified'",
                [(int)$target['id']]
            )['c'] ?? 0);
        } catch (\Throwable) {
            $verifiedAccounts = ((string)($target['handle_status'] ?? '') === 'verified' && trim((string)($target['official_handle'] ?? '')) !== '') ? 1 : 0;
        }

        $last = trim((string)($target['last_scanned_at'] ?? ''));
        if ($incident) {
            $confirmed=(string)$incident['status']==='confirmed';
            return [
                'status'=>$confirmed?'confirmed':'suspected',
                'label'=>$confirmed?'تنبيه تشغيلي موثق':'بلاغات متقاربة — غير مؤكدة رسميًا',
                'summary'=>(string)($incident['summary'] ?? ''),
                'incident'=>$incident,
                'solutions'=>$solutions,
                'last_scanned_at'=>$last!==''?$last:null,
                'verified_x_accounts'=>$verifiedAccounts,
                'target_id'=>(int)$target['id'],
                'entity_name'=>(string)($target['entity_name'] ?? ''),
            ];
        }

        if ($solutions !== []) {
            return [
                'status'=>'guidance',
                'label'=>'يوجد توجيه دعم رسمي حديث',
                'summary'=>'لا يوجد تنبيه نشط، لكن لدينا رد أو توجيه حديث من حساب X رسمي موثق قد يفيد عند مواجهة مشكلة مشابهة.',
                'incident'=>null,
                'solutions'=>$solutions,
                'last_scanned_at'=>$last!==''?$last:null,
                'verified_x_accounts'=>$verifiedAccounts,
                'target_id'=>(int)$target['id'],
                'entity_name'=>(string)($target['entity_name'] ?? ''),
            ];
        }

        if ($last !== '') {
            return [
                'status'=>'clear',
                'label'=>'لا توجد مؤشرات تقنية نشطة في آخر فحص',
                'summary'=>'لم يرصد نظام «خطوات» تنبيهًا رسميًا أو مجموعة بلاغات كافية لإنشاء تنبيه نشط. هذا لا يُعد ضمانًا لتوفر الخدمة لحظيًا.',
                'incident'=>null,
                'solutions'=>[],
                'last_scanned_at'=>$last,
                'verified_x_accounts'=>$verifiedAccounts,
                'target_id'=>(int)$target['id'],
                'entity_name'=>(string)($target['entity_name'] ?? ''),
            ];
        }

        return [
            'status'=>'pending',
            'label'=>'بانتظار أول فحص تشغيلي',
            'summary'=>'المصدر مربوط بالمراقبة، لكن لم يكتمل فحص تشغيلي حديث بعد.',
            'incident'=>null,
            'solutions'=>[],
            'last_scanned_at'=>null,
            'verified_x_accounts'=>$verifiedAccounts,
            'target_id'=>(int)$target['id'],
            'entity_name'=>(string)($target['entity_name'] ?? ''),
        ];
    }

    public function statusSummary(): array
    {
        $targets=(int)(Database::fetch('SELECT COUNT(*) c FROM social_watch_targets WHERE active=1')['c'] ?? 0);
        try {
            $verifiedAccounts=(int)(Database::fetch("SELECT COUNT(*) c FROM official_x_accounts WHERE active=1 AND verification_status='verified'")['c'] ?? 0);
            $verifiedTargets=(int)(Database::fetch("SELECT COUNT(DISTINCT target_id) c FROM official_x_accounts WHERE active=1 AND verification_status='verified'")['c'] ?? 0);
            $solutions=(int)(Database::fetch("SELECT COUNT(*) c FROM social_solution_knowledge WHERE status='usable' AND datetime(valid_until)>datetime('now')")['c'] ?? 0);
        } catch (\Throwable) {
            $verifiedAccounts=(int)(Database::fetch("SELECT COUNT(*) c FROM social_watch_targets WHERE active=1 AND handle_status='verified' AND official_handle<>''")['c'] ?? 0);
            $verifiedTargets=$verifiedAccounts;
            $solutions=0;
        }
        $signals=(int)(Database::fetch("SELECT COUNT(*) c FROM social_signals WHERE datetime(observed_at)>=datetime('now','-72 hours')")['c'] ?? 0);
        $confirmed=(int)(Database::fetch("SELECT COUNT(*) c FROM live_service_incidents WHERE status='confirmed' AND datetime(expires_at)>datetime('now')")['c'] ?? 0);
        $suspected=(int)(Database::fetch("SELECT COUNT(*) c FROM live_service_incidents WHERE status='suspected' AND datetime(expires_at)>datetime('now')")['c'] ?? 0);
        return [
            'targets'=>$targets,
            'verified_x_accounts'=>$verifiedAccounts,
            'targets_with_verified_x'=>$verifiedTargets,
            'signals_72h'=>$signals,
            'temporary_official_solutions'=>$solutions,
            'confirmed_incidents'=>$confirmed,
            'suspected_incidents'=>$suspected
        ];
    }

    private function scanBatch(array $targets): array
    {
        $names=[];
        foreach ($targets as $t) {
            $name=trim((string)($t['entity_name'] ?? $t['source_name'] ?? ''));
            if ($name==='') continue;
            $handles=$this->verifiedHandlesForTarget((int)$t['id']);
            $names[]=$name.($handles!==[]?' ('.implode(', ',array_map(static fn($h)=>'@'.$h,$handles)).')':'');
        }
        if ($names===[]) return ['ok'=>true,'results'=>0,'signals'=>0];

        $query='Recent public posts, questions, and customer-service replies on X in Saudi Arabia during the last 48 hours for these platforms/entities: '.implode(' | ',$names).'. Focus only on technical errors, outages, maintenance, system updates, login problems, data synchronization, payment issues, and support answers. Prefer recent posts and official support replies.';
        $scanIds=[];
        foreach ($targets as $t) $scanIds[(int)$t['id']]=$this->startScan((int)$t['id'],$query,'daily_batch');

        $result=$this->exaSearch($query,15);
        if (!($result['ok'] ?? false)) {
            foreach($scanIds as $id) $this->finishScan($id,'failed',0,(string)($result['error'] ?? 'Exa failed'));
            return $result;
        }

        $stored=0;
        $matchedCounts=[];
        foreach ((array)($result['results'] ?? []) as $row) {
            $target=$this->matchTargetToResult($targets,$row);
            if(!$target) continue;
            $tid=(int)$target['id'];
            $matchedCounts[$tid]=($matchedCounts[$tid]??0)+1;
            if($this->storeSignal($target,$row)) $stored++;
        }

        foreach($targets as $t){
            $tid=(int)$t['id'];
            Database::execute('UPDATE social_watch_targets SET last_scanned_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=?',[$tid]);
            $this->finishScan($scanIds[$tid]??0,'ok',(int)($matchedCounts[$tid]??0),null);
        }
        return ['ok'=>true,'results'=>count((array)($result['results'] ?? [])),'signals'=>$stored];
    }

    private function exaSearch(string $query, int $limit): array
    {
        $key=trim((string)(getenv('EXA_API_KEY') ?: ''));
        if($key==='') return ['ok'=>false,'error'=>'EXA_API_KEY غير مضبوط.'];
        if(!function_exists('curl_init')) return ['ok'=>false,'error'=>'cURL غير متاح.'];
        $payload=[
            'query'=>$query,
            'type'=>'fast',
            'numResults'=>max(3,min(20,$limit)),
            'includeDomains'=>['x.com','twitter.com'],
            'contents'=>['highlights'=>true],
        ];
        $ch=curl_init(self::EXA_ENDPOINT);
        curl_setopt_array($ch,[
            CURLOPT_POST=>true,
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_CONNECTTIMEOUT=>12,
            CURLOPT_TIMEOUT=>55,
            CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_HTTPHEADER=>['Content-Type: application/json','x-api-key: '.$key],
            CURLOPT_POSTFIELDS=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
        ]);
        $raw=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$err=curl_error($ch);curl_close($ch);
        if($raw===false) return ['ok'=>false,'error'=>$err?:'تعذر الاتصال بـ Exa.'];
        $json=json_decode((string)$raw,true);
        if($code<200||$code>=300){
            $msg=is_array($json)?(string)($json['error']??$json['message']??''):'';
            return ['ok'=>false,'error'=>$msg!==''?$msg:('Exa HTTP '.$code)];
        }
        $rows=[];
        foreach((array)($json['results']??[]) as $r){
            if(!is_array($r))continue;
            $url=trim((string)($r['url']??$r['id']??''));
            if(!$this->isXUrl($url))continue;
            $high=$r['highlights']??[];
            if(is_array($high)) $high=implode("\n",array_map('strval',$high));
            $rows[]=[
                'title'=>trim((string)($r['title']??'')),
                'url'=>$url,
                'author'=>trim((string)($r['author']??'')),
                'publishedDate'=>trim((string)($r['publishedDate']??'')),
                'text'=>trim((string)($r['text']??$r['summary']??'')),
                'highlights'=>trim((string)$high),
            ];
        }
        return ['ok'=>true,'results'=>$rows];
    }

    private function storeSignal(array $target, array $row): bool
    {
        $url=trim((string)($row['url']??''));
        if(!$this->isXUrl($url)) return false;
        $handle=$this->extractHandle($url);
        $verifiedHandles=$this->verifiedHandlesForTarget((int)$target['id']);
        $isOfficial=$handle!=='' && in_array(strtolower($handle), array_map('strtolower',$verifiedHandles), true);

        $text=trim(implode("\n",array_filter([
            (string)($row['title']??''),(string)($row['text']??''),(string)($row['highlights']??'')
        ])));
        if($text==='') $text=(string)($row['title']??'');
        $text=mb_substr(preg_replace('/\s+/u',' ',$text)??$text,0,1800);
        $issue=$this->classifyIssue($text,$isOfficial);
        if($issue==='none') return false;

        $published=null;
        $dateRaw=trim((string)($row['publishedDate']??''));
        if($dateRaw!==''){
            $ts=strtotime($dateRaw);
            if($ts!==false) $published=gmdate('Y-m-d H:i:s',$ts);
        }
        $maxAge=max(24,min(168,(int)Settings::get('social_signal_max_age_hours','72')));
        if($published!==null && strtotime($published) < time()-($maxAge*3600)) return false;

        $evidenceType=$isOfficial?'official_content':'user_report';
        $confidence=$isOfficial?95:($published!==null?48:20);
        $solution=$isOfficial?$this->extractSolution($text):'';
        $hash=hash('sha256',mb_strtolower($url.'|'.$text));
        $expiresHours=$isOfficial?max(12,min(168,(int)Settings::get('social_official_signal_ttl_hours','72'))):max(6,min(72,(int)Settings::get('social_user_signal_ttl_hours','36')));
        $expires=gmdate('Y-m-d H:i:s',time()+$expiresHours*3600);

        Database::execute(
            "INSERT INTO social_signals(target_id,source_id,network,post_url,author_handle,title,excerpt,published_at,observed_at,evidence_type,issue_type,solution_text,confidence,official_confirmed,review_status,expires_at,content_hash)
             VALUES(?,?,'x',?,?,?,?,?,CURRENT_TIMESTAMP,?,?,?,?,?,'candidate',?,?)
             ON CONFLICT(post_url) DO UPDATE SET target_id=excluded.target_id,source_id=excluded.source_id,author_handle=excluded.author_handle,title=excluded.title,excerpt=excluded.excerpt,published_at=COALESCE(excluded.published_at,social_signals.published_at),observed_at=CURRENT_TIMESTAMP,evidence_type=excluded.evidence_type,issue_type=excluded.issue_type,solution_text=excluded.solution_text,confidence=excluded.confidence,official_confirmed=excluded.official_confirmed,expires_at=excluded.expires_at,content_hash=excluded.content_hash",
            [
                (int)$target['id'],isset($target['source_id'])?(int)$target['source_id']:null,$url,$handle,
                mb_substr((string)($row['title']??''),0,500),mb_substr($text,0,1200),$published,$evidenceType,$issue,$solution,$confidence,$isOfficial?1:0,$expires,$hash
            ]
        );
        if ($isOfficial && $solution !== '') {
            $this->storeTemporaryOfficialSolution($target, $issue, $text, $solution, $url, $handle, $published, $confidence);
        }
        return true;
    }

    private function rebuildIncidents(): int
    {
        $targets=Database::fetchAll('SELECT id FROM social_watch_targets WHERE active=1');
        $count=0;
        foreach($targets as $t) if($this->rebuildIncidentForTarget((int)$t['id'])) $count++;
        return $count;
    }

    private function rebuildIncidentForTarget(int $targetId): bool
    {
        $hours=max(24,min(168,(int)Settings::get('social_signal_max_age_hours','72')));
        $signals=Database::fetchAll(
            "SELECT * FROM social_signals WHERE target_id=? AND review_status<>'dismissed'
             AND published_at IS NOT NULL AND datetime(published_at)>=datetime('now',?)
             AND datetime(expires_at)>datetime('now') ORDER BY datetime(published_at) DESC,id DESC",
            [$targetId,'-'.$hours.' hours']
        );
        if($signals===[]){
            Database::execute("UPDATE live_service_incidents SET status='resolved',resolved_at=CURRENT_TIMESTAMP,last_seen_at=CURRENT_TIMESTAMP WHERE target_id=? AND status IN ('confirmed','suspected')",[$targetId]);
            return false;
        }

        $groups=[];
        foreach($signals as $s){
            $type=(string)$s['issue_type'];
            if($type==='security_claim_unverified') continue;
            $groups[$type][]=$s;
        }
        if($groups===[]) return false;

        $threshold=max(2,min(10,(int)Settings::get('social_user_report_threshold','3')));
        $best=null;
        foreach($groups as $type=>$items){
            $official=array_values(array_filter($items,static fn($s)=>(int)$s['official_confirmed']===1));
            $users=array_values(array_filter($items,static fn($s)=>(string)$s['evidence_type']==='user_report'));
            $status=$official!==[]?'confirmed':(count($users)>=$threshold?'suspected':null);
            if($status===null) continue;
            $score=($status==='confirmed'?1000:0)+count($items)*10;
            if($best===null||$score>$best['score']) $best=['type'=>$type,'items'=>$items,'official'=>$official,'users'=>$users,'status'=>$status,'score'=>$score];
        }
        if($best===null) return false;

        $target=Database::fetch('SELECT * FROM social_watch_targets WHERE id=?',[$targetId]);
        if(!$target) return false;
        $entity=(string)$target['entity_name'];
        $status=$best['status'];$type=$best['type'];
        $official=$best['official'];$users=$best['users'];
        $latest=$best['items'][0];
        $officialEvidence='';$workaround='';
        if($official!==[]){
            $officialEvidence=(string)$official[0]['post_url'];
            foreach($official as $o){if(trim((string)$o['solution_text'])!==''){$workaround=(string)$o['solution_text'];break;}}
        }
        $title=$status==='confirmed'?'تنبيه حديث موثق يتعلق بـ '.$entity:'بلاغات عامة متعددة قد تشير إلى خلل مؤقت في '.$entity;
        $summary=$status==='confirmed'
            ? 'رصدت «خطوات» محتوى حديثًا من حساب X رسمي موثق مرتبط بالجهة حول '.($this->issueLabel($type)).'.'
            : 'رصدت «خطوات» عدة بلاغات عامة متقاربة على X حول '.($this->issueLabel($type)).'، ولم نجد تأكيدًا رسميًا حتى الآن.';
        $confidence=$status==='confirmed'?95:min(78,45+count($users)*8);
        $ttl=max(6,min(72,(int)Settings::get('social_incident_ttl_hours','24')));
        $expires=gmdate('Y-m-d H:i:s',time()+$ttl*3600);
        $incidentItems=$best['items'];
        $oldest=end($incidentItems);
        $first=(string)($oldest['published_at'] ?? $latest['published_at'] ?? '');
        $last=(string)($latest['published_at'] ?? '');
        $evidenceCount=count($best['items']);

        Database::execute(
            "INSERT INTO live_service_incidents(target_id,source_id,issue_type,status,title,summary,workaround,first_seen_at,last_seen_at,expires_at,evidence_count,user_report_count,official_evidence_count,official_evidence_url,confidence,created_at,updated_at)
             VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)
             ON CONFLICT(target_id,issue_type) DO UPDATE SET status=excluded.status,title=excluded.title,summary=excluded.summary,workaround=excluded.workaround,last_seen_at=excluded.last_seen_at,expires_at=excluded.expires_at,evidence_count=excluded.evidence_count,user_report_count=excluded.user_report_count,official_evidence_count=excluded.official_evidence_count,official_evidence_url=excluded.official_evidence_url,confidence=excluded.confidence,resolved_at=NULL,updated_at=CURRENT_TIMESTAMP",
            [$targetId,$target['source_id']!==null?(int)$target['source_id']:null,$type,$status,$title,$summary,$workaround,$first,$last,$expires,$evidenceCount,count($users),count($official),$officialEvidence,$confidence]
        );
        return true;
    }

    private function resolveTarget(string $question, array $services): ?array
    {
        $targets=Database::fetchAll(
            "SELECT w.*,s.name source_name,s.domain source_domain,s.sector source_sector FROM social_watch_targets w LEFT JOIN source_registry s ON s.id=w.source_id WHERE w.active=1 ORDER BY w.priority DESC,w.id"
        );
        $serviceText='';
        foreach($services as $s){
            $serviceText.=' '.(string)($s['name']??'').' '.(string)($s['official_entity']??'').' '.(string)($s['official_url']??'');
        }
        $hay=$this->normalize($question.' '.$serviceText);
        $best=null;$bestScore=0;
        foreach($targets as $t){
            $score=0;
            $name=$this->normalize((string)($t['entity_name']??$t['source_name']??''));
            if($name!=='' && mb_stripos($hay,$name)!==false) $score+=8;
            $sourceName=$this->normalize((string)($t['source_name']??''));
            if($sourceName!==''&&mb_stripos($hay,$sourceName)!==false) $score+=7;
            $domain=strtolower(preg_replace('/^www\./','',(string)($t['source_domain']??''))??'');
            if($domain!==''&&mb_stripos($hay,$domain)!==false) $score+=8;
            $handle=strtolower(ltrim((string)($t['official_handle']??''),'@'));
            if($handle!==''&&mb_stripos($hay,$handle)!==false) $score+=9;
            foreach(array_filter(array_map('trim',explode('|',(string)($t['aliases']??'')))) as $alias){
                $a=$this->normalize($alias); if(mb_strlen($a)>=4&&mb_stripos($hay,$a)!==false) $score+=3;
            }
            if($score>$bestScore){$best=$t;$bestScore=$score;}
        }
        return $bestScore>=3?$best:null;
    }

    private function matchTargetToResult(array $targets, array $row): ?array
    {
        $url=(string)($row['url']??'');$handle=$this->extractHandle($url);
        $text=$this->normalize(implode(' ',[(string)($row['title']??''),(string)($row['author']??''),(string)($row['text']??''),(string)($row['highlights']??'')]));
        $best=null;$scoreBest=0;
        foreach($targets as $t){
            $score=0;
            $official=strtolower(ltrim((string)($t['official_handle']??''),'@'));
            if($official!==''&&$handle!==''&&strcasecmp($official,$handle)===0) $score+=20;
            $name=$this->normalize((string)($t['entity_name']??$t['source_name']??''));
            if($name!==''&&mb_stripos($text,$name)!==false) $score+=8;
            $sourceName=$this->normalize((string)($t['source_name']??''));
            if($sourceName!==''&&mb_stripos($text,$sourceName)!==false) $score+=7;
            foreach(array_filter(array_map('trim',explode('|',(string)($t['aliases']??'')))) as $alias){
                $a=$this->normalize($alias);if(mb_strlen($a)>=5&&mb_stripos($text,$a)!==false)$score+=2;
            }
            if($score>$scoreBest){$best=$t;$scoreBest=$score;}
        }
        return $scoreBest>=6?$best:null;
    }

    private function classifyIssue(string $text, bool $official): string
    {
        $q=$this->normalize($text);
        $security=['هجوم سيبراني','هجمات سيبرانيه','هجمات سيبرانية','اختراق','تسريب بيانات','cyber attack','cyberattack','ddos','data breach'];
        foreach($security as $term){
            if(mb_stripos($q,$this->normalize($term))!==false){
                return $official?'security_incident_confirmed':'security_claim_unverified';
            }
        }
        $rules=[
            'maintenance_update'=>['صيانه','صيانة','تحديث النظام','تحديثات النظام','اعمال تحديث','أعمال تحديث','maintenance','scheduled maintenance','system update'],
            'login_auth'=>['تسجيل الدخول','تعذر الدخول','لا استطيع الدخول','لا أستطيع الدخول','نفاذ','رمز التحقق','otp','login','sign in','authentication'],
            'data_sync'=>['لم تتحدث','لم يتحدث','البيانات لم','لا تظهر البيانات','لا يظهر','مزامنه','مزامنة','تحديث البيانات','sync','synchronization'],
            'payment'=>['سداد','الدفع','فاتوره','فاتورة','خصم المبلغ','payment','invoice','billing'],
            'service_unavailable'=>['تعطل','متعطل','لا يعمل','لا يفتح','الخدمه غير متاحه','الخدمة غير متاحة','خارج الخدمه','خارج الخدمة','عطل فني','مشكله تقنيه','مشكلة تقنية','خطأ تقني','error','unavailable','outage','down','service unavailable'],
        ];
        foreach($rules as $type=>$terms) foreach($terms as $term) if(mb_stripos($q,$this->normalize($term))!==false) return $type;
        return 'none';
    }

    private function extractSolution(string $text): string
    {
        $q=$this->normalize($text);
        foreach(['يرجى','نرجو','يمكنك','لحل','قم ب','من خلال','اتبع','حاول','اعد المحاوله','أعد المحاولة','please','you can','try again','use the'] as $cue){
            if(mb_stripos($q,$this->normalize($cue))!==false) return mb_substr(trim($text),0,650);
        }
        return '';
    }

    private function issueLabel(string $type): string
    {
        return match($type){
            'maintenance_update'=>'صيانة أو تحديث للنظام',
            'login_auth'=>'تسجيل الدخول أو المصادقة',
            'data_sync'=>'تحديث أو مزامنة البيانات',
            'payment'=>'الدفع أو الفوترة',
            'service_unavailable'=>'تعطل أو عدم توفر الخدمة',
            'security_incident_confirmed'=>'حادث أمني أكدته الجهة رسميًا',
            default=>'مشكلة تقنية',
        };
    }

    private function expireOldSignalsAndIncidents(): void
    {
        Database::execute("UPDATE social_signals SET review_status='expired' WHERE review_status='candidate' AND datetime(expires_at)<=datetime('now')");
        Database::execute("UPDATE live_service_incidents SET status='resolved',resolved_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE status IN ('confirmed','suspected') AND datetime(expires_at)<=datetime('now')");
        try {
            Database::execute("UPDATE social_solution_knowledge SET status='expired',updated_at=CURRENT_TIMESTAMP WHERE status='usable' AND datetime(valid_until)<=datetime('now')");
        } catch (\Throwable) {}
    }


    private function verifiedHandlesForTarget(int $targetId): array
    {
        try {
            $rows=Database::fetchAll(
                "SELECT handle FROM official_x_accounts
                 WHERE target_id=? AND active=1 AND verification_status='verified'
                 ORDER BY CASE account_role WHEN 'support' THEN 0 ELSE 1 END,id",
                [$targetId]
            );
            $handles=[];
            foreach($rows as $r){
                $h=trim((string)($r['handle']??''));
                if($h!=='') $handles[]=ltrim($h,'@');
            }
            if($handles!==[]) return array_values(array_unique($handles));
        } catch (\Throwable) {}

        $row=Database::fetch("SELECT official_handle,handle_status FROM social_watch_targets WHERE id=?",[$targetId]);
        $h=trim((string)($row['official_handle']??''));
        return $h!=='' && (string)($row['handle_status']??'')==='verified' ? [ltrim($h,'@')] : [];
    }

    private function storeTemporaryOfficialSolution(
        array $target,
        string $issueType,
        string $problemExcerpt,
        string $solutionText,
        string $evidenceUrl,
        string $officialHandle,
        ?string $publishedAt,
        int $confidence
    ): void {
        if ($issueType==='security_claim_unverified' || $issueType==='none') return;
        $days=max(1,min(30,(int)Settings::get('social_solution_valid_days','7')));
        $baseTs=$publishedAt!==null ? strtotime($publishedAt) : time();
        if($baseTs===false)$baseTs=time();
        $validUntil=gmdate('Y-m-d H:i:s',$baseTs+$days*86400);
        $hash=hash('sha256',mb_strtolower($target['id'].'|'.$issueType.'|'.$solutionText.'|'.$evidenceUrl));
        try {
            Database::execute(
                "INSERT INTO social_solution_knowledge(target_id,source_id,issue_type,problem_excerpt,solution_text,evidence_url,official_handle,first_seen_at,last_verified_at,valid_until,confidence,status,content_hash,created_at,updated_at)
                 VALUES(?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP,?,?,'usable',?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)
                 ON CONFLICT(evidence_url) DO UPDATE SET target_id=excluded.target_id,source_id=excluded.source_id,issue_type=excluded.issue_type,problem_excerpt=excluded.problem_excerpt,solution_text=excluded.solution_text,official_handle=excluded.official_handle,last_verified_at=CURRENT_TIMESTAMP,valid_until=excluded.valid_until,confidence=excluded.confidence,status='usable',content_hash=excluded.content_hash,updated_at=CURRENT_TIMESTAMP",
                [
                    (int)$target['id'],
                    isset($target['source_id'])?(int)$target['source_id']:null,
                    $issueType,
                    mb_substr($problemExcerpt,0,900),
                    mb_substr($solutionText,0,900),
                    $evidenceUrl,
                    $officialHandle,
                    $publishedAt ?: gmdate('Y-m-d H:i:s'),
                    $validUntil,
                    max(85,min(99,$confidence)),
                    $hash
                ]
            );
        } catch (\Throwable) {}
    }

    private function startScan(int $targetId,string $query,string $mode): int
    {
        Database::execute('INSERT INTO social_intelligence_scans(target_id,provider,mode,query_text,status,started_at) VALUES(?,\'exa\',?,?,\'running\',CURRENT_TIMESTAMP)',[$targetId,$mode,$query]);
        return Database::lastInsertId();
    }

    private function finishScan(int $scanId,string $status,int $count,?string $error): void
    {
        if($scanId<=0)return;
        Database::execute('UPDATE social_intelligence_scans SET status=?,result_count=?,error_message=?,finished_at=CURRENT_TIMESTAMP WHERE id=?',[$status,$count,$error!==null?mb_substr($error,0,600):null,$scanId]);
    }

    private function isXUrl(string $url): bool
    {
        if(!filter_var($url,FILTER_VALIDATE_URL))return false;
        $host=strtolower((string)(parse_url($url,PHP_URL_HOST)?:''));
        $host=preg_replace('/^www\./','',$host)??$host;
        return $host==='x.com'||$host==='twitter.com';
    }

    private function extractHandle(string $url): string
    {
        $path=trim((string)(parse_url($url,PHP_URL_PATH)?:''),'/');
        $first=explode('/',$path)[0]??'';
        if($first===''||in_array(strtolower($first),['i','intent','share','home','search'],true))return '';
        return preg_match('/^[A-Za-z0-9_]{1,30}$/',$first)?$first:'';
    }

    private function normalize(string $text): string
    {
        $text=mb_strtolower($text);
        $text=str_replace(['أ','إ','آ','ة','ى','ؤ','ئ'],['ا','ا','ا','ه','ي','و','ي'],$text);
        $text=preg_replace('/[\x{064B}-\x{065F}\x{0670}]/u','',$text)??$text;
        $text=preg_replace('/[^\p{Arabic}\p{L}\p{N}@._]+/u',' ',$text)??$text;
        return trim(preg_replace('/\s+/u',' ',$text)??$text);
    }
}
