<?php
$heroEnabled=(string)setting('home_hero_enabled','1')==='1';
$statsEnabled=(string)setting('home_stats_enabled','1')==='1';
$servicesEnabled=(string)setting('home_services_enabled','1')==='1';
$updatesEnabled=(string)setting('home_updates_enabled','1')==='1';
$articlesEnabled=(string)setting('home_articles_enabled','1')==='1';
$calculatorsEnabled=(string)setting('home_calculators_enabled','1')==='1';
$customBlocks=json_decode((string)setting('home_custom_blocks','[]'),true); if(!is_array($customBlocks))$customBlocks=[];
$quickSearch=['تجديد الإقامة','إصدار رخصة بلدية','فتح مؤسسة','نقل ملكية مركبة'];
$sectorFallback=[
 ['name'=>'خدمات الأفراد','desc'=>'الهوية، الجوازات، المركبات والمزيد','slug'=>'individuals','tone'=>'mint','icon'=>'◎'],
 ['name'=>'خدمات الأعمال','desc'=>'التأسيس، التراخيص والالتزامات','slug'=>'business','tone'=>'warm','icon'=>'▣'],
 ['name'=>'الخدمات البلدية','desc'=>'الرخص، الشهادات والاستعلامات','slug'=>'municipal','tone'=>'lilac','icon'=>'⌂'],
 ['name'=>'أدوات وحاسبات','desc'=>'نتائج فورية تساعدك على التخطيط','slug'=>'calculators','tone'=>'blue','icon'=>'▦'],
];
?>
<div class="v216-home">
<?php if($heroEnabled): ?>
<section class="v216-hero" aria-labelledby="v216-title">
  <div class="container v216-hero-grid">
    <div class="v216-hero-copy" data-reveal>
      <span class="v216-pill">✦ المعرفة التي تختصر عليك الطريق</span>
      <h1 id="v216-title">كل إجراء رقمي،<br><em>بخطوات أوضح.</em></h1>
      <p>دليلك العملي لفهم الإجراءات والخدمات في السعودية. نرتب لك المتطلبات والخطوات والجهات ذات العلاقة، ثم نأخذك إلى المصدر الرسمي في الوقت الصحيح.</p>
      <form action="<?= e(url('search')) ?>" method="get" class="v216-search">
        <span aria-hidden="true">⌕</span>
        <input type="search" name="q" placeholder="ابحث عن خدمة، إجراء أو منصة..." aria-label="البحث في خطوات">
        <button type="submit">ابحث الآن</button>
      </form>
      <div class="v216-quick-search"><b>الأكثر بحثًا:</b><?php foreach($quickSearch as $q): ?><a href="<?= e(url('search?q='.rawurlencode($q))) ?>"><?= e($q) ?></a><?php endforeach; ?></div>
    </div>
    <div class="v216-hero-visual" data-reveal>
      <div class="v216-visual-frame"><img src="<?= e(asset('img/v216-hero.webp')) ?>" alt="تصور بصري للخدمات الرقمية والمصادر الرسمية" loading="eager"></div>
      <div class="v216-float-card v216-float-source"><span>✓</span><div><strong>مصادر رسمية</strong><small>روابط مباشرة للجهات</small></div></div>
      <div class="v216-float-card v216-float-update"><span>●</span><div><strong>محدّث باستمرار</strong><small>آخر مراجعة: اليوم</small></div></div>
      <div class="v216-visual-badge"><span class="v216-mini-mark"><i></i><i></i><i></i></span><div><strong><?= number_format((int)$stats['sources']) ?>+</strong><small>مصدر ومنصة في السجل</small></div></div>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if($servicesEnabled): ?>
<section class="v216-section v216-sectors"><div class="container">
  <div class="v216-section-head" data-reveal><div><span>ابدأ من احتياجك</span><h2>ماذا تريد أن تنجز اليوم؟</h2></div><a href="<?= e(url('procedures')) ?>">عرض جميع الإجراءات ←</a></div>
  <div class="v216-sector-grid">
    <?php $shown=0; foreach($categories as $cat): if($shown>=4)break; $shown++; $tones=['mint','warm','lilac','blue']; $tone=$tones[($shown-1)%4]; ?>
      <a class="v216-sector-card is-<?= e($tone) ?>" data-reveal href="<?= e(url('procedures?category='.rawurlencode((string)$cat['slug']))) ?>">
        <span class="v216-sector-no">0<?= $shown ?></span><div><strong><?= e($cat['name']) ?></strong><small><?= (int)$cat['services_count'] ?> إجراء متاح</small></div><b>←</b>
      </a>
    <?php endforeach; ?>
    <?php for($i=$shown;$i<4;$i++): $x=$sectorFallback[$i]; ?>
      <a class="v216-sector-card is-<?= e($x['tone']) ?>" data-reveal href="<?= e($x['slug']==='calculators'?url('calculators'):url('procedures')) ?>"><span class="v216-sector-no">0<?= $i+1 ?></span><div><strong><?= e($x['name']) ?></strong><small><?= e($x['desc']) ?></small></div><b>←</b></a>
    <?php endfor; ?>
  </div>
</div></section>
<?php endif; ?>

