<?php

declare(strict_types=1);

namespace Khatauat\Services;

use Khatauat\Core\Database;
use Khatauat\Core\Settings;

final class PublicAiService
{
    public function isConfigured(): bool
    {
        return trim((string)(getenv('AI_API_KEY') ?: '')) !== ''
            && trim((string)Settings::get('ai_api_url', '')) !== ''
            && trim((string)Settings::get('ai_model', '')) !== '';
    }

    /**
     * Public problem solver. It uses only verified Khatauat content and verified
     * problem knowledge. It never performs a live Exa/web lookup for a visitor.
     */
    public function answer(string $question): array
    {
        $question = trim($question);
        if (mb_strlen($question) < 3) {
            return ['ok'=>false,'error'=>'اكتب سؤالك أو المشكلة بصورة أوضح.'];
        }
        if (mb_strlen($question) > 700) {
            return ['ok'=>false,'error'=>'اختصر السؤال إلى 700 حرف أو أقل.'];
        }

        $services = $this->findServices($question);
        $knowledge = $this->findProblemKnowledge($question, $services);
        $resolvedContact = $this->resolveContact($question, $services, $knowledge);
        $socialSolutions = [];

        // Current technical intelligence is consulted only for technical questions.
        // Public X posts remain signals, not authoritative facts. Verified official
        // replies may become short-lived operational evidence and expire automatically.
        if ($this->looksTechnical($question)) {
            try {
                $social = (new SocialIntelligenceService())->contextForQuestion($question, $services, true);
                if (is_array($social) && !empty($social['incident'])) {
                    return $this->buildSocialIncidentResponse($question, $services, $knowledge, $resolvedContact, $social);
                }
                if (is_array($social)) {
                    $socialSolutions = array_values(array_filter((array)($social['solutions'] ?? []), 'is_array'));
                }
            } catch (\Throwable) {
                // Social/X intelligence is an enhancement, never a dependency.
            }
        }

        if ($services === [] && $knowledge === [] && $socialSolutions !== []) {
            return $this->buildSocialSolutionResponse($question, $resolvedContact, $socialSolutions);
        }

        if ($services === [] && $knowledge === []) {
            return [
                'ok'=>true,
                'used_ai'=>false,
                'status'=>'insufficient',
                'text'=>$resolvedContact
                    ? 'لم أجد في قاعدة «خطوات» حلاً موثقًا لهذه المشكلة تحديدًا، لذلك لن أخمّن. حدد نوع العطل إن احتجت، واستخدم قناة الدعم الرسمية الظاهرة أدناه.'
                    : 'لم أجد حتى الآن معلومة موثقة داخل «خطوات» تطابق هذه الحالة. لن أخمّن حلاً. اذكر اسم الجهة أو المنصة الرسمية ورسالة الخطأ أو نوع المشكلة، ويمكنك أيضًا استخدام البحث العادي دون استهلاك رصيد AI.',
                'services'=>[],
                'diagnostic_questions'=>$this->genericClarifyingQuestions($question, $resolvedContact),
                'contact'=>$resolvedContact,
                'evidence'=>[],
            ];
        }

        $context = $this->buildContext($services, $knowledge);
        if ($socialSolutions !== []) {
            $context['temporary_official_support'] = array_map(static fn($s) => [
                'issue_type'=>(string)($s['issue_type'] ?? ''),
                'problem_excerpt'=>(string)($s['problem_excerpt'] ?? ''),
                'solution_text'=>(string)($s['solution_text'] ?? ''),
                'evidence_url'=>(string)($s['evidence_url'] ?? ''),
                'official_handle'=>(string)($s['official_handle'] ?? ''),
                'last_verified_at'=>(string)($s['last_verified_at'] ?? ''),
                'valid_until'=>(string)($s['valid_until'] ?? ''),
                'confidence'=>(int)($s['confidence'] ?? 0),
            ], $socialSolutions);
        }
        if ($context === []) {
            return [
                'ok'=>true,
                'used_ai'=>false,
                'status'=>'insufficient',
                'text'=>'وجدت خدمة قريبة، لكن لا توجد معلومات موثقة كافية لحل هذه الحالة بعد. لن أخمّن الإجراء، ولن يتم خصم استفسار AI من رصيدك.',
                'services'=>$services,
                'diagnostic_questions'=>$this->buildDiagnosticQuestions($question, $services, $knowledge),
                'contact'=>$resolvedContact,
                'evidence'=>[],
            ];
        }

        $endpoint = trim((string)Settings::get('ai_api_url', ''));
        $model = trim((string)Settings::get('ai_model', ''));
        $key = trim((string)(getenv('AI_API_KEY') ?: ''));
        if ($endpoint === '' || $model === '' || $key === '') {
            return ['ok'=>false,'error'=>'خدمة الذكاء الاصطناعي غير مهيأة حاليًا.'];
        }
        if (!function_exists('curl_init')) {
            return ['ok'=>false,'error'=>'امتداد cURL غير متاح على الخادم.'];
        }

        $parts = parse_url($endpoint);
        $host = strtolower((string)($parts['host'] ?? ''));
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $path = (string)($parts['path'] ?? '');
        if ($scheme !== 'https' || $host !== 'api.groq.com' || $path !== '/openai/v1/chat/completions') {
            return ['ok'=>false,'error'=>'موصل الاستفسارات العامة غير مضبوط على مزود Groq المعتمد للمرحلة الحالية.'];
        }

        $system = <<<'SYS'
أنت «خطوات AI»، مساعد تشخيصي لمنصة سعودية مستقلة وليست جهة حكومية.
مهمتك الوصول إلى حل مفيد قابل للتنفيذ، لكن حصريًا من السياق الموثق المرسل لك.

قواعد صارمة:
1) لا تستخدم الذاكرة العامة ولا تخترع شرطًا أو رسمًا أو مستندًا أو مدة أو رابطًا أو حلًا تقنيًا.
2) قد يحتوي السياق على temporary_official_support: ردود تشغيلية حديثة من حساب X رسمي موثق. استخدمها فقط داخل تاريخ صلاحيتها، واذكر أنها توجيه حديث قابل للتغير وليست قاعدة دائمة.
3) إذا كان السياق يحسم الحل، أعط الحل بالترتيب وبوضوح.
4) إذا كان اختيار الإجراء الصحيح يعتمد على معلومة ناقصة من المستخدم، اجعل status = needs_clarification ولا تتظاهر بأنك عرفت الإجابة.
5) إذا كانت المشكلة تقنية/حسابية خاصة بالمنصة الرسمية ولا يوجد حل موثق في السياق، اجعل status = insufficient وقل بوضوح إن المصادر لا تحتوي حلًا موثقًا وأن التواصل مع دعم الجهة هو الأنسب.
6) لا تطلب أبدًا رقم هوية، كلمة مرور، رمز OTP، بيانات بطاقة أو أي سر.
7) لا تقل إن «خطوات» تنفذ الخدمة أو تمثل جهة حكومية.
8) لا تولّد أسئلة متابعة بنفسك؛ النظام سيعرض أسئلة تشخيصية مبنية على قواعد موثقة.

