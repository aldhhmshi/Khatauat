<?php
$owner=\Khatauat\Core\Auth::user() ?? [];
$ownerName=trim((string)($owner['name'] ?? 'مالك المنصة')) ?: 'مالك المنصة';
$sourceCoverage=(int)($stats['source_registry'] ?? 0);
$verifiedX=(int)($stats['verified_x'] ?? 0);
$incidentCount=(int)($stats['incidents'] ?? 0);
$maxMetric=max(1,(int)$stats['published'],(int)$stats['drafts'],(int)$stats['reports'],(int)$stats['ai_drafts'],$sourceCoverage,$verifiedX);
?>
<section class="admin-wrap v216-owner-page"><div class="container admin-layout v216-owner-layout">
<?php require root_path('resources/views/partials/admin_nav.php'); ?>
<div class="admin-content v216-owner-content">
  <div class="owner-mobile-bar"><button type="button" data-owner-sidebar-open>☰</button><div><small>لوحة المالك</small><strong>خطوات رقمية</strong></div><a href="<?= e(url('')) ?>" target="_blank" rel="noopener">الموقع ↗</a></div>

  <header class="v216-owner-head" data-reveal>
    <div><small>مرحبًا بك 👋</small><h1>نظرة عامة</h1><p>إدارة المحتوى والمصادر والذكاء التشغيلي من واجهة واحدة واضحة.</p></div>
    <div class="v216-owner-actions"><a class="v216-ai-cta" href="<?= e(url('admin/ai-drafts')) ?>">✦ إنشاء محتوى بالذكاء الاصطناعي</a><a class="v216-icon-btn" href="<?= e(url('admin/settings')) ?>">⚙</a></div>
  </header>

  <section class="v216-owner-status" data-reveal>
    <div><span class="<?= $incidentCount>0?'is-warn':'is-good' ?>"></span><div><small>حالة التشغيل</small><strong><?= $incidentCount>0?'توجد مؤشرات تحتاج مراجعة':'المنصة تعمل بصورة مستقرة' ?></strong></div></div>
    <p><?= $incidentCount>0?number_format($incidentCount).' تنبيه خدمة نشط يحتاج قرارك.':'آخر بيانات المراقبة لا تعرض حادثة خدمة نشطة.' ?></p>
  </section>

  <div class="v216-owner-kpis">
    <a href="<?= e(url('admin/services')) ?>" data-reveal><span>إجراءات منشورة</span><strong><?= number_format((int)$stats['published']) ?></strong><small>متاحة للمستخدمين</small><i>▣</i></a>
    <a href="<?= e(url('admin/ai-drafts')) ?>" data-reveal><span>مسودات AI</span><strong><?= number_format((int)$stats['ai_drafts']) ?></strong><small>بانتظار المراجعة</small><i>✦</i></a>
    <a href="<?= e(url('admin/source-registry')) ?>" data-reveal><span>المصادر النشطة</span><strong><?= number_format($sourceCoverage) ?></strong><small><?= number_format($verifiedX) ?> حساب X موثّق</small><i>◇</i></a>
    <a href="<?= e(url('admin/trust-reports')) ?>" data-reveal><span>تحتاج قرارك</span><strong><?= number_format((int)$stats['reports']) ?></strong><small>مراجعات وبلاغات</small><i>!</i></a>
  </div>

  <div class="v216-owner-main">
    <section class="v216-owner-panel v216-activity-panel" data-reveal>
      <div class="v216-panel-head"><div><small>مؤشرات التشغيل</small><h2>صورة سريعة عن المنصة</h2></div><a href="<?= e(url('admin/analytics')) ?>">فتح التحليلات ←</a></div>
      <div class="v216-metric-bars">
        <?php $metrics=[['إجراءات',$stats['published']],['مسودات',$stats['drafts']],['مصادر',$sourceCoverage],['مراجعات',$stats['reports']],['AI',$stats['ai_drafts']],['X',$verifiedX]]; foreach($metrics as $m): $pct=max(6,(int)round(((int)$m[1]/$maxMetric)*100)); ?>
          <div><span style="--h:<?= $pct ?>%"><i></i></span><b><?= e((string)$m[0]) ?></b><small><?= number_format((int)$m[1]) ?></small></div>
        <?php endforeach; ?>
      </div>
      <div class="v216-panel-foot"><span>هذه مؤشرات تشغيل فعلية من قاعدة المنصة وليست أرقامًا تجريبية.</span></div>
    </section>

    <section class="v216-owner-ai" data-reveal>
      <span class="v216-owner-ai-icon">✦</span><h2>مساعد خطوات AI</h2><p>حوّل المصدر الرسمي إلى مسودة قابلة للمراجعة، واقترح كلمات مفتاحية وتحسينات دون نشر تلقائي.</p>
      <ul><li>اقتراح كلمات مفتاحية</li><li>تحسين SEO للمحتوى</li><li>حفظ النتائج كمسودة</li></ul>
      <a href="<?= e(url('admin/ai-drafts')) ?>">ابدأ التوليد ←</a>
    </section>

    <section class="v216-owner-panel v216-quick-panel" data-reveal>
      <div class="v216-panel-head"><div><small>إعدادات سريعة</small><h2>الوصول للأهم</h2></div></div>
      <div class="v216-quick-list">
        <a href="<?= e(url('admin/analytics')) ?>"><span>التحليلات</span><b>↗</b></a>
        <a href="<?= e(url('admin/integrations')) ?>"><span>التكاملات</span><b>↗</b></a>
        <a href="<?= e(url('admin/appearance')) ?>"><span>الهوية والمظهر</span><b>↗</b></a>
        <a href="<?= e(url('admin/marketing')) ?>"><span>التسويق AI</span><b>↗</b></a>
        <a href="<?= e(url('admin/ads')) ?>"><span>الإعلانات والبانر</span><b>↗</b></a>
        <a href="<?= e(url('admin/ai-ops')) ?>"><span>عمليات AI</span><b>↗</b></a>
      </div>
    </section>
  </div>

  <div class="v216-owner-lower">
    <section class="v216-owner-panel" data-reveal><div class="v216-panel-head"><div><small>الأولوية الآن</small><h2>ما يحتاج قرارك</h2></div><a href="<?= e(url('admin/trust-reports')) ?>">عرض الكل ←</a></div>
      <div class="v216-review-list">
        <?php if(!$recentReports && !$recentIncidents): ?><div class="v216-empty"><b>✓</b><strong>لا توجد عناصر عاجلة</strong><span>ستظهر هنا الحالات التي تحتاج تدخلك.</span></div><?php endif; ?>
        <?php foreach($recentIncidents as $i): ?><article><span class="v216-review-icon is-warn">!</span><div><small><?= ($i['status']??'')==='confirmed'?'تنبيه مؤكد':'خلل محتمل' ?></small><strong><?= e($i['title']??'مؤشر خدمة') ?></strong><p>الثقة <?= (int)($i['confidence']??0) ?>% • <?= e(substr((string)($i['detected_at']??''),0,16)) ?></p></div></article><?php endforeach; ?>
        <?php foreach(array_slice($recentReports,0,4) as $r): ?><article><span class="v216-review-icon">?</span><div><small>بلاغ ثقة</small><strong><?= e($r['service_name']) ?></strong><p><?= e(excerpt($r['message'],95)) ?></p></div></article><?php endforeach; ?>
      </div>
    </section>
    <section class="v216-owner-panel" data-reveal><div class="v216-panel-head"><div><small>المصادر</small><h2>آخر عمليات التحقق</h2></div><a href="<?= e(url('admin/sources')) ?>">المراقبة ←</a></div><div class="v216-source-list"><?php if(!$recentChecks): ?><div class="v216-empty"><strong>لا توجد فحوص حديثة</strong></div><?php endif; ?><?php foreach(array_slice($recentChecks,0,5) as $c): ?><article><span class="<?= ($c['status']??'')==='ok'?'is-good':'is-warn' ?>"></span><div><strong><?= e($c['source_title']) ?></strong><small><?= e(substr((string)$c['checked_at'],0,16)) ?></small></div><b><?= e($c['status']??'فحص') ?></b></article><?php endforeach; ?></div></section>
  </div>
</div></div></section>