<section class="v216-section v216-trust"><div class="container">
  <div class="v216-trust-card" data-reveal>
    <div class="v216-trust-copy"><span class="v216-dark-pill">الشفافية أولًا ◇</span><h2>لا نطلب منك أن تثق بنا.<br><em>نأخذك إلى المصدر.</em></h2><p>كل دليل يوضح الجهة الرسمية، رابط التنفيذ، وتاريخ آخر مراجعة. وإذا لم نجد معلومة موثقة، نقول ذلك بوضوح بدل التخمين.</p><a href="<?= e(url('procedures')) ?>">تصفح دليل الإجراءات ←</a></div>
    <div class="v216-source-stack">
      <?php $examples=array_slice($journeySteps?:[],0,3); if($examples): foreach($examples as $i=>$step): ?><article><span>0<?= $i+1 ?></span><div><strong><?= e($step['entity']?:$step['platform']?:'جهة رسمية') ?></strong><small><?= e($step['title']) ?></small></div><b>✓</b></article><?php endforeach; else: ?>
      <article><span>01</span><div><strong>المنصة الوطنية الموحدة</strong><small>مرجع مركزي للخدمات الحكومية</small></div><b>✓</b></article><article><span>02</span><div><strong>الجهة المالكة للخدمة</strong><small>المتطلبات والإجراءات الرسمية</small></div><b>✓</b></article><article><span>03</span><div><strong>منصة التنفيذ</strong><small>الرابط الذي تنفذ منه فعليًا</small></div><b>✓</b></article>
      <?php endif; ?>
    </div>
  </div>
</div></section>

<?php /* KHATAUAT_HOME_AD_SLOT_V1 */ ?>
<?php render_ad_slot('home_mid'); ?>

<?php if($articlesEnabled): ?>
<section class="v216-section v216-reading"><div class="container">
  <div class="v216-section-head" data-reveal><div><span>مختارات المحرر</span><h2>اقرأ، افهم، ثم أنجز.</h2></div><a href="<?= e(url('blog')) ?>">كل المقالات ←</a></div>
  <div class="v216-article-grid">
    <?php foreach(array_slice($articles,0,3) as $i=>$a): ?><a class="v216-article-card" data-reveal href="<?= e(url('article/'.$a['slug'])) ?>"><span class="v216-article-tag"><?= $i===0?'دليل محدث':($i===1?'الأكثر قراءة':'جديد') ?></span><div class="v216-article-art"><span class="v216-mini-mark"><i></i><i></i><i></i></span></div><h3><?= e($a['title']) ?></h3><p><?= e(excerpt($a['summary'],120)) ?></p><b>اقرأ المقال ←</b></a><?php endforeach; ?>
    <?php if(!$articles): ?><article class="v216-article-card is-empty"><span class="v216-article-tag">قريبًا</span><div class="v216-article-art"><span class="v216-mini-mark"><i></i><i></i><i></i></span></div><h3>محتوى يشرح قبل أن تنفذ</h3><p>ستظهر هنا المقالات المعتمدة من لوحة المالك.</p></article><?php endif; ?>
  </div>
</div></section>
<?php endif; ?>

<section class="v216-section v216-tools"><div class="container">
  <div class="v216-section-head" data-reveal><div><span>أدوات عملية</span><h2>قرارات أسرع، بخطوات أقل.</h2></div></div>
  <div class="v216-tool-grid">
    <?php if($calculatorsEnabled): ?><a data-reveal href="<?= e(url('calculators')) ?>"><i>▦</i><div><strong>الحاسبات</strong><small>احسب قبل أن تقرر</small></div><b>←</b></a><?php endif; ?>
    <?php if($updatesEnabled): ?><a data-reveal href="<?= e(url('updates')) ?>"><i>↻</i><div><strong>التحديثات</strong><small>اعرف ما تغيّر وأثره عليك</small></div><b>←</b></a><?php endif; ?>
    <?php if(\Khatauat\Core\Auth::check()): ?><a data-reveal href="<?= e(url('ask-ai')) ?>"><i>✦</i><div><strong>اسأل خطوات AI</strong><small>حل مشكلة بخطوات تشخيصية</small></div><b>←</b></a><?php else: ?><a data-reveal href="<?= e(url('register')) ?>"><i>◎</i><div><strong>أنشئ حسابك</strong><small>احفظ مساراتك وابدأ مجانًا</small></div><b>←</b></a><?php endif; ?>
  </div>
</div></section>

<?php foreach($customBlocks as $block): if(empty($block['enabled']))continue; ?><section class="v216-section custom-home-block"><div class="container"><div class="v216-custom-card" data-reveal><span><?= e((string)($block['eyebrow']??'خطوات')) ?></span><h2><?= e((string)($block['title']??'')) ?></h2><p><?= nl2br(e((string)($block['body']??''))) ?></p><?php if(!empty($block['link'])&&!empty($block['link_text'])): ?><a href="<?= e(safe_href((string)$block['link'])) ?>"><?= e((string)$block['link_text']) ?> ←</a><?php endif; ?></div></div></section><?php endforeach; ?>

<section class="v216-final"><div class="container" data-reveal><div><span>خطوات رقمية</span><h2>افهم الطريق قبل أن تبدأ.</h2><p>ابدأ من هدفك، وسنساعدك على الوصول إلى الجهة والخطوة الصحيحة.</p></div><form action="<?= e(url('search')) ?>" method="get"><input type="search" name="q" placeholder="ماذا تريد أن تنجز؟"><button>ابدأ الآن ←</button></form></div></section>
</div>
