<?php
$p=current_url_path();
$active=fn(string $prefix):string=>str_starts_with($p,$prefix)?'is-active':'';
$owner=\Khatauat\Core\Auth::user() ?? [];
$ownerName=trim((string)($owner['name'] ?? 'مالك المنصة')) ?: 'مالك المنصة';
$ownerEmail=trim((string)($owner['email'] ?? ''));
$proceduresActive=str_starts_with($p,'/admin/services') || str_starts_with($p,'/admin/service');
$settingsRoutes=['/admin/settings','/admin/appearance','/admin/analytics','/admin/integrations','/admin/marketing','/admin/ads','/admin/ai-ops'];
$settingsActive=false; foreach($settingsRoutes as $r){ if(str_starts_with($p,$r)){ $settingsActive=true; break; } }
?>
<aside class="owner-sidebar v215-owner-sidebar" data-owner-sidebar>
  <div class="owner-sidebar-top">
    <a class="owner-brand" href="<?= e(url('admin')) ?>" aria-label="لوحة المالك">
      <span class="owner-brand-mark v216-owner-mark"><i></i><i></i><i></i></span>
      <span><small>مساحة الإدارة</small><strong>خطوات رقمية</strong></span>
    </a>
    <button type="button" class="owner-sidebar-close" data-owner-sidebar-close aria-label="إغلاق القائمة">×</button>
  </div>

  <div class="owner-profile-card">
    <span class="owner-avatar"><?= e(mb_substr($ownerName,0,1)) ?></span>
    <div><strong><?= e($ownerName) ?></strong><?php if($ownerEmail): ?><small><?= e($ownerEmail) ?></small><?php else: ?><small>صلاحية المالك</small><?php endif; ?></div>
    <span class="owner-live-dot" title="وضع المالك نشط"></span>
  </div>

  <nav class="owner-nav" aria-label="أقسام لوحة المالك">
    <div class="owner-nav-group"><span class="owner-nav-label">مساحة الإدارة</span>
      <a class="<?= $p==='/admin'?'is-active':'' ?>" href="<?= e(url('admin')) ?>"><i>⌂</i><span>الرئيسية</span></a>
      <a class="<?= $active('/admin/trust-reports') ?>" href="<?= e(url('admin/trust-reports')) ?>"><i>✓</i><span>مراجعات تحتاج قرارك</span></a>
    </div>

    <div class="owner-nav-group"><span class="owner-nav-label">المحتوى والدليل</span>
      <a class="<?= $proceduresActive?'is-active':'' ?>" href="<?= e(url('admin/services')) ?>"><i>↝</i><span>دليل الإجراءات</span></a>
      <a class="<?= $active('/admin/content') ?>" href="<?= e(url('admin/content')) ?>"><i>▣</i><span>المحتوى والتحديثات</span></a>
      <a class="<?= $active('/admin/ai-drafts') ?>" href="<?= e(url('admin/ai-drafts')) ?>"><i>✦</i><span>مسودات الذكاء</span></a>
    </div>

    <div class="owner-nav-group"><span class="owner-nav-label">الثقة والمصادر</span>
      <a class="<?= $active('/admin/sources') ?>" href="<?= e(url('admin/sources')) ?>"><i>↻</i><span>مراقبة المصادر</span></a>
      <a class="<?= $active('/admin/source-registry') ?>" href="<?= e(url('admin/source-registry')) ?>"><i>⌁</i><span>السجل الوطني للمصادر</span></a>
    </div>

    <div class="owner-nav-group owner-settings-group <?= $settingsActive?'is-open':'' ?>" data-owner-settings-group>
      <span class="owner-nav-label">النظام</span>
      <button class="owner-settings-toggle <?= $settingsActive?'is-active':'' ?>" type="button" data-owner-settings-toggle aria-expanded="<?= $settingsActive?'true':'false' ?>"><i>⚙</i><span>الإعدادات</span><b>⌄</b></button>
      <div class="owner-nav-sub" data-owner-settings-sub>
        <a class="<?= $p==='/admin/settings'?'is-active':'' ?>" href="<?= e(url('admin/settings')) ?>"><span>الإعدادات العامة</span></a>
        <a class="<?= $active('/admin/appearance') ?>" href="<?= e(url('admin/appearance')) ?>"><span>الهوية والمظهر</span></a>
        <a class="<?= $active('/admin/analytics') ?>" href="<?= e(url('admin/analytics')) ?>"><span>التحليلات</span></a>
        <a class="<?= $active('/admin/integrations') ?>" href="<?= e(url('admin/integrations')) ?>"><span>التكاملات</span></a>
        <a class="<?= $active('/admin/marketing') ?>" href="<?= e(url('admin/marketing')) ?>"><span>التسويق AI</span></a>
        <a class="<?= $active('/admin/ads') ?>" href="<?= e(url('admin/ads')) ?>"><span>الإعلانات والبانر</span></a>
        <a class="<?= $active('/admin/ai-ops') ?>" href="<?= e(url('admin/ai-ops')) ?>"><span>عمليات AI</span></a>
      </div>
    </div>
  </nav>

  <div class="owner-sidebar-footer">
    <a class="owner-public-link" href="<?= e(url('')) ?>" target="_blank" rel="noopener"><span>عرض الموقع العام</span><b>↗</b></a>
    <form action="<?= e(url('logout')) ?>" method="post"><?= csrf_field() ?><button type="submit" class="owner-logout"><span>تسجيل الخروج</span><b>⇥</b></button></form>
  </div>
</aside>
