# نشر خطوات 2.0 على Hostinger

## قبل الرفع
1. احتفظ بنسخة كاملة من ملفات الموقع وقاعدة SQLite الحالية.
2. تأكد من تفعيل PHP 8.2+ و`pdo_sqlite`, `mbstring`, `curl`, `openssl`, `fileinfo`.
3. لا تضع `.env` أو `storage/` داخل مسار عام يمكن تنزيله مباشرة.

## ترتيب الملفات
الأفضل أن يكون المشروع في مسار خاص مثل:

```text
/home/USER/khatauat/
  app/
  bin/
  database/
  resources/
  storage/
  .env
  public/
```

واجعل الدومين يشير إلى:

```text
/home/USER/khatauat/public
```

إذا كانت خطة الاستضافة لا تسمح بتغيير Document Root، انقل **محتويات `public/` فقط** إلى `public_html` وعدّل Bootstrap path بعناية، مع إبقاء `app`, `storage`, `.env` وقاعدة البيانات خارج `public_html`.

## ترقية قاعدة حالية
بعد رفع نسخة 2.0 وتشغيلها على نسخة احتياطية أولًا:

```bash
php bin/upgrade-v2.0.php
php bin/check.php
```

راجع بعد ذلك:
- `/admin/source-registry`
- `/admin/integrations`
- `/admin/ai-ops`
- `/admin/marketing`

## أسرار الخادم
ضع الأسرار المطلوبة فقط في `.env` أو Environment Variables لدى Hostinger. لا تضعها داخل Git أو قاعدة البيانات.

لـOpenAI:
```env
OPENAI_API_KEY=...
OPENAI_MODEL=...
```

لـExa (اختياري):
```env
EXA_API_KEY=...
```

لجسر النشر:
```env
AUTOMATION_WEBHOOK_SECRET=...
```

## Cron
ابدأ بالثلاثة التالية بعد التأكد من المسارات:

```cron
0 * * * * /usr/bin/php /home/USER/khatauat/bin/cron-monitor.php >> /home/USER/khatauat/storage/logs/cron-monitor.log 2>&1
20 3 * * * /usr/bin/php /home/USER/khatauat/bin/cron-source-discovery.php >> /home/USER/khatauat/storage/logs/cron-source-discovery.log 2>&1
*/5 * * * * /usr/bin/php /home/USER/khatauat/bin/cron-marketing.php >> /home/USER/khatauat/storage/logs/cron-marketing.log 2>&1
```

## فحص بعد النشر
- الصفحة الرئيسية والبحث.
- الصفحات الثلاث المصدرية المنشورة.
- تسجيل دخول المالك وتغيير كلمة المرور.
- إضافة مصدر مرشح واعتماده في بيئة اختبار.
- تشغيل AI Ops بعد ضبط المفتاح والنموذج.
- إرسال Webhook اختباري إلى n8n/Make قبل ربط الحسابات الاجتماعية الحقيقية.
- Sitemap/robots/canonical.
- consent وGA4.
- الإعلانات على الهاتف والكمبيوتر بدون تداخل مع أزرار التنفيذ الرسمية.

## قاعدة الأمان التشغيلية
لا تمنح النشر التلقائي للمحتوى الحكومي. اجعل Source Monitor + AI يكتشفان ويقارنان ويجهزان المسودة، والمالك يعتمد التغيير.
