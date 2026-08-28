<section class="admin-wrap v143-admin-page ops-admin">
  <div class="container admin-layout">
    <?php require root_path('resources/views/partials/admin_nav.php'); ?>
    <div class="admin-content"><?php require root_path('resources/views/partials/settings_tabs.php'); ?>
      <header class="v143-admin-head">
        <div>
          <span class="eyebrow">AI Marketing Studio</span>
          <h1>مركز التسويق بالذكاء الاصطناعي</h1>
          <p>حوّل هدفًا واحدًا إلى استراتيجية وحزمة محتوى متعددة المنصات، ثم اعتمد وجدول المواد عبر موصل نشر آمن.</p>
        </div>
        <a class="btn btn-soft" href="<?= e(url('admin/integrations')) ?>">إدارة ربط المنصات</a>
      </header>

      <div class="marketing-flow-strip">
        <span><b>1</b> هدف الحملة</span><i>←</i><span><b>2</b> AI يصيغ لكل منصة</span><i>←</i><span><b>3</b> مراجعة واعتماد</span><i>←</i><span><b>4</b> جدولة/موصل نشر</span><i>←</i><span><b>5</b> قياس وتحسين</span>
      </div>

      <div class="ops-grid">
        <section class="v143-panel ops-command">
          <div class="v143-panel-head"><div><h2>إنشاء حملة</h2><p>ينشئ AI استراتيجية ونسخًا حسب طبيعة كل منصة.</p></div></div>
          <form method="post" action="<?= e(url('admin/marketing/generate')) ?>" class="ops-form">
            <?= csrf_field() ?>
            <label>اسم الحملة<input name="name" required placeholder="مثال: كل إجراء في مكان واحد"></label>
            <label>الهدف<input name="objective" required placeholder="زيارات مؤهلة / تسجيل / عودة المستخدم"></label>
            <label>الجمهور<input name="audience" required placeholder="أصحاب المنشآت، الأفراد، المقيمون..."></label>
            <label>العرض أو الرسالة<textarea name="offer_text" rows="4" placeholder="ما القيمة التي نريد إبرازها؟"></textarea></label>
            <button class="btn btn-primary">توليد الحملة بالـAI</button>
          </form>
        </section>
        <section class="v143-panel">
          <div class="v143-panel-head"><div><h2>الحملات</h2><p>حالة كل حزمة تسويقية.</p></div></div>
          <?php if(!$campaigns): ?><div class="empty-state">لا توجد حملات بعد.</div><?php endif; ?>
          <?php foreach($campaigns as $c): ?><div class="campaign-row"><div><small><?= e($c['status']) ?></small><b><?= e($c['name']) ?></b><p><?= e(excerpt((string)$c['objective'],90)) ?></p></div><strong><?= (int)$c['assets_count'] ?><small> مادة</small></strong></div><?php endforeach; ?>
        </section>
      </div>

      <section class="v143-panel top-gap">
        <div class="v143-panel-head">
          <div><h2>مكتبة المحتوى متعددة المنصات</h2><p>الموافقة منفصلة عن النشر. لا تُرسل أي مادة خارج الموقع قبل اعتمادها.</p></div>
          <?php if($publishBridge): ?><span class="integration-state state-<?= e($publishBridge['status']) ?>">موصل النشر: <?= e($publishBridge['status']) ?></span><?php endif; ?>
        </div>
        <div class="marketing-assets">
          <?php foreach($assets as $a): ?>
            <article>
              <div class="asset-head"><span><?= e($a['platform_key']) ?></span><span class="badge <?= in_array($a['status'],['approved','published'],true)?'badge-success':'badge-warn' ?>"><?= e($a['status']) ?></span></div>
              <small><?= e($a['campaign_name']) ?> · <?= e($a['asset_type']) ?></small>
              <h3><?= e($a['title']?:'بدون عنوان') ?></h3>
              <p><?= nl2br(e($a['content'])) ?></p>
              <?php if($a['cta']): ?><b class="asset-cta">CTA: <?= e($a['cta']) ?></b><?php endif; ?>
              <?php if($a['hashtags']): ?><small class="asset-tags"><?= e($a['hashtags']) ?></small><?php endif; ?>
              <?php if($a['publication_status']): ?><div class="publish-state"><b>حالة الإرسال:</b> <?= e($a['publication_status']) ?><?php if($a['publication_error']): ?><small><?= e($a['publication_error']) ?></small><?php endif; ?></div><?php endif; ?>
              <div class="actions marketing-actions">
                <?php if($a['status']==='draft'): ?>
                  <form method="post" action="<?= e(url('admin/marketing/asset-status')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><input type="hidden" name="status" value="approved"><button class="btn btn-primary btn-sm">اعتماد</button></form>
                  <form method="post" action="<?= e(url('admin/marketing/asset-status')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><input type="hidden" name="status" value="rejected"><button class="btn btn-soft btn-sm">رفض</button></form>
                <?php elseif($a['status']==='approved'): ?>
                  <form method="post" action="<?= e(url('admin/marketing/publish')) ?>" class="publish-form"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><label>موعد اختياري<input type="datetime-local" name="scheduled_at"></label><button class="btn btn-primary btn-sm">إرسال / جدولة</button></form>
                  <form method="post" action="<?= e(url('admin/marketing/asset-status')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><input type="hidden" name="status" value="draft"><button class="btn btn-soft btn-sm">إعادة للمراجعة</button></form>
                <?php endif; ?>
              </div>
            </article>
          <?php endforeach; ?>
          <?php if(!$assets): ?><div class="empty-state">ستظهر هنا مواد Instagram وTikTok وSnapchat وX وLinkedIn وYouTube وGoogle Ads وWhatsApp وFacebook.</div><?php endif; ?>
        </div>
      </section>
      <div class="ops-note"><b>النشر الخارجي:</b> يمكن للموقع إرسال المادة المعتمدة إلى n8n/Make أو عامل تكامل خاص. كل منصة تبقى خاضعة لصلاحيات OAuth وسياسات النشر الخاصة بها، ولا يُعتبر الإرسال تأكيدًا للنشر إلا إذا أعاد الموصل تأكيدًا صريحًا.</div>
    </div>
  </div>
</section>
