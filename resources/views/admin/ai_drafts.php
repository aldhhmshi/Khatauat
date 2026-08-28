<?php
use Khatauat\Services\ArticleDraftMapper;
?>
<style>
.ai-draft-toolbar{margin:14px 0 18px;padding:14px;border:1px solid var(--line);border-radius:14px;background:var(--soft)}
.ai-draft-toolbar-head{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:10px}
.ai-draft-toolbar-head b{font-size:12px;color:var(--ink)}
.ai-draft-toolbar-head small{font-size:9px;color:var(--muted)}
.ai-draft-actions{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}
.ai-draft-actions>a,.ai-draft-actions>form{min-width:0;margin:0}
.ai-draft-actions .btn{width:100%;min-height:42px;padding-inline:12px}
.ai-delete-action{grid-column:1/-1;display:flex;justify-content:flex-start}
.ai-delete-action .btn{width:auto!important;min-width:150px}
.ai-draft-actions .btn-danger{background:#fff0f0;color:var(--danger);border:1px solid #efcaca}
.ai-draft-actions .btn-danger:hover{background:#fde7e7}
.ai-publish-box{grid-column:1/-1;display:grid;grid-template-columns:minmax(0,1fr) auto;align-items:center;gap:10px;padding:10px 12px;border:1px solid var(--line);border-radius:12px;background:#fff}
.ai-publish-box .switch-row{margin:0;min-width:0;font-size:10px}
.ai-publish-box .btn{width:auto;min-width:145px}
.ai-draft-legacy{margin:10px 0;padding:10px 12px;border-radius:11px;background:var(--warn-bg);color:var(--warn);font-size:10px}
.ai-draft-preview-title{display:flex;justify-content:space-between;align-items:center;gap:10px;margin:14px 0 8px}
.ai-draft-preview-title h3{margin:0;font-size:12px}
.ai-draft-preview-title span{font-size:9px;color:var(--muted)}
.ai-lang-card{margin:12px 0;padding:14px;border:1px solid var(--line);border-radius:14px;background:#fff;line-height:1.85}
.ai-lang-card h3{margin:0 0 10px}.ai-lang-en{text-align:left}
.ai-content-preview{margin:12px 0;padding:18px;border:1px solid var(--line);border-radius:14px;background:#fff;line-height:1.95;text-align:right}
.ai-content-preview-en{direction:ltr;text-align:left}
.ai-content-preview h2,.ai-content-preview h3{margin-top:18px}
.ai-content-preview p,.ai-content-preview li{font-size:14px}
@media(max-width:700px){.ai-draft-actions{grid-template-columns:1fr}.ai-publish-box{grid-column:auto;grid-template-columns:1fr}.ai-publish-box .btn{width:100%}.ai-delete-action{grid-column:auto}.ai-delete-action .btn{width:100%!important}}
</style>
<section class="admin-wrap">
<div class="container admin-layout">
<?php require root_path('resources/views/partials/admin_nav.php'); ?>
<div class="admin-content">
    <div class="admin-head">
        <div>
            <span class="eyebrow">الذكاء الاصطناعي</span>
            <h1>توليد حزمة مقال + SEO</h1>
            <p>المخرجات الجديدة: العربية أولًا ثم الإنجليزية، مع مصدر رسمي إلزامي ومحتوى مهني دون إظهار التفكير الداخلي.</p>
        </div>
    </div>

    <div class="admin-two">
        <section class="admin-panel">
            <form class="inner-form" method="post" action="<?= e(url('admin/ai-generate')) ?>">
                <?= csrf_field() ?>
                <label>الموضوع<input name="topic" required></label>
                <div class="form-grid two">
                    <label>الجمهور<input name="audience" placeholder="مثال: الأفراد في السعودية"></label>
                    <label>الكلمة المفتاحية<input name="keyword" placeholder="كلمة أساسية واحدة"></label>
                </div>
                <label>المصادر المعتمدة
                    <textarea name="sources" rows="12" placeholder="ألصق الروابط الرسمية ومعها النصوص أو النقاط التي تريد الاعتماد عليها. لا تضع معلومات غير متحقق منها." required></textarea>
                </label>
                <div class="notice-card">
                    <b>قاعدة التوليد</b>
                    <p>يلزم رابط رسمي واحد على الأقل. العربية هي النسخة الأساسية، ثم الإنجليزية. أي معلومة غير مثبتة بالمصدر تُسجل كمعلومة ناقصة ولا تُخمن.</p>
                </div>
                <button class="btn btn-primary">إنشاء حزمة المقال</button>
            </form>
        </section>

        <section class="admin-panel">
            <div class="panel-head"><h2>آخر المسودات</h2><span class="badge"><?= count($drafts) ?></span></div>
            <?php if (!$drafts): ?><p>لا توجد مسودات بعد.</p><?php endif; ?>
            <?php foreach ($drafts as $d): ?>
                <?php
                    $status = (string)($d['status'] ?? 'draft');
                    $statusLabel = ['draft'=>'مسودة','converted'=>'مرحّلة للمحتوى','published'=>'منشورة','failed'=>'فشل التوليد'][$status] ?? $status;
                    $pack = $status === 'failed' ? null : ArticleDraftMapper::decodeDraft($d);
                    $hasArticle = !empty($d['article_id']);
                ?>
                <details class="admin-details">
                    <summary>
                        <span>
                            <b><?= e($d['topic']) ?></b>
                            <small><?= e($statusLabel) ?> • <?= e(substr((string)$d['created_at'], 0, 16)) ?><?= !empty($d['provider']) ? ' • ' . e($d['provider']) : '' ?></small>
                        </span>
                        <span>إدارة</span>
                    </summary>

                    <?php if ($status !== 'failed'): ?>
                        <div class="draft-output">
                            <div class="ai-draft-toolbar">
                                <div class="ai-draft-toolbar-head">
                                    <b>إجراءات المسودة</b>
                                    <small>الترتيب المقترح: ترحيل ← مراجعة ← نشر</small>
                                </div>
                                <div class="ai-draft-actions">
                                    <?php if (!$hasArticle && in_array($status, ['draft','converted'], true)): ?>
                                        <form method="post" action="<?= e(url('admin/ai-draft/to-article')) ?>">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                                            <button class="btn btn-primary" type="submit">ترحيل إلى المحتوى والتحديثات</button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if ($hasArticle): ?>
                                        <a class="btn btn-primary" href="<?= e(url('admin/content?edit='.(int)$d['article_id'])) ?>">فتح المقال في المحتوى والتحديثات</a>
                                    <?php endif; ?>

                                    <?php if (($d['article_status'] ?? '') === 'published' && !empty($d['article_slug'])): ?>
                                        <a class="btn btn-ghost" href="<?= e(url('article/'.$d['article_slug'])) ?>" target="_blank">عرض المقال المنشور ↗</a>
                                    <?php endif; ?>

                                    <?php if (in_array($status, ['draft','converted'], true)): ?>
                                        <form class="ai-publish-box" method="post" action="<?= e(url('admin/ai-draft/publish')) ?>" onsubmit="return confirm('سيتم نشر المقال بعد تأكيد مراجعة المصادر. متابعة؟')">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                                            <label class="switch-row"><span>راجعت المصادر الرسمية وأوافق على النشر</span><input type="checkbox" name="sources_verified" required></label>
                                            <button class="btn btn-soft" type="submit">نشر بعد التحقق</button>
                                        </form>
                                    <?php endif; ?>

                                    <form class="ai-delete-action" method="post" action="<?= e(url('admin/ai-draft/delete')) ?>" onsubmit="return confirm('حذف هذه المسودة؟ إذا كان لها مقال مرتبط فسيبقى المقال محفوظًا.')">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                                        <button class="btn btn-danger" type="submit">حذف المسودة</button>
                                    </form>
                                </div>
                            </div>

                            <?php if (is_array($pack)): ?>
                                <?php if (!empty($pack['legacy'])): ?>
                                    <div class="ai-draft-legacy">هذه مسودة قديمة. أصبح بإمكانك ترحيلها الآن، لكن يجب مراجعة التنسيق والمعلومات داخل «المحتوى والتحديثات» قبل النشر.</div>
                                <?php endif; ?>
                                <?php if (!empty($pack['bilingual'])): ?>
                                    <div class="ai-lang-card" dir="rtl" lang="ar">
                                        <h3>العربية — النسخة الأساسية</h3>
                                        <p><strong>العنوان:</strong> <?= e((string)($pack['title_ar'] ?? $pack['title'] ?? '')) ?></p>
                                        <p><strong>SEO:</strong> <?= e((string)($pack['seo_title_ar'] ?? $pack['seo_title'] ?? '')) ?></p>
                                        <p><strong>Meta:</strong> <?= e((string)($pack['seo_description_ar'] ?? $pack['seo_description'] ?? '')) ?></p>
                                        <p><strong>الملخص:</strong> <?= e((string)($pack['summary_ar'] ?? $pack['summary'] ?? '')) ?></p>
                                    </div>
                                    <div class="ai-lang-card ai-lang-en" dir="ltr" lang="en">
                                        <h3>English</h3>
                                        <p><strong>Title:</strong> <?= e((string)($pack['title_en'] ?? '')) ?></p>
                                        <p><strong>SEO:</strong> <?= e((string)($pack['seo_title_en'] ?? '')) ?></p>
                                        <p><strong>Meta:</strong> <?= e((string)($pack['seo_description_en'] ?? '')) ?></p>
                                        <p><strong>Summary:</strong> <?= e((string)($pack['summary_en'] ?? '')) ?></p>
                                    </div>
                                    <p><strong>Slug:</strong> <?= e((string)($pack['slug'] ?? '')) ?></p>
                                <?php else: ?>
                                    <p><strong>العنوان:</strong> <?= e((string)($pack['title'] ?? '')) ?></p>
                                    <p><strong>SEO Title:</strong> <?= e((string)($pack['seo_title'] ?? '')) ?></p>
                                    <p><strong>Meta:</strong> <?= e((string)($pack['seo_description'] ?? '')) ?></p>
                                    <p><strong>Slug:</strong> <?= e((string)($pack['slug'] ?? '')) ?></p>
                                    <p><strong>الملخص:</strong> <?= e((string)($pack['summary'] ?? '')) ?></p>
                                <?php endif; ?>

                                <?php if (!empty($pack['source_urls'])): ?>
                                    <h3>المصادر المستخرجة</h3>
                                    <ul><?php foreach ((array)$pack['source_urls'] as $u): ?><li><?= e((string)$u) ?></li><?php endforeach; ?></ul>
                                <?php endif; ?>

                                <?php if (!empty($pack['missing_information'])): ?>
                                    <h3>معلومات ناقصة — لا تُخمن</h3>
                                    <ul><?php foreach ((array)$pack['missing_information'] as $m): ?><li><?= e((string)$m) ?></li><?php endforeach; ?></ul>
                                <?php endif; ?>

                                <?php if (!empty($pack['verification_notes'])): ?>
                                    <h3>ملاحظات المراجعة</h3>
                                    <ul><?php foreach ((array)$pack['verification_notes'] as $m): ?><li><?= e((string)$m) ?></li><?php endforeach; ?></ul>
                                <?php endif; ?>

                                <div class="ai-draft-preview-title"><h3>معاينة المحتوى</h3><span>العربية أولًا ثم الإنجليزية — التحرير بعد الترحيل</span></div>
                                <?php if (!empty($pack['bilingual'])): ?>
                                    <div class="ai-content-preview" dir="rtl" lang="ar">
                                        <h3>المحتوى العربي</h3>
                                        <?= (string)($pack['content_html_ar'] ?? '') ?>
                                    </div>
                                    <div class="ai-content-preview ai-content-preview-en" dir="ltr" lang="en">
                                        <h3>English content</h3>
                                        <?= (string)($pack['content_html_en'] ?? '') ?>
                                    </div>
                                <?php else: ?>
                                    <textarea rows="14" readonly><?= e((string)($pack['content_html'] ?? '')) ?></textarea>
                                <?php endif; ?>
                            <?php else: ?>
                                <div class="ai-draft-legacy">تعذر تحليل معاينة المسودة، لكن يمكنك محاولة ترحيلها إلى «المحتوى والتحديثات» لمراجعتها يدويًا.</div>
                                <textarea rows="14" readonly><?= e((string)($d['result_text'] ?? '')) ?></textarea>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="draft-output">
                            <?= nl2br(e((string)($d['error_detail'] ?: $d['result_text']))) ?>
                            <div class="ai-draft-toolbar">
                                <div class="ai-draft-actions">
                                    <form method="post" action="<?= e(url('admin/ai-draft/delete')) ?>" onsubmit="return confirm('حذف هذه المسودة الفاشلة؟')">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                                        <button class="btn btn-danger" type="submit">حذف المسودة الفاشلة</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </details>
            <?php endforeach; ?>
        </section>
    </div>
</div>
</div>
</section>
