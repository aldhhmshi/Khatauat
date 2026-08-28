<?php
use Khatauat\Services\CalculatorService;
$groups=[];
foreach($calculators as $c){$groups[$c['category']??'general'][]=$c;}
?>
<section class="v217-calc-hero">
  <div class="container v217-calc-hero-inner">
    <div>
      <span class="eyebrow">أدوات عملية داخل خطوات</span>
      <h1>احسبها هنا، ولا تغادر المنصة.</h1>
      <p>جمعنا الحاسبات المعتمدة داخل تجربة واحدة بالهوية نفسها. أدخل بياناتك، شاهد النتيجة فورًا، ثم راجع المرجع الرسمي عندما تكون القاعدة نظامية.</p>
    </div>
    <div class="v217-calc-hero-stat"><strong><?= count($calculators) ?></strong><span>حاسبة داخلية</span><small>بدون تحويل لصفحات خارجية</small></div>
  </div>
</section>

<section class="v217-calc-library">
  <div class="container">
    <div class="v217-calc-filter" data-calc-filter>
      <button class="is-active" type="button" data-category="all">الكل</button>
      <?php foreach(array_keys($groups) as $key): ?><button type="button" data-category="<?= e($key) ?>"><?= e(CalculatorService::categoryLabel((string)$key)) ?></button><?php endforeach; ?>
    </div>

    <div class="v217-calc-grid" data-calc-grid>
      <?php foreach($calculators as $i=>$c): ?>
      <a class="v217-calc-card" data-reveal data-category="<?= e((string)($c['category']??'general')) ?>" href="<?= e(url('calculator/'.$c['slug'])) ?>">
        <span class="v217-calc-no"><?= str_pad((string)($i+1),2,'0',STR_PAD_LEFT) ?></span>
        <div class="v217-calc-icon"><?= e((string)($c['icon']??'▦')) ?></div>
        <div class="v217-calc-card-copy"><small><?= e(CalculatorService::categoryLabel((string)($c['category']??'general'))) ?></small><h2><?= e($c['name']) ?></h2><p><?= e($c['purpose']) ?></p></div>
        <span class="v217-calc-open">ابدأ الحساب <b>←</b></span>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php render_ad_slot('calculators_mid'); ?>
<script>
document.addEventListener('DOMContentLoaded',()=>{const wrap=document.querySelector('[data-calc-filter]');if(!wrap)return;const cards=[...document.querySelectorAll('[data-calc-grid] [data-category]')];wrap.addEventListener('click',e=>{const b=e.target.closest('button[data-category]');if(!b)return;wrap.querySelectorAll('button').forEach(x=>x.classList.toggle('is-active',x===b));const k=b.dataset.category;cards.forEach(c=>c.hidden=k!=='all'&&c.dataset.category!==k);});});
</script>
