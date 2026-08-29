<?php

declare(strict_types=1);

$partyLabels = [
    'INDIVIDUAL' => 'فرد',
    'COMPANY' => 'شركة',
];
$policyLabels = [
    'INDIVIDUAL' => 'للأفراد',
    'COMPANY' => 'للشركات',
    'BOTH' => 'للفرد والشركة',
];
$activeId = (int)($activeOrganization['id'] ?? 0);
$organizationTypes = is_array($organizationTypes ?? null) ? $organizationTypes : [];
$organizations = is_array($organizations ?? null) ? $organizations : [];
$members = is_array($members ?? null) ? $members : [];
?>
<section class="workspace-page">
    <div class="container">
        <div class="workspace-hero">
            <div>
                <span class="workspace-eyebrow">مساحة داخلية · مستقلة عن دليل خطوات</span>
                <h1>مساحة المنظومة</h1>
                <p>اختر المؤسسة أولًا، ثم افتح الوحدة التي تخص عملك. لا يظهر محتوى أي منظومة قبل تثبيت سياق مؤسستك.</p>
            </div>
            <div class="workspace-identity" aria-label="هوية المنصة">
                <span class="workspace-identity-mark">خ</span>
                <div><strong>هوية خطوات محفوظة</strong><small>الإدارة هنا للمنظومات فقط</small></div>
            </div>
        </div>

        <div class="workspace-notice" role="note">
            <span class="workspace-notice-icon" aria-hidden="true">✓</span>
            <div><strong>فصل واضح بين المنصة والمنظومات</strong><p>حسابك في خطوات لا يمنح وصولًا تلقائيًا إلى Growth AI أو جواز المبنى. كل منظومة لها عضوية وصلاحيات وسياق مستقل.</p></div>
        </div>

        <div id="workspace-status" class="workspace-status" role="status" aria-live="polite" hidden></div>

        <div class="workspace-grid">
            <article class="workspace-card workspace-context-card">
                <div class="workspace-card-heading">
                    <div><span class="workspace-kicker">01 · السياق</span><h2>المؤسسة الحالية</h2></div>
                    <?php if ($activeOrganization): ?><span class="workspace-badge is-active">نشطة</span><?php else: ?><span class="workspace-badge">لم تُحدد</span><?php endif; ?>
                </div>

                <?php if ($organizations): ?>
                    <form id="workspace-switch-form" class="workspace-switch-form">
                        <?= csrf_field() ?>
                        <label for="workspace-organization">التبديل بين المؤسسات</label>
                        <div class="workspace-form-row">
                            <select id="workspace-organization" name="organization_id" required>
                                <?php foreach ($organizations as $organization): ?>
                                    <option value="<?= (int)$organization['id'] ?>" <?= (int)$organization['id'] === $activeId ? 'selected' : '' ?>><?= e((string)$organization['display_name']) ?> · <?= e((string)($organization['organization_type_name_ar'] ?? 'منظمة')) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button class="btn btn-primary" type="submit">تثبيت السياق</button>
                        </div>
                    </form>
                    <?php if ($activeOrganization): ?>
                        <div class="workspace-active-summary">
                            <span class="workspace-avatar"><?= e(mb_substr((string)$activeOrganization['display_name'], 0, 1)) ?></span>
                            <div><strong><?= e((string)$activeOrganization['display_name']) ?></strong><small><?= e((string)($activeOrganization['organization_type_name_ar'] ?? 'منظمة')) ?> · <?= e((string)$activeOrganization['party_kind']) ?></small></div>
                            <code><?= e((string)$activeOrganization['organization_uuid']) ?></code>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="workspace-empty">
                        <span class="workspace-empty-icon" aria-hidden="true">＋</span>
                        <div><strong>أنشئ أول مؤسسة لك</strong><p>يمكنك البدء كفرد أو كشركة. بعد الإنشاء ستصبح المؤسسة هي السياق النشط تلقائيًا.</p></div>
                    </div>
                <?php endif; ?>
            </article>

            <article class="workspace-card workspace-create-card">
                <div class="workspace-card-heading">
                    <div><span class="workspace-kicker">02 · الانضمام</span><h2>إضافة مؤسسة</h2></div>
                    <span class="workspace-badge">فرد أو شركة</span>
                </div>
                <?php if ($organizationTypes): ?>
                    <form id="workspace-create-form" class="workspace-form">
                        <?= csrf_field() ?>
                        <div class="workspace-form-grid">
                            <label>نوع الحساب
                                <select name="party_kind" id="workspace-party-kind" required>
                                    <option value="COMPANY">شركة</option>
                                    <option value="INDIVIDUAL">فرد</option>
                                </select>
                            </label>
                            <label>مجال المؤسسة
                                <select name="organization_type_key" id="workspace-organization-type" required>
                                    <?php foreach ($organizationTypes as $type): ?>
                                        <option value="<?= e((string)$type['type_key']) ?>" data-party-policy="<?= e((string)$type['party_kind_policy']) ?>"><?= e((string)$type['name_ar']) ?> · <?= e((string)($policyLabels[$type['party_kind_policy']] ?? $type['party_kind_policy'])) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="workspace-span-2">اسم المؤسسة الظاهر
                                <input type="text" name="display_name" maxlength="160" placeholder="مثال: شركة المدار للتطوير" required>
                            </label>
                            <label>الاسم النظامي <span>(اختياري)</span>
                                <input type="text" name="legal_name" maxlength="200">
                            </label>
                            <label>الرقم الموحد / السجل <span>(اختياري)</span>
                                <input type="text" name="unified_number" maxlength="80" inputmode="numeric">
                            </label>
                        </div>
                        <button class="btn btn-primary" type="submit">إنشاء المؤسسة وتفعيل السياق</button>
                    </form>
                <?php else: ?>
                    <div class="workspace-callout is-muted"><strong>إضافة المؤسسات غير مفعّلة في هذا الإصدار.</strong><p>هذه شاشة المعاينة فقط؛ لا تُنفّذ أي Migration على الإنتاج. سيظهر النموذج بعد اعتماد جداول المنظمات.</p></div>
                <?php endif; ?>
            </article>
        </div>

        <article class="workspace-card workspace-modules-card">
            <div class="workspace-card-heading">
                <div><span class="workspace-kicker">03 · الوحدات</span><h2>منظوماتك المستقلة</h2></div>
                <span class="workspace-badge is-outline">لا محتوى قبل الدخول</span>
            </div>
            <p class="workspace-muted">هذه البطاقات تعريفية فقط. الوصول الفعلي يمر عبر العضوية، الدور، النطاق، والاستحقاق الخاص بكل منظومة.</p>
            <div class="workspace-module-grid">
                <div class="workspace-module"><span class="workspace-module-icon">G</span><div><strong>Growth AI</strong><small>نمو وتسويق وعمليات ذكاء اصطناعي · سياق مستقل</small></div><span class="workspace-module-state">بانتظار التفعيل</span></div>
                <div class="workspace-module"><span class="workspace-module-icon">ج</span><div><strong>جواز المبنى</strong><small>مشاريع وأصول وصيانة · حسب نوع الشركة</small></div><span class="workspace-module-state">بانتظار التفعيل</span></div>
                <div class="workspace-module"><span class="workspace-module-icon">أ</span><div><strong>الأصول والصيانة</strong><small>قسم خاص داخل جواز المبنى وليس ضمن خطوات</small></div><span class="workspace-module-state">بانتظار التفعيل</span></div>
            </div>
            <?php if (!$activeOrganization): ?><div class="workspace-callout is-warning"><strong>لا يوجد سياق نشط.</strong><p>أنشئ مؤسسة أو اختر واحدة قبل فتح أي وحدة. هذا الحاجز مقصود لحماية بيانات الشركات.</p></div><?php endif; ?>
        </article>

        <article class="workspace-card workspace-members-card">
            <div class="workspace-card-heading">
                <div><span class="workspace-kicker">04 · الفريق</span><h2>عضويات المؤسسة</h2></div>
                <?php if ($activeOrganization && $canManageMembers): ?><span class="workspace-badge is-active">إدارة المالك</span><?php else: ?><span class="workspace-badge">محمي</span><?php endif; ?>
            </div>
            <?php if (!$activeOrganization): ?>
                <div class="workspace-callout is-muted"><strong>اختر مؤسسة أولًا.</strong><p>لن تُعرض أسماء أو عضويات أي مؤسسة قبل تثبيت السياق النشط.</p></div>
            <?php elseif (!$canManageMembers): ?>
                <div class="workspace-callout is-muted"><strong>هذه العضويات محمية بالدور.</strong><p>حسابك لا يملك صلاحية قراءة فريق المؤسسة الحالية.</p></div>
            <?php else: ?>
                <div class="workspace-member-toolbar">
                    <form id="workspace-invite-form" class="workspace-form-row">
                        <?= csrf_field() ?>
                        <input type="hidden" name="organization_id" value="<?= $activeId ?>">
                        <label class="sr-only" for="workspace-invite-email">بريد مستخدم مسجل</label>
                        <input id="workspace-invite-email" type="email" name="email" placeholder="بريد مستخدم مسجل في خطوات" required>
                        <button class="btn btn-soft" type="submit">دعوة عضو</button>
                    </form>
                    <span class="workspace-help">الدعوات الخارجية غير مفعلة في هذه المرحلة.</span>
                </div>
                <?php if ($members): ?>
                    <div class="workspace-member-list">
                        <?php foreach ($members as $member): ?>
                            <div class="workspace-member">
                                <span class="workspace-avatar is-small"><?= e(mb_substr((string)($member['name'] ?? '?'), 0, 1)) ?></span>
                                <div class="workspace-member-main"><strong><?= e((string)($member['name'] ?? 'مستخدم')) ?></strong><small><?= e((string)$member['email']) ?></small></div>
                                <span class="workspace-member-status status-<?= e(strtolower((string)$member['membership_status'])) ?>"><?= e((string)$member['membership_status']) ?></span>
                                <?php if ((int)$member['user_id'] !== (int)($currentUser['id'] ?? 0)): ?>
                                    <form class="workspace-member-status-form">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="membership_id" value="<?= (int)$member['membership_id'] ?>">
                                        <select name="status" aria-label="تحديث حالة العضوية">
                                            <option value="ACTIVE" <?= $member['membership_status'] === 'ACTIVE' ? 'selected' : '' ?>>نشطة</option>
                                            <option value="SUSPENDED" <?= $member['membership_status'] === 'SUSPENDED' ? 'selected' : '' ?>>موقوفة</option>
                                            <option value="ENDED" <?= $member['membership_status'] === 'ENDED' ? 'selected' : '' ?>>منتهية</option>
                                        </select>
                                        <button class="btn btn-ghost btn-sm" type="submit">حفظ</button>
                                    </form>
                                <?php else: ?><span class="workspace-owner-label">أنت · المالك</span><?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?><div class="workspace-empty is-compact"><strong>لا توجد عضويات إضافية بعد.</strong><p>ابدأ بدعوة مستخدم مسجل في خطوات.</p></div><?php endif; ?>
            <?php endif; ?>
        </article>

        <p class="workspace-footnote">هذه الصفحة داخلية وغير مفهرسة. الهوية العامة، الشعار، RTL، وصفحات دليل خطوات الحالية لم تتغير.</p>
    </div>