أعد JSON فقط بهذه البنية:
{
  "status":"resolved|needs_clarification|insufficient",
  "answer":"نص عربي واضح ومختصر",
  "evidence":[{"label":"اسم المصدر","url":"https://..."}],
  "missing_information":"ما الذي ينقص فقط إن وجد"
}
SYS;

        $payload = [
            'model'=>$model,
            'messages'=>[
                ['role'=>'system','content'=>$system],
                ['role'=>'user','content'=>"سؤال/مشكلة المستخدم:\n{$question}\n\nسياق خطوات الموثق:\n".json_encode($context, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)],
            ],
            'temperature'=>0.05,
            'max_tokens'=>1100,
        ];

        $ch = curl_init($endpoint);
        curl_setopt_array($ch,[
            CURLOPT_POST=>true,
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_CONNECTTIMEOUT=>12,
            CURLOPT_TIMEOUT=>50,
            CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$key],
            CURLOPT_POSTFIELDS=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
        ]);
        $raw = curl_exec($ch);
        $statusCode = (int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false) return ['ok'=>false,'error'=>$error !== '' ? $error : 'تعذر الاتصال بمزود AI.'];
        $json = json_decode((string)$raw,true);
        if ($statusCode < 200 || $statusCode >= 300) {
            $detail = is_array($json) ? trim((string)($json['error']['message'] ?? '')) : '';
            return ['ok'=>false,'error'=>$detail !== '' ? $detail : ('Groq HTTP '.$statusCode)];
        }

        $content = trim((string)($json['choices'][0]['message']['content'] ?? ''));
        if ($content === '') return ['ok'=>false,'error'=>'عاد AI بدون إجابة قابلة للاستخدام.'];

        $structured = $this->decodeJsonObject($content);
        if ($structured === null) {
            $structured = [
                'status'=>'needs_clarification',
                'answer'=>$content,
                'evidence'=>[],
                'missing_information'=>'',
            ];
        }

        $status = (string)($structured['status'] ?? 'needs_clarification');
        if (!in_array($status,['resolved','needs_clarification','insufficient'],true)) {
            $status='needs_clarification';
        }
        $text = trim((string)($structured['answer'] ?? ''));
        if ($text === '') $text='لم أتمكن من تكوين إجابة موثقة كافية لهذه الحالة.';

        $diagnosticQuestions = $status === 'resolved'
            ? []
            : $this->buildDiagnosticQuestions($question, $services, $knowledge);

        $contact = null;
        if ($status === 'insufficient' || ($status === 'needs_clarification' && $this->looksTechnical($question))) {
            $contact = $resolvedContact;
        }

        return [
            'ok'=>true,
            'used_ai'=>true,
            'status'=>$status,
            'text'=>$text,
            'missing_information'=>trim((string)($structured['missing_information'] ?? '')),
            'evidence'=>$this->sanitizeEvidence((array)($structured['evidence'] ?? []), $context),
            'services'=>$services,
            'diagnostic_questions'=>$diagnosticQuestions,
            'contact'=>$contact,
        ];
    }

    private function findServices(string $question): array
    {
        $like = '%'.$question.'%';
        $rows = Database::fetchAll(
            "SELECT DISTINCT s.id,s.name,s.slug,s.summary,s.official_entity,s.official_url,c.name category_name
             FROM services s
             LEFT JOIN categories c ON c.id=s.category_id
             LEFT JOIN service_steps st ON st.service_id=s.id
             WHERE s.status='published' AND (
                 s.name LIKE ? OR s.summary LIKE ? OR s.official_entity LIKE ? OR s.requirements LIKE ?
                 OR st.title LIKE ? OR st.entity LIKE ? OR st.platform LIKE ? OR st.action_text LIKE ?
             )
             ORDER BY s.id DESC LIMIT 5",
            [$like,$like,$like,$like,$like,$like,$like,$like]
        );
        if ($rows !== []) return $rows;

        $stop = ['كيف','ماذا','اريد','أريد','احتاج','أحتاج','ابي','أبي','هل','في','من','على','الى','إلى','عن','ما','هو','هي','لي','لدي','عندي','السعودية','السعوديه','مشكلة','مشكلتي'];
        $tokens = preg_split('/[^\p{Arabic}\p{L}\p{N}]+/u', mb_strtolower($question)) ?: [];
        $tokens = array_values(array_unique(array_filter($tokens, static fn($t) => mb_strlen($t) >= 3 && !in_array($t,$stop,true))));
        $tokens = array_slice($tokens,0,8);
        if ($tokens === []) return [];
        $where=[];$params=[];
        foreach ($tokens as $token) {
            $where[]='(s.name LIKE ? OR s.summary LIKE ? OR s.official_entity LIKE ? OR s.requirements LIKE ? OR st.title LIKE ? OR st.entity LIKE ? OR st.platform LIKE ? OR st.action_text LIKE ?)';
            for($i=0;$i<8;$i++) $params[]='%'.$token.'%';
        }
        return Database::fetchAll(
            "SELECT DISTINCT s.id,s.name,s.slug,s.summary,s.official_entity,s.official_url,c.name category_name
             FROM services s LEFT JOIN categories c ON c.id=s.category_id LEFT JOIN service_steps st ON st.service_id=s.id
             WHERE s.status='published' AND (".implode(' OR ',$where).")
             ORDER BY s.id DESC LIMIT 5",
            $params
        );
    }

    private function findProblemKnowledge(string $question, array $services): array
    {
        try {
            $rows=Database::fetchAll("SELECT * FROM service_problem_knowledge WHERE trust_status='verified' ORDER BY priority DESC,id DESC");
        } catch (\Throwable) {
            return [];
        }
        $q=mb_strtolower($question);
        $serviceIds=array_map(static fn($s)=>(int)$s['id'],$services);
        $matched=[];
        foreach($rows as $row){
            $serviceId=(int)($row['service_id'] ?? 0);
            $terms=array_values(array_filter(array_map('trim',explode('|',(string)($row['trigger_terms'] ?? '')))));
            $score=0;
            foreach($terms as $term){ if($term!=='' && mb_stripos($q,mb_strtolower($term))!==false) $score+=2; }
            if($serviceId>0 && in_array($serviceId,$serviceIds,true)) $score+=1;
            if($score>0){ $row['_score']=$score; $matched[]=$row; }
        }
        usort($matched,static fn($a,$b)=>(int)$b['_score']<=>(int)$a['_score']);
        return array_slice($matched,0,3);
    }

    private function buildContext(array $services, array $knowledge): array
    {
        $context=[];
        foreach ($services as $service) {
            $steps = Database::fetchAll(
                "SELECT st.position,st.title,st.entity,st.platform,st.prerequisite,st.action_text,st.output_text,st.official_url,st.verified_at,src.title source_title,src.url source_url
                 FROM service_steps st LEFT JOIN sources src ON src.id=st.source_id
                 WHERE st.service_id=? AND st.trust_status='verified' AND st.source_id IS NOT NULL AND st.verified_at IS NOT NULL
                 ORDER BY st.position LIMIT 25",
                [(int)$service['id']]
            );
            if ($steps === []) continue;
            $context['services'][]=[
                'service'=>[
                    'name'=>(string)$service['name'],
                    'summary'=>(string)$service['summary'],
                    'official_entity'=>(string)$service['official_entity'],
                    'official_url'=>(string)$service['official_url'],
                    'khatauat_url'=>\url('service/'.(string)$service['slug']),
                ],
                'steps'=>$steps,
            ];
        }
        foreach($knowledge as $row){
            $facts=json_decode((string)($row['verified_facts_json'] ?? '[]'),true);
            $context['problem_knowledge'][]=[
                'title'=>(string)$row['title'],
                'facts'=>is_array($facts)?$facts:[],
                'source_title'=>(string)($row['source_title'] ?? ''),
                'source_url'=>(string)($row['source_url'] ?? ''),
                'verified_at'=>(string)($row['verified_at'] ?? ''),
            ];
        }
        return $context;
    }

    /** Questions are deterministic and tied to verified knowledge or service stages. */
    private function buildDiagnosticQuestions(string $question, array $services, array $knowledge): array
    {
        $out=[];
        foreach($knowledge as $row){
            $items=json_decode((string)($row['diagnostic_questions_json'] ?? '[]'),true);
            if(!is_array($items)) continue;
            foreach($items as $item){
                if(!is_array($item) || trim((string)($item['question'] ?? ''))==='') continue;
                $out[]=[
                    'question'=>trim((string)$item['question']),
                    'purpose'=>trim((string)($item['purpose'] ?? 'هذا الجواب يغيّر الإجراء التالي.')),
                    'options'=>array_values(array_slice(array_filter((array)($item['options'] ?? []),'is_string'),0,8)),
                ];
                if(count($out)>=3) return $out;
            }
        }

        if($services!==[]){
            $service=$services[0];
            $steps=Database::fetchAll('SELECT position,title,entity,platform FROM service_steps WHERE service_id=? AND trust_status="verified" ORDER BY position LIMIT 7',[(int)$service['id']]);
            if($steps!==[]){
                $out[]=[
                    'question'=>'في أي مرحلة من المسار توقفت المشكلة؟',
                    'purpose'=>'تحديد المرحلة يمنع إعطاء حل لجزء مختلف من الإجراء.',
                    'options'=>array_values(array_map(static fn($s)=>trim((string)$s['title']),$steps)),
                ];
            }
        }
        if($this->looksTechnical($question) && count($out)<3){
            foreach($this->technicalDecisionQuestions($question) as $item){
                $out[]=$item;
                if(count($out)>=3) break;
            }
        }
        return array_slice($out,0,3);
    }

    private function genericClarifyingQuestions(string $question, ?array $contact = null): array
    {
        $questions=[];
        if($this->looksTechnical($question)){
            if($contact===null){
                $questions[]=[
                    'question'=>'ما اسم الجهة أو المنصة الرسمية التي تظهر فيها المشكلة؟',
                    'purpose'=>'تحديد الجهة هو الذي يحدد قناة الدعم والمسار الصحيح؛ لن نفترضها من تلقاء أنفسنا.',
                    'options'=>[],
                ];
            }
            foreach($this->technicalDecisionQuestions($question) as $item){
                $questions[]=$item;
                if(count($questions)>=3) break;
            }
        }
        return array_slice($questions,0,3);
    }

    private function resolveContact(string $question, array $services, array $knowledge): ?array
    {
        $stepTerms=[];
        foreach($services as $service){
            try{
                $steps=Database::fetchAll('SELECT entity,platform,title FROM service_steps WHERE service_id=? ORDER BY position LIMIT 12',[(int)$service['id']]);
                foreach($steps as $step){
                    foreach(['entity','platform','title'] as $k){
                        $v=trim((string)($step[$k]??''));
                        if($v!=='') $stepTerms[]=$v;
                    }
                }
            }catch(\Throwable){}
        }
        $hay=mb_strtolower(trim($question.' '.implode(' ',array_map(static fn($s)=>(string)($s['official_entity']??''),$services)).' '.implode(' ',$stepTerms)));

        // v2.0.8: source-first routing. Every approved source is mapped to an escalation profile.
        try{
            $rows=Database::fetchAll("SELECT s.id,s.name,s.entity,s.domain,s.sector,m.support_scope,c.*\n                FROM source_registry s\n                JOIN official_source_support m ON m.source_id=s.id\n                JOIN official_entity_contacts c ON c.id=m.contact_id\n                WHERE s.status='active' AND c.trust_status='verified'");
        }catch(\Throwable){$rows=[];}

        $best=null;$bestScore=0;
        foreach($rows as $row){
            $score=0;
            $name=mb_strtolower(trim((string)($row['name']??'')));
            $entity=mb_strtolower(trim((string)($row['entity']??'')));
            $domain=mb_strtolower(trim((string)($row['domain']??'')));
            if($name!=='' && mb_stripos($hay,$name)!==false) $score+=12;
            if($domain!=='' && mb_stripos($hay,$domain)!==false) $score+=10;
            if($entity!=='' && mb_stripos($hay,$entity)!==false) $score+=5;

            $aliases=array_filter(array_map('trim',explode('|',(string)($row['aliases']??''))));
            foreach($aliases as $alias){
                $a=mb_strtolower($alias);
                if(mb_strlen($a)>=3 && mb_stripos($hay,$a)!==false) $score+=7;
            }
            if($score>$bestScore){$best=$row;$bestScore=$score;}
        }

        // If source routing did not find a match, fall back to the legacy verified alias resolver.
        if($best===null){
            try{$contacts=Database::fetchAll("SELECT * FROM official_entity_contacts WHERE trust_status='verified' ORDER BY priority DESC,id");}
            catch(\Throwable){return null;}
            foreach($contacts as $c){
                $score=0;
                $terms=array_filter(array_map('trim',explode('|',(string)($c['aliases']??''))));
                $terms[]=(string)($c['entity_name']??'');
                foreach($terms as $term){
                    $t=mb_strtolower(trim($term));
                    if($t!=='' && mb_strlen($t)>=3 && mb_stripos($hay,$t)!==false) $score+=2;
                }
                if($score>$bestScore){$best=$c;$bestScore=$score;}
            }
        }
        if(!$best || $bestScore<=0) return null;

        $contactId=(int)($best['id']??0);
        $centers=[];
        if($contactId>0){
            try{
                $centers=Database::fetchAll('SELECT name,region,city,address,latitude,longitude,google_maps_url,source_url FROM official_service_centers WHERE contact_id=? AND active=1 ORDER BY city,name',[$contactId]);
            }catch(\Throwable){}
        }

        return [
            'entity_name'=>(string)($best['entity_name']??$best['name']??'الجهة الرسمية'),
            'source_name'=>(string)($best['name']??''),
            'phone'=>(string)($best['phone']??''),
            'email'=>(string)($best['email']??''),
            'support_url'=>(string)($best['support_url']??''),
            'branches_url'=>(string)($best['branches_url']??''),
            'maps_query'=>(string)($best['maps_query']??''),
            'maps_enabled'=>(int)($best['maps_enabled']??0)===1,
            'source_url'=>(string)($best['source_url']??''),
            'verified_at'=>(string)($best['verified_at']??''),
            'verification_level'=>(string)($best['verification_level']??'official_site_only'),
            'support_scope'=>(string)($best['support_scope']??'official_site'),
            'notes'=>(string)($best['notes']??''),
            'centers'=>$centers,
        ];
    }


    private function technicalDecisionQuestions(string $question): array
    {
        $q=mb_strtolower($question);
        if(mb_stripos($q,'لم يتحدث')!==false || mb_stripos($q,'لم تتحدث')!==false || mb_stripos($q,'لا يظهر')!==false || mb_stripos($q,'غير ظاهر')!==false){
            return [[
                'question'=>'هل ظهر التغيير أولًا في الجهة التي نفذت فيها الإجراء الأساسي؟',
                'purpose'=>'إذا لم يظهر التغيير في الجهة الأصلية فالمشكلة في الإجراء الأساسي، أما إذا ظهر هناك فقط فالمشكلة غالبًا في انتقال أو مزامنة البيانات بين الجهات.',
                'options'=>['نعم، ظهر في الجهة الأصلية','لا، لم يظهر حتى في الجهة الأصلية','غير متأكد'],
            ]];
        }
        if(mb_stripos($q,'دخول')!==false || mb_stripos($q,'نفاذ')!==false || mb_stripos($q,'رمز')!==false){
            return [[
                'question'=>'هل يتوقف الدخول قبل التحقق عبر النفاذ/رمز التحقق أم بعد نجاح تسجيل الدخول؟',
                'purpose'=>'موضع التوقف يحدد هل نبحث في المصادقة أم في صلاحية الخدمة داخل المنصة نفسها.',
                'options'=>['قبل نجاح تسجيل الدخول','بعد نجاح تسجيل الدخول','لا أعرف'],
            ]];
        }
        if(mb_stripos($q,'دفع')!==false || mb_stripos($q,'سداد')!==false || mb_stripos($q,'فاتور')!==false || mb_stripos($q,'خصم')!==false){
            return [[
                'question'=>'هل تم خصم المبلغ فعليًا أم توقفت العملية قبل الخصم؟',
                'purpose'=>'وجود خصم مالي يغيّر مسار الحل والتصعيد مقارنة بفشل إنشاء الفاتورة أو الدفع قبل الخصم.',
                'options'=>['تم الخصم','لم يتم الخصم','غير متأكد'],
            ]];
        }
        if(mb_stripos($q,'رفض')!==false || mb_stripos($q,'مرفوض')!==false){
            return [[
                'question'=>'هل يظهر سبب رفض محدد داخل الطلب؟',
                'purpose'=>'سبب الرفض الرسمي هو الذي يحدد إن كان المطلوب تصحيح بيانات أو استيفاء شرط أو رفع تذكرة دعم.',
                'options'=>['نعم، يوجد سبب واضح','لا، يظهر رفض بدون سبب','لا أستطيع فتح تفاصيل الطلب'],
            ]];
        }
        return [[
            'question'=>'ما الذي يحدث تحديدًا عند محاولة تنفيذ الخدمة؟',
            'purpose'=>'التمييز بين رسالة خطأ، عدم تحديث البيانات، تعذر الدخول، أو عدم ظهور الخدمة يغير الحل بالكامل.',
            'options'=>['تظهر رسالة خطأ','البيانات لم تتحدث','تعذر الدخول','الخدمة غير ظاهرة','الطلب متوقف/مرفوض','شيء آخر'],
        ]];
    }

    private function buildSocialSolutionResponse(string $question, ?array $contact, array $solutions): array
    {
        $best=$solutions[0] ?? null;
        if(!is_array($best)) return ['ok'=>true,'used_ai'=>false,'status'=>'insufficient','text'=>'لا توجد إجابة تشغيلية حديثة كافية.','services'=>[],'diagnostic_questions'=>[],'contact'=>$contact,'evidence'=>[]];
        $solution=trim((string)($best['solution_text'] ?? ''));
        $url=trim((string)($best['evidence_url'] ?? ''));
        $handle=trim((string)($best['official_handle'] ?? ''));
        $valid=trim((string)($best['valid_until'] ?? ''));
        $text='وجدت «خطوات» توجيهًا تشغيليًا حديثًا منشورًا من حساب X رسمي موثق';
        if($handle!=='') $text.=' (@'.ltrim($handle,'@').')';
        $text.=' يتعلق بنوع المشكلة التي وصفتها.';
        if($solution!=='') $text.="\n\n".$solution;
        $text.="\n\nهذه معلومة تشغيلية حديثة وليست قاعدة دائمة؛ يعاد التحقق منها تلقائيًا";
        if($valid!=='') $text.=' وصلاحيتها الحالية حتى '.$valid;
        $text.='.';
        return [
            'ok'=>true,
            'used_ai'=>false,
            'status'=>'temporary_official_solution',
            'text'=>$text,
            'missing_information'=>'',
            'evidence'=>filter_var($url,FILTER_VALIDATE_URL)?[['label'=>'رد/توجيه حديث من حساب X رسمي موثق','url'=>$url]]:[],
            'services'=>[],
            'diagnostic_questions'=>[],
            'contact'=>$contact,
            'temporary_solution'=>[
                'official_handle'=>$handle,
                'last_verified_at'=>(string)($best['last_verified_at'] ?? ''),
                'valid_until'=>$valid,
                'confidence'=>(int)($best['confidence'] ?? 0),
            ],
        ];
    }

    private function looksTechnical(string $question): bool
    {
        $q=mb_strtolower($question);
        foreach(['خطأ','مشكلة','لا يعمل','لا يفتح','تعطل','رفض','مرفوض','لم يتحدث','ما زال','لا يظهر','غير ظاهر','لا استطيع','لا أستطيع','تقني','تحديث البيانات'] as $term){
            if(mb_stripos($q,$term)!==false) return true;
        }
        return false;
    }

    private function buildSocialIncidentResponse(string $question, array $services, array $knowledge, ?array $resolvedContact, array $social): array
    {
        $incident=(array)($social['incident'] ?? []);
        $target=(array)($social['target'] ?? []);
        $status=(string)($incident['status'] ?? 'suspected');
        $entity=trim((string)($incident['entity_name'] ?? $target['entity_name'] ?? 'المنصة الرسمية'));
        $issue=(string)($incident['issue_type'] ?? 'service_unavailable');
        $label=match($issue){
            'maintenance_update'=>'صيانة أو تحديث للنظام',
            'login_auth'=>'تسجيل الدخول أو المصادقة',
            'data_sync'=>'تحديث أو مزامنة البيانات',
            'payment'=>'الدفع أو الفوترة',
            'service_unavailable'=>'تعطل أو عدم توفر الخدمة',
            'security_incident_confirmed'=>'حادث أمني أكدته الجهة رسميًا',
            default=>'مشكلة تقنية',
        };

        $workaround=trim((string)($incident['workaround'] ?? ''));
        if($status==='confirmed'){
            $text='يوجد تنبيه حديث موثق مرتبط بـ «'.$entity.'» حول '.$label.'. قد تكون المشكلة التي تواجهها مرتبطة بالحالة الحالية.';
            if($workaround!==''){
                $text.="\n\nتوجيه منشور من الحساب الرسمي المرتبط بالجهة:\n".$workaround;
            }else{
                $text.="\n\nلم نجد في التنبيه الحالي حلاً تفصيليًا موثقًا؛ إذا استمرت المشكلة استخدم قناة الدعم الرسمية أدناه بدل تجربة إجراءات غير مؤكدة.";
            }
            $resultStatus='incident_confirmed';
            $diagnostic=[];
        }else{
            $text='رصدت «خطوات» عدة بلاغات عامة متقاربة على منصة X تخص «'.$entity.'» حول '.$label.'. لا يوجد لدينا تأكيد رسمي حتى الآن، لذلك لا نصف الحالة بأنها عطل مؤكد ولا ننسب سببًا تقنيًا أو أمنيًا للجهة.';
            $text.="\n\nإذا بدأت مشكلتك مؤخرًا وكانت الخدمة تعمل سابقًا، فقد تكون مرتبطة بخلل مؤقت. إذا كانت المشكلة خاصة بحسابك أو مستمرة منذ فترة، استخدم التشخيص المعتاد أو الدعم الرسمي.";
            $resultStatus='incident_suspected';
            $diagnostic=[[
                'question'=>'هل بدأت هذه المشكلة اليوم أو بعد أن كانت الخدمة تعمل معك بشكل طبيعي؟',
                'purpose'=>'هذا يفرق بين خلل عام مؤقت وبين مشكلة خاصة بالحساب أو الإجراء نفسه.',
                'options'=>['نعم، بدأت مؤخرًا','لا، المشكلة قديمة أو مستمرة','غير متأكد'],
            ]];
        }

        $evidence=[];
        $officialUrl=trim((string)($incident['official_evidence_url'] ?? ''));
        if($status==='confirmed' && filter_var($officialUrl,FILTER_VALIDATE_URL)){
            $evidence[]=['label'=>'تنبيه أو رد من حساب X رسمي موثق','url'=>$officialUrl];
        }

        return [
            'ok'=>true,
            'used_ai'=>false,
            'status'=>$resultStatus,
            'text'=>$text,
            'missing_information'=>'',
            'evidence'=>$evidence,
            'services'=>$services,
            'diagnostic_questions'=>$diagnostic,
            'contact'=>$resolvedContact,
            'incident'=>[
                'status'=>$status,
                'entity_name'=>$entity,
                'issue_label'=>$label,
                'confidence'=>(int)($incident['confidence'] ?? 0),
                'user_report_count'=>(int)($incident['user_report_count'] ?? 0),
                'official_evidence_count'=>(int)($incident['official_evidence_count'] ?? 0),
                'last_seen_at'=>(string)($incident['last_seen_at'] ?? ''),
                'expires_at'=>(string)($incident['expires_at'] ?? ''),
                'official_evidence_url'=>$officialUrl,
            ],
        ];
    }

    private function decodeJsonObject(string $content): ?array
    {
        $content=trim($content);
        $content=preg_replace('/^```(?:json)?\s*/i','',$content) ?? $content;
        $content=preg_replace('/\s*```$/','',$content) ?? $content;
        $data=json_decode($content,true);
        return is_array($data)?$data:null;
    }

    private function sanitizeEvidence(array $evidence, array $context): array
    {
        $allowed=[];
        foreach(($context['services'] ?? []) as $svc){
            $u=trim((string)($svc['service']['official_url'] ?? ''));
            if($u!=='') $allowed[$u]=true;
            foreach(($svc['steps'] ?? []) as $st){
                foreach(['official_url','source_url'] as $key){
                    $u=trim((string)($st[$key] ?? ''));
                    if($u!=='') $allowed[$u]=true;
                }
            }
        }
        foreach(($context['problem_knowledge'] ?? []) as $k){
            $u=trim((string)($k['source_url'] ?? ''));
            if($u!=='') $allowed[$u]=true;
        }
        foreach(($context['temporary_official_support'] ?? []) as $k){
            $u=trim((string)($k['evidence_url'] ?? ''));
            if($u!=='') $allowed[$u]=true;
        }
        $out=[];
        foreach($evidence as $item){
            if(!is_array($item)) continue;
            $url=trim((string)($item['url'] ?? ''));
            if($url==='' || !isset($allowed[$url])) continue;
            $out[]=['label'=>trim((string)($item['label'] ?? 'مصدر رسمي')),'url'=>$url];
        }
        return array_slice($out,0,6);
    }
}
