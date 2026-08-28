<section class="admin-wrap v143-admin-page ops-admin">
  <div class="container admin-layout">
    <?php require root_path('resources/views/partials/admin_nav.php'); ?>
    <div class="admin-content"><?php require root_path('resources/views/partials/settings_tabs.php'); ?>
      <header class="v143-admin-head">
        <div>
          <span class="eyebrow">Connected Growth Stack</span>
          <h1>التكاملات والمنصات</h1>
          <p>اربط البحث والقياس والتسويق والنشر والذكاء الاصطناعي. المفاتيح السرية لا تُحفظ في SQLite؛ توضع في متغيرات البيئة.</p>
        </div>
      </header>

      <div class="integration-grid">
        <?php foreach($items as $item):
          $secrets=json_decode((string)$item['secret_env_keys'],true); if(!is_array($secrets))$secrets=[];
          $caps=json_decode((string)$item['capabilities_json'],true); if(!is_array($caps))$caps=[];
          $cfg=json_decode((string)$item['public_config_json'],true); if(!is_array($cfg))$cfg=[];
        ?>
          <article class="integration-card">
            <div class="integration-top">
              <span class="integration-mark"><?= e(mb_substr($item['name'],0,1)) ?></span>
              <div><small><?= e($item['category']) ?></small><h2><?= e($item['name']) ?></h2></div>
              <span class="integration-state state-<?= e($item['status']) ?>"><?= e($item['status']) ?></span>
            </div>
            <div class="chip-row"><?php foreach($caps as $c): ?><span><?= e((string)$c) ?></span><?php endforeach; ?></div>
            <?php if($secrets): ?><p class="integration-secrets"><b>متغيرات الخادم:</b> <?= e(implode(' · ',$secrets)) ?></p><?php endif; ?>
            <form method="post" action="<?= e(url('admin/integrations/save')) ?>" class="integration-form">
              <?= csrf_field() ?>
              <input type="hidden" name="provider_key" value="<?= e($item['provider_key']) ?>">
              <label class="switch-line"><input type="checkbox" name="enabled" <?= (int)$item['enabled']===1?'checked':'' ?>><span>تفعيل التكامل داخل خطوات</span></label>
              <?php if($item['provider_key']==='openai'): ?>
                <label>اسم النموذج<input name="openai_model" value="<?= e($openaiModel) ?>" placeholder="أدخل اسم نموذج متاح في حساب API"></label>
                <label>Responses API<input value="https://api.openai.com/v1/responses" readonly aria-readonly="true"></label><p class="integration-help">المسار مثبت على النطاق الرسمي لـ OpenAI لحماية مفتاح API من التحويل إلى وجهة غير موثوقة.</p>
              <?php elseif($item['provider_key']==='automation_webhook'): ?>
                <label>رابط موصل النشر HTTPS<input type="url" name="endpoint_url" value="<?= e((string)($cfg['endpoint_url']??'')) ?>" placeholder="https://.../webhook/khatauat"></label>
                <label>اسم سير العمل<input name="account_label" value="<?= e((string)($cfg['account_label']??'')) ?>" placeholder="مثال: n8n Social Publisher"></label>
                <p class="integration-help">يُرسل الموقع المواد المعتمدة فقط، مع توقيع HMAC في <code>X-Khatauat-Signature</code>. ضع المفتاح في <code>AUTOMATION_WEBHOOK_SECRET</code>.</p>
              <?php else: ?>
                <label>اسم الحساب/الملكية<input name="account_label" value="<?= e((string)($cfg['account_label']??'')) ?>" placeholder="اختياري"></label>
                <label>معرف عام غير سري<input name="property_id" value="<?= e((string)($cfg['property_id']??'')) ?>" placeholder="اختياري"></label>
              <?php endif; ?>
              <button class="btn btn-soft btn-sm">حفظ وفحص الحالة</button>
            </form>
            <?php if($item['notes']): ?><small class="integration-note"><?= e($item['notes']) ?></small><?php endif; ?>
          </article>
        <?php endforeach; ?>
      </div>
      <div class="ops-note"><b>حدود الصلاحية:</b> لا ينشئ الموقع حسابات خارجية ولا يتجاوز موافقات OAuth أو سياسات المنصات. حالة «configured» تعني اكتمال الإعداد المحلي فقط؛ أما النشر الفعلي فيحتاج اعتماد الحسابات والصلاحيات لدى المزود.</div>
    </div>
  </div>
</section>
