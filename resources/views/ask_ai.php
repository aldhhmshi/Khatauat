<section class="ai-ask-page">
  <div class="container ai-ask-shell">
    <div class="ai-ask-intro">
      <span class="eyebrow">هل تواجه مشكلة؟ ابدأ من هنا</span>
      <h1>اسأل خطوات AI</h1>
      <p>اذكر المشكلة كما حدثت معك. لن نسألك أسئلة عشوائية؛ كل سؤال تشخيصي يجب أن يغيّر الإجراء التالي أو يحدد الجهة التي تحتاجها.</p>
      <?php if (\Khatauat\Core\Auth::isOwner()): ?>
        <div class="ai-quota-badge">حساب المالك — بدون حد المستخدم المجاني</div>
      <?php elseif (!empty($paid_case)): ?>
        <div class="ai-quota-badge is-paid">جلسة مشكلة مدفوعة — متبقي <strong><?= (int)$remaining ?></strong> من <?= (int)$limit ?> رسالة</div>
      <?php else: ?>
        <div class="ai-quota-badge">متبقي اليوم: <strong><?= (int)$remaining ?></strong> من <?= (int)$limit ?> استفسارات AI</div>
        <div class="ai-paid-entry"><span>رصيد المشاكل: <strong><?= (int)($case_balance ?? 0) ?></strong></span><a href="<?= e(url('billing')) ?>">إدارة الرصيد</a><a href="<?= e(url('plans')) ?>">الباقات والدفع</a></div>
        <?php if ((int)($case_balance ?? 0) <= 0): ?>
          <div class="ai-purchase-callout">
            <div><span>تحتاج متابعة أعمق؟</span><strong>ابدأ بحل مشكلتين مقابل 10 ريال</strong><small>تدفع مرة واحدة، وتصبح لكل مشكلة جلسة مستقلة داخل خطوات.</small></div>
            <a class="btn btn-primary" href="<?= e(url('billing/checkout/problem_2')) ?>">شراء والدفع</a>
            <a class="btn btn-ghost" href="<?= e(url('plans')) ?>">عرض كل الباقات</a>
          </div>
        <?php endif; ?>
      <?php endif; ?>
    </div>

    <?php if (!empty($quota_error)): ?><div class="ai-ask-alert is-warning"><?= e($quota_error) ?> <a href="<?= e(url('search')) ?>">استخدم البحث العادي</a></div><?php endif; ?>
    <?php if (!empty($error)): ?><div class="ai-ask-alert is-error"><?= e($error) ?></div><?php endif; ?>
    <?php if (!$configured): ?><div class="ai-ask-alert is-warning">مساعد AI غير مهيأ حاليًا. البحث العادي ودليل الإجراءات يعملان بصورة مستقلة.</div><?php endif; ?>

    <?php if (!empty($service_pulse) && empty($incident)): $sp=(array)$service_pulse; $spStatus=(string)($sp['status'] ?? 'unknown'); ?>
      <section class="service-pulse-card ai-preflight-pulse is-<?= e($spStatus) ?>">
        <div class="service-pulse-icon" aria-hidden="true"><span></span></div>
        <div class="service-pulse-main">
          <div class="service-pulse-head"><span class="eyebrow">فحص قبل التشخيص</span><span class="service-pulse-status"><?= e((string)($sp['label'] ?? 'حالة الخدمة')) ?></span></div>
          <p><?= e((string)($sp['summary'] ?? '')) ?></p>
          <div class="service-pulse-meta"><?php if (!empty($sp['last_scanned_at'])): ?><span>آخر فحص: <?= e((string)$sp['last_scanned_at']) ?></span><?php endif; ?><span>لا يخصم من رصيد AI</span></div>
          <?php if ($spStatus==='confirmed' && !empty($sp['incident']['official_evidence_url'])): ?><a href="<?= e((string)$sp['incident']['official_evidence_url']) ?>" target="_blank" rel="noopener nofollow">فتح الدليل الرسمي ↗</a><?php endif; ?>
          <?php if ($spStatus==='suspected'): ?><small>هذه إشارة تشغيلية غير مؤكدة رسميًا؛ سنستمر بالتشخيص إذا كانت حالتك مختلفة.</small><?php endif; ?>
        </div>
      </section>
    <?php endif; ?>

    <form class="ai-ask-form" method="post" action="<?= e(url('ask-ai')) ?>" data-ai-problem-form>
      <?= csrf_field() ?>
      <?php if (!empty($paid_case['case_ref'])): ?><input type="hidden" name="case_ref" value="<?= e((string)$paid_case['case_ref']) ?>"><?php endif; ?>
      <label for="ai-question">ما المشكلة التي تواجهها؟</label>
      <div class="ai-ask-input-row">
        <textarea id="ai-question" name="question" rows="4" maxlength="700" required placeholder="مثال: نقلت السجل التجاري لكن التأمينات ما زالت تظهر باسم المالك السابق، ماذا أفعل؟"><?= e($question ?? '') ?></textarea>
        <button class="btn btn-primary" type="submit" <?= (!$configured || (!\Khatauat\Core\Auth::isOwner() && (int)$remaining<=0))?'disabled':'' ?>>حل المشكلة</button>
      </div>
      <small>لا ترسل رقم الهوية أو كلمة المرور أو رمز التحقق أو بيانات البطاقة. البحث العادي مجاني وغير محدود.</small>
    </form>

    <?php if (!empty($incident)): $inc=(array)$incident; ?>
      <section class="ai-live-incident <?= (($inc['status'] ?? '')==='confirmed')?'is-confirmed':'is-suspected' ?>">
        <div class="ai-live-incident-head">
          <span class="eyebrow">حالة خدمة محدثة</span>
          <strong><?= (($inc['status'] ?? '')==='confirmed')?'تنبيه موثق من حساب X رسمي':'بلاغات مستخدمين متعددة — غير مؤكدة رسميًا' ?></strong>
        </div>
        <h2><?= e((string)($inc['entity_name'] ?? 'المنصة الرسمية')) ?></h2>
        <p><?= e((string)($inc['issue_label'] ?? 'مشكلة تقنية')) ?></p>
        <div class="ai-live-incident-meta">
          <span>الثقة: <?= (int)($inc['confidence'] ?? 0) ?>%</span>
          <?php if (($inc['status'] ?? '')==='confirmed'): ?>
            <span>أدلة رسمية: <?= (int)($inc['official_evidence_count'] ?? 0) ?></span>
          <?php else: ?>
            <span>بلاغات عامة: <?= (int)($inc['user_report_count'] ?? 0) ?></span>
          <?php endif; ?>
          <?php if (!empty($inc['last_seen_at'])): ?><span>آخر رصد: <?= e((string)$inc['last_seen_at']) ?></span><?php endif; ?>
        </div>
        <?php if (($inc['status'] ?? '')==='confirmed' && !empty($inc['official_evidence_url'])): ?>
          <a href="<?= e((string)$inc['official_evidence_url']) ?>" target="_blank" rel="noopener nofollow">فتح التنبيه أو الرد الرسمي على X ↗</a>
        <?php else: ?>
          <small>هذه إشارة تشغيلية وليست إعلانًا رسميًا عن تعطل الخدمة. لا ننسب السبب للجهة بدون تأكيد رسمي.</small>
        <?php endif; ?>
      </section>
    <?php endif; ?>

    <?php if (!empty($answer)): ?>
      <article class="ai-answer-card <?= !empty($result_status)?'is-'.e((string)$result_status):'' ?>">
        <div class="ai-answer-head">
          <span>
            <?php if (($result_status ?? '')==='resolved'): ?>حل موثق
            <?php elseif (($result_status ?? '')==='temporary_official_solution'): ?>توجيه حديث من خدمة العملاء الرسمية
            <?php elseif (($result_status ?? '')==='incident_confirmed'): ?>تنبيه تشغيلي رسمي حديث
            <?php elseif (($result_status ?? '')==='incident_suspected'): ?>خلل محتمل — غير مؤكد رسميًا
            <?php elseif (($result_status ?? '')==='insufficient'): ?>المصادر لا تحسم المشكلة
            <?php else: ?>نحتاج معلومة إضافية للوصول للحل<?php endif; ?>
          </span>
          <?php if (!empty($used_ai)): ?><small><?= !empty($paid_case)?'تم احتساب رسالة من جلسة المشكلة':'تم احتساب استفسار AI' ?></small><?php else: ?><small>لم يُخصم من الرصيد</small><?php endif; ?>
        </div>
        <div class="ai-answer-text"><?= nl2br(e((string)$answer)) ?></div>

        <?php if (!empty($missing_information)): ?>
          <div class="ai-missing-info"><strong>ما الذي ينقص؟</strong><span><?= e((string)$missing_information) ?></span></div>
        <?php endif; ?>

        <?php if (!empty($evidence)): ?>
          <div class="ai-evidence-list"><strong>المصادر التي بُنيت عليها الإجابة</strong>
            <?php foreach((array)$evidence as $ev): ?>
              <a href="<?= e((string)($ev['url'] ?? '#')) ?>" target="_blank" rel="noopener nofollow"><?= e((string)($ev['label'] ?? 'مصدر رسمي')) ?> ↗</a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <?php if (!empty($matched_services)): ?>
          <div class="ai-related-services"><strong>الخدمة المرتبطة داخل خطوات</strong><?php foreach ((array)$matched_services as $s): ?><a href="<?= e(url('service/'.(string)$s['slug'])) ?>"><?= e((string)$s['name']) ?> ←</a><?php endforeach; ?></div>
        <?php endif; ?>
      </article>
    <?php endif; ?>

    <?php if (!empty($diagnostic_questions)): ?>
      <section class="ai-diagnostic-panel">
        <div class="ai-diagnostic-head">
          <span class="eyebrow">تشخيص موجه</span>
          <h2>أجب عن المعلومة التي تغيّر الإجراء التالي</h2>
          <p>لا نطرح أسئلة لمجرد استمرار المحادثة. هذه الأسئلة مرتبطة بالخدمة أو المشكلة وتساعد على تضييق الحل.</p>
        </div>
        <div class="ai-diagnostic-list">
          <?php foreach((array)$diagnostic_questions as $i=>$dq): ?>
            <article class="ai-diagnostic-question">
              <div class="ai-diagnostic-num"><?= (int)$i+1 ?></div>
              <div>
                <h3><?= e((string)($dq['question'] ?? '')) ?></h3>
                <?php if (!empty($dq['purpose'])): ?><small>لماذا نسأل؟ <?= e((string)$dq['purpose']) ?></small><?php endif; ?>
                <?php if (!empty($dq['options'])): ?>
                  <div class="ai-diagnostic-options">
                    <?php foreach((array)$dq['options'] as $option): ?>
                      <button type="button" data-diagnostic-answer data-question="<?= e((string)($dq['question'] ?? '')) ?>" data-answer="<?= e((string)$option) ?>"><?= e((string)$option) ?></button>
                    <?php endforeach; ?>
                  </div>
                <?php else: ?>
                  <button type="button" class="ai-diagnostic-free" data-diagnostic-free data-question="<?= e((string)($dq['question'] ?? '')) ?>">أجب عن هذا السؤال</button>
                <?php endif; ?>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      </section>
    <?php endif; ?>

    <?php if (!empty($contact)): $c=(array)$contact; $level=(string)($c['verification_level'] ?? 'official_site_only'); ?>
      <section class="ai-escalation-card">
        <div class="ai-escalation-copy">
          <span class="eyebrow">تصعيد رسمي عند الحاجة</span>
          <?php if ($level==='official_site_only'): ?>
            <h2>لا توجد لدينا قناة دعم تفصيلية موثقة لهذه الحالة بعد</h2>
            <p>لن نخمن رقمًا أو بريدًا. استخدم الموقع الرسمي لـ<?= e((string)($c['entity_name'] ?? 'الجهة')) ?>، وسنُكمل توثيق قنوات الدعم عندما تتوفر من مصدر رسمي واضح.</p>
          <?php else: ?>
            <h2>إذا كانت المشكلة تقنية أو غير موثقة، تواصل مع <?= e((string)($c['entity_name'] ?? 'الجهة الرسمية')) ?></h2>
            <p>لا نريد إعطاءك حلًا غير مؤكد. استخدم إحدى قنوات الجهة الرسمية أدناه.</p>
          <?php endif; ?>
          <div class="ai-contact-actions">
            <?php if (!empty($c['phone'])): ?><a class="btn btn-primary" href="tel:<?= e(preg_replace('/[^0-9+]/','',(string)$c['phone'])) ?>">اتصال: <?= e((string)$c['phone']) ?></a><?php endif; ?>
            <?php if (!empty($c['email'])): ?><a class="btn btn-soft" href="mailto:<?= e((string)$c['email']) ?>">البريد الرسمي</a><?php endif; ?>
            <?php if (!empty($c['support_url'])): ?><a class="btn btn-ghost" href="<?= e((string)$c['support_url']) ?>" target="_blank" rel="noopener nofollow"><?= $level==='official_site_only'?'فتح الموقع الرسمي':'الدعم الرسمي' ?> ↗</a><?php endif; ?>
          </div>
          <?php if (($c['support_scope'] ?? '')==='authority' && !empty($c['source_name']) && (string)$c['source_name']!==(string)($c['entity_name'] ?? '')): ?>
            <small class="ai-escalation-scope">قناة التصعيد المعروضة تتبع الجهة المالكة/المشرفة على <?= e((string)$c['source_name']) ?>.</small>
          <?php endif; ?>
        </div>

        <?php if (!empty($c['maps_enabled']) && (!empty($c['maps_query']) || !empty($c['centers']))): ?>
          <div class="ai-nearest-service" data-nearest-service
               data-map-query="<?= e((string)($c['maps_query'] ?? '')) ?>"
               data-centers='<?= e(json_encode((array)($c['centers'] ?? []),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)) ?>'>
            <h3>هل تحتاج التوجه إلى موقع للجهة؟</h3>
            <p>نطلب موقعك فقط عند الضغط ونستخدمه لحظيًا لفتح أقرب نتيجة في Google Maps. لا نخزن موقعك، ولا نحجز مواعيد.</p>
            <div class="ai-map-actions">
              <button class="btn btn-soft" type="button" data-find-nearest>استخدم موقعي في Google Maps</button>
              <span>أو</span>
              <input type="text" maxlength="80" placeholder="اكتب المدينة أو المنطقة" data-map-city>
              <button class="btn btn-ghost" type="button" data-open-city-map>بحث حسب المدينة</button>
            </div>
            <div class="ai-location-result" data-location-result hidden></div>
          </div>
        <?php elseif (!empty($c['branches_url'])): ?>
          <div class="ai-nearest-service"><h3>الفروع ومواقع الخدمة</h3><a href="<?= e((string)$c['branches_url']) ?>" target="_blank" rel="noopener nofollow">فتح دليل الفروع الرسمي ↗</a></div>
        <?php endif; ?>

        <?php if (!empty($c['source_url'])): ?><small class="ai-contact-source">مرجع بيانات التصعيد: <a href="<?= e((string)$c['source_url']) ?>" target="_blank" rel="noopener nofollow">المصدر الرسمي</a><?= !empty($c['verified_at'])?' — آخر تحقق: '.e((string)$c['verified_at']):'' ?></small><?php endif; ?>
      </section>
    <?php endif; ?>

    <?php if (empty($paid_case) && !\Khatauat\Core\Auth::isOwner() && (int)($case_balance ?? 0)>0): ?><form method="post" action="<?= e(url('billing/case/start')) ?>" class="ai-start-paid-case"><?= csrf_field() ?><div><strong>لديك رصيد لحل <?= (int)$case_balance ?> مشكلة</strong><small>ابدأ جلسة مستقلة عندما تريد متابعة مشكلة بعد انتهاء المجاني.</small></div><button class="btn btn-primary" type="submit">ابدأ مشكلة من الرصيد</button></form><?php endif; ?>

    <div class="ai-ask-disclaimer">«خطوات» منصة دلالية مستقلة وليست جهة حكومية. إذا لم توجد معلومة موثقة، سنقول ذلك بوضوح ونوجهك إلى الدعم الرسمي بدل اختراع حل.</div>
  </div>
