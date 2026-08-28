<section class="admin-wrap">
<div class="container admin-layout">
<?php require root_path('resources/views/partials/admin_nav.php'); ?>
<div class="admin-content">
    <div class="admin-head"><div><span class="eyebrow">التحرير</span><h1>المحتوى والتحديثات</h1></div></div>

    <div class="admin-two">
        <section class="admin-panel">
            <div class="panel-head">
                <h2><?= $editArticle ? 'مراجعة / تعديل مقال' : 'مقال جديد' ?></h2>
                <a href="<?= e(url('admin/ai-drafts')) ?>">توليد حزمة AI</a>
            </div>
            <?php $a = $editArticle ?? []; ?>
            <form class="inner-form" method="post" action="<?= e(url('admin/article/save')) ?>">
                <?= csrf_field() ?>
                <?php if (!empty($a['id'])): ?><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><?php endif; ?>
                <label>العنوان<input name="title" required value="<?= e((string)($a['title'] ?? '')) ?>"></label>
                <label>الرابط المختصر<input name="slug" value="<?= e((string)($a['slug'] ?? '')) ?>"></label>
                <label>الملخص<textarea name="summary" rows="3"><?= e((string)($a['summary'] ?? '')) ?></textarea></label>
                <label>المحتوى HTML<textarea name="content" rows="14"><?= e((string)($a['content'] ?? '')) ?></textarea></label>
                <div class="form-grid two">
                    <label>عنوان SEO<input name="seo_title" value="<?= e((string)($a['seo_title'] ?? '')) ?>"></label>
                    <label>وصف Meta<input name="seo_description" value="<?= e((string)($a['seo_description'] ?? '')) ?>"></label>
                </div>
                <label>روابط المصادر الرسمية — رابط في كل سطر
                    <textarea name="source_urls" rows="5" placeholder="https://...\nhttps://..."><?= e((string)($a['source_urls'] ?? '')) ?></textarea>
                </label>
                <label>ملاحظات التحقق
                    <textarea name="verification_notes" rows="5" placeholder="دوّن ما راجعته وما بقي ناقصًا."><?= e((string)($a['verification_notes'] ?? '')) ?></textarea>
                </label>
                <label class="switch-row">
                    <span>راجعت المصادر الرسمية وأتحمل اعتماد صحة المعلومات قبل النشر</span>
                    <input type="checkbox" name="sources_verified" <?= !empty($a['verified_at']) ? 'checked' : '' ?>>
                </label>
                <?php if (!empty($a['verified_at'])): ?><small>آخر اعتماد: <?= e((string)$a['verified_at']) ?> — <?= e((string)($a['verified_by'] ?? '')) ?></small><?php endif; ?>
                <label>الحالة
                    <select name="status">
                        <option value="draft" <?= (($a['status'] ?? 'draft') === 'draft') ? 'selected' : '' ?>>مسودة</option>
                        <option value="published" <?= (($a['status'] ?? '') === 'published') ? 'selected' : '' ?>>منشور</option>
                    </select>
                </label>
                <div class="notice-card"><b>حاجز النشر</b><p>لن يسمح النظام بالنشر إذا لم توجد روابط مصادر رسمية ولم يتم تأكيد مراجعتها.</p></div>
                <button class="btn btn-primary"><?= !empty($a['id']) ? 'حفظ المراجعة' : 'حفظ المقال' ?></button>
                <?php if (!empty($a['id'])): ?><a class="btn btn-soft" href="<?= e(url('admin/content')) ?>">إلغاء التعديل</a><?php endif; ?>
            </form>

            <div class="item-list">
                <?php foreach ($articles as $row): ?>
                    <div class="admin-row">
                        <div>
                            <b><?= e($row['title']) ?></b>
                            <small><?= e($row['status']) ?><?= !empty($row['verified_at']) ? ' • موثّق للمراجعة' : ' • يحتاج تحقق' ?></small>
                        </div>
                        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
                            <a href="<?= e(url('admin/content?edit='.(int)$row['id'])) ?>">تعديل</a>
                            <?php if (($row['status'] ?? '') === 'draft' && !empty($row['verified_at']) && trim((string)($row['source_urls'] ?? '')) !== ''): ?>
                                <form method="post" action="<?= e(url('admin/article/status')) ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                    <input type="hidden" name="status" value="published">
                                    <button class="btn btn-soft btn-sm">نشر</button>
                                </form>
                            <?php elseif (($row['status'] ?? '') === 'published'): ?>
                                <form method="post" action="<?= e(url('admin/article/status')) ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                    <input type="hidden" name="status" value="draft">
                                    <button class="btn btn-soft btn-sm">إلغاء النشر</button>
                                </form>
                                <a href="<?= e(url('article/'.$row['slug'])) ?>" target="_blank">عرض ↗</a>
                            <?php endif; ?>
                            <form method="post" action="<?= e(url('admin/article/delete')) ?>" onsubmit="return confirm('حذف المقال نهائيًا؟')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                <button class="btn btn-soft btn-sm">حذف</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="admin-panel v217-admin-calculators">
            <?php $localCalculators=\Khatauat\Services\CalculatorService::published(); ?>
            <div class="panel-head"><div><h2>مكتبة الحاسبات الداخلية</h2><p>كل الحاسبات تعمل داخل خطوات بنفس الهوية، ولا تحول المستخدم إلى صفحة خارجية.</p></div><span class="badge badge-success"><?= count($localCalculators) ?> حاسبة</span></div>
            <div class="v217-admin-calc-list">
              <?php foreach($localCalculators as $c): ?><a href="<?= e(url('calculator/'.$c['slug'])) ?>" target="_blank" rel="noopener"><span><?= e((string)($c['icon']??'▦')) ?></span><div><b><?= e($c['name']) ?></b><small><?= e(\Khatauat\Services\CalculatorService::categoryLabel((string)($c['category']??'general'))) ?> · <?= e((string)($c['rule_version']??'1.0')) ?></small></div><i>↗</i></a><?php endforeach; ?>
            </div>
            <div class="notice-card"><b>قاعدة تشغيل موحدة</b><p>المصدر الرسمي يظهر كمرجع أسفل النتيجة فقط. الحساب نفسه يتم داخل خطوات، ولا توجد أزرار تنقل المستخدم إلى حاسبة خارجية.</p></div>
        </section>
    </div>

    <section class="admin-panel top-gap">
        <div class="panel-head"><h2>مسودات التحديث المكتشفة</h2><span class="badge">تحتاج اعتمادًا بشريًا</span></div>
        <table class="data-table"><thead><tr><th>العنوان</th><th>المصدر</th><th>الأثر</th><th>الحالة</th><th></th></tr></thead><tbody>
        <?php foreach($updates as $u): ?><tr><td><b><?= e($u['title']) ?></b><small><?= e($u['entity']) ?></small></td><td><?= e($u['source_title']?:'—') ?></td><td><?= e(excerpt($u['impact'],100)) ?></td><td><?= e($u['status']) ?></td><td><form method="post" action="<?= e(url('admin/update/publish')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><select name="status"><option value="published">نشر</option><option value="draft">مسودة</option><option value="rejected">رفض</option></select><button class="btn btn-soft btn-sm">اعتماد</button></form></td></tr><?php endforeach; ?>
        </tbody></table>
    </section>
</div>
</div>
</section>