</section>

<script>
(() => {
    const status = document.getElementById('workspace-status');
    const show = (message, ok = false) => { if (!status) return; status.hidden = false; status.textContent = message; status.className = 'workspace-status ' + (ok ? 'is-success' : 'is-error'); };
    const submitJson = async (form, endpoint) => {
        const response = await fetch(endpoint, {method: 'POST', body: new FormData(form), headers: {'X-Requested-With': 'fetch', 'Accept': 'application/json'}});
        let payload = null;
        try { payload = await response.json(); } catch (_) {}
        if (!response.ok || !payload || payload.ok !== true) throw new Error(payload && payload.error ? payload.error : 'تعذر إكمال العملية.');
        return payload;
    };
    const bind = (selector, endpoint, success) => document.querySelectorAll(selector).forEach(form => form.addEventListener('submit', async event => {
        event.preventDefault();
        const button = form.querySelector('button[type="submit"]');
        if (button) button.disabled = true;
        try { await submitJson(form, endpoint); show(success, true); window.setTimeout(() => window.location.reload(), 450); }
        catch (error) { show(error.message || 'تعذر إكمال العملية.'); if (button) button.disabled = false; }
    }));
    bind('#workspace-switch-form', <?= json_encode(url('api/organizations/switch'), JSON_UNESCAPED_SLASHES) ?>, 'تم تثبيت المؤسسة الحالية.');
    bind('#workspace-create-form', <?= json_encode(url('api/organizations'), JSON_UNESCAPED_SLASHES) ?>, 'تم إنشاء المؤسسة وتفعيل السياق.');
    bind('#workspace-invite-form', <?= json_encode(url('api/organizations/member/invite'), JSON_UNESCAPED_SLASHES) ?>, 'تم إرسال الدعوة للمستخدم.');
    bind('.workspace-member-status-form', <?= json_encode(url('api/organizations/member/status'), JSON_UNESCAPED_SLASHES) ?>, 'تم تحديث حالة العضوية.');

    const party = document.getElementById('workspace-party-kind');
    const types = document.getElementById('workspace-organization-type');
    const filterTypes = () => {
        if (!party || !types) return;
        const selected = party.value;
        let firstVisible = null;
        Array.from(types.options).forEach(option => {
            const policy = option.dataset.partyPolicy || 'BOTH';
            const visible = policy === 'BOTH' || policy === selected;
            option.hidden = !visible;
            if (visible && !firstVisible) firstVisible = option;
        });
        if (types.selectedOptions[0] && types.selectedOptions[0].hidden && firstVisible) types.value = firstVisible.value;
    };
    party?.addEventListener('change', filterTypes); filterTypes();
})();
</script>
