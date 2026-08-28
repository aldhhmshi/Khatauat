<section class="page-hero small"><div class="container narrow"><span class="eyebrow">الدفع</span><h1><?= e((string)$product['name']) ?></h1><p>هذه صفحة المراجعة الأخيرة. بعد الموافقة اضغط زر الدفع للانتقال إلى Moyasar وإتمام العملية.</p></div></section>
<section class="section"><div class="container narrow"><div class="checkout-card"><div class="checkout-summary"><div><span>الباقة</span><strong><?= e((string)$product['name']) ?></strong></div><div><span>عدد المشاكل</span><strong><?= (int)$product['included_cases'] ?></strong></div><div><span>حد الرسائل لكل مشكلة</span><strong><?= (int)$product['case_message_limit'] ?></strong></div><div><span>السعر النهائي المعروض</span><strong><?= number_format(((int)$product['price_minor'])/100,2) ?> ريال</strong></div></div><form method="post" action="<?= e(url('billing/checkout')) ?>" class="checkout-consent-form"><?= csrf_field() ?><input type="hidden" name="product_code" value="<?= e((string)$product['code']) ?>"><label class="legal-check">
<?php
$paylinkCheckout =
    strtolower(
        (string)(
            getenv('PAYMENT_GATEWAY')
            ?: 'moyasar'
        )
    ) === 'paylink';
?>

<?php if ($paylinkCheckout): ?>

<div class="field">
    <label for="client_name">
        الاسم
    </label>

    <input
        id="client_name"
        name="client_name"
        type="text"
        maxlength="120"
        autocomplete="name"
        required
        placeholder="الاسم كما ترغب أن يظهر في فاتورة الدفع"
    >
</div>

<div class="field">
    <label for="client_mobile">
        رقم الجوال
    </label>

    <input
        id="client_mobile"
        name="client_mobile"
        type="tel"
        inputmode="tel"
        autocomplete="tel"
        maxlength="10"
        pattern="05[0-9]{8}"
        required
        placeholder="05XXXXXXXX"
        dir="ltr"
    >

    <small>
        يستخدم لإنشاء فاتورة الدفع الآمنة لدى Paylink.
    </small>
</div>

<?php endif; ?>

<input type="checkbox" name="accept_terms" value="1" required><span>قرأت وأوافق على <a href="<?= e(url('terms')) ?>" target="_blank">الشروط والأحكام</a>، بما فيها تعريف «المشكلة» والاستخدام والاسترداد.</span></label><label class="legal-check"><input type="checkbox" name="accept_privacy" value="1" required><span>اطلعت على <a href="<?= e(url('privacy')) ?>" target="_blank">سياسة الخصوصية</a> وكيفية معالجة بيانات الدفع والأمان.</span></label><div class="payment-safety"><strong>بيانات البطاقة لا تمر عبر خطوات</strong><p>بعد المتابعة سيتم توجيهك إلى صفحة الدفع المستضافة لدى مزود الدفع. لا نطلب رقم البطاقة أو CVV داخل هذه الصفحة.</p></div><button class="btn btn-primary btn-lg" type="submit" <?= $gateway_mode==='disabled'?'disabled':'' ?>>الدفع عبر Moyasar</button><?php if($gateway_mode==='disabled'): ?><p class="form-hint is-warning">بوابة الدفع لم تُفعّل بعد. يجب إعداد مفتاح Moyasar في البيئة قبل بدء عمليات حقيقية.</p><?php endif; ?></form></div></div></section>
