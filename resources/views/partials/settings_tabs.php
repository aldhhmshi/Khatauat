<?php
$settingsPath=current_url_path();
$tabs=[
 ['path'=>'admin/settings','prefix'=>'/admin/settings','label'=>'عام'],
 ['path'=>'admin/appearance','prefix'=>'/admin/appearance','label'=>'الهوية والمظهر'],
 ['path'=>'admin/analytics','prefix'=>'/admin/analytics','label'=>'التحليلات'],
 ['path'=>'admin/integrations','prefix'=>'/admin/integrations','label'=>'التكاملات'],
 ['path'=>'admin/marketing','prefix'=>'/admin/marketing','label'=>'التسويق AI'],
 ['path'=>'admin/ads','prefix'=>'/admin/ads','label'=>'الإعلانات والبانر'],
 ['path'=>'admin/ai-ops','prefix'=>'/admin/ai-ops','label'=>'عمليات AI'],
 ['path'=>'admin/billing','prefix'=>'/admin/billing','label'=>'الفوترة والباقات'],
];
?>
<nav class="owner-settings-tabs" aria-label="صفحات الإعدادات">
  <div class="owner-settings-tabs-head"><span>إعدادات المنصة</span><small>كل أدوات التشغيل والنمو في مكان واحد</small></div>
  <div class="owner-settings-tabs-scroll">
    <?php foreach($tabs as $tab): $on=str_starts_with($settingsPath,$tab['prefix']); ?>
      <a class="<?= $on?'is-current':'' ?>" href="<?= e(url($tab['path'])) ?>"><?= e($tab['label']) ?></a>
    <?php endforeach; ?>
  </div>
</nav>