</section>

<script>
(function(){
  const form=document.querySelector('[data-ai-problem-form]');
  const box=document.getElementById('ai-question');
  if(form&&box){
    document.querySelectorAll('[data-diagnostic-answer]').forEach(btn=>{
      btn.addEventListener('click',()=>{
        const q=btn.getAttribute('data-question')||'';
        const a=btn.getAttribute('data-answer')||'';
        const base=(box.value||'').trim();
        box.value=(base?base+'\n\n':'')+'إجابة على سؤال التشخيص «'+q+'»: '+a;
        box.focus();
        box.scrollIntoView({behavior:'smooth',block:'center'});
      });
    });
    document.querySelectorAll('[data-diagnostic-free]').forEach(btn=>{
      btn.addEventListener('click',()=>{
        const q=btn.getAttribute('data-question')||'';
        const base=(box.value||'').trim();
        box.value=(base?base+'\n\n':'')+'إجابة على سؤال التشخيص «'+q+'»: ';
        box.focus();
        box.scrollIntoView({behavior:'smooth',block:'center'});
      });
    });
  }

  function distanceKm(a,b,c,d){
    const R=6371,toRad=x=>x*Math.PI/180;
    const dLat=toRad(c-a),dLon=toRad(d-b);
    const x=Math.sin(dLat/2)**2+Math.cos(toRad(a))*Math.cos(toRad(c))*Math.sin(dLon/2)**2;
    return 2*R*Math.asin(Math.sqrt(x));
  }
  document.querySelectorAll('[data-nearest-service]').forEach(card=>{
    const btn=card.querySelector('[data-find-nearest]');
    const result=card.querySelector('[data-location-result]');
    const cityInput=card.querySelector('[data-map-city]');
    const cityBtn=card.querySelector('[data-open-city-map]');
    const query=card.getAttribute('data-map-query')||'';

    function openCity(){
      const city=(cityInput?.value||'').trim();
      if(!city){cityInput?.focus();return;}
      const href='https://www.google.com/maps/search/?api=1&query='+encodeURIComponent((query?query+' ':'')+city);
      window.open(href,'_blank','noopener');
    }
    cityBtn?.addEventListener('click',openCity);
    cityInput?.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();openCity();}});

    if(!btn||!result)return;
    btn.addEventListener('click',()=>{
      result.hidden=false;
      if(!navigator.geolocation){result.textContent='المتصفح لا يدعم تحديد الموقع. اكتب مدينتك في الحقل أعلاه.';return;}
      result.textContent='جارٍ تحديد موقعك…';
      navigator.geolocation.getCurrentPosition(pos=>{
        const lat=pos.coords.latitude,lng=pos.coords.longitude;
        let centers=[];
        try{centers=JSON.parse(card.getAttribute('data-centers')||'[]')}catch(e){}
        const valid=centers.filter(x=>Number.isFinite(Number(x.latitude))&&Number.isFinite(Number(x.longitude)));
        if(valid.length){
          valid.sort((x,y)=>distanceKm(lat,lng,+x.latitude,+x.longitude)-distanceKm(lat,lng,+y.latitude,+y.longitude));
          const c=valid[0],km=distanceKm(lat,lng,+c.latitude,+c.longitude).toFixed(1);
          const href=c.google_maps_url||('https://www.google.com/maps/search/?api=1&query='+encodeURIComponent(c.latitude+','+c.longitude));
          result.replaceChildren();
          const strong=document.createElement('strong'); strong.textContent=String(c.name||'أقرب موقع خدمة');
          const meta=document.createElement('span'); meta.textContent=String(c.city||'')+' — نحو '+km+' كم';
          const link=document.createElement('a'); link.target='_blank'; link.rel='noopener nofollow'; link.href=href; link.textContent='فتح الموقع في Google Maps ↗';
          result.append(strong,meta,link);
          return;
        }
        if(query){
          const href='https://www.google.com/maps/search/'+encodeURIComponent(query)+'/@'+lat+','+lng+',13z';
          result.replaceChildren();
          const note=document.createElement('span'); note.textContent='سنفتح بحث Google Maps حول موقعك الحالي. النتيجة من Google Maps وليست حجزًا أو موعدًا من خطوات.';
          const link=document.createElement('a'); link.target='_blank'; link.rel='noopener nofollow'; link.href=href; link.textContent='فتح النتائج القريبة في Google Maps ↗';
          result.append(note,link);
        }else{
          result.textContent='لا توجد بيانات موقع موثقة كافية لهذه الجهة حاليًا.';
        }
      },()=>{result.textContent='لم يتم السماح باستخدام الموقع. اكتب مدينتك أو منطقتك في الحقل أعلاه لفتح Google Maps.';},{enableHighAccuracy:false,timeout:8000,maximumAge:300000});
    });
  });
})();
</script>
