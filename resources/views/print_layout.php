<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($title ?? 'تصدير المسار') ?></title>
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="print-page">
<div class="print-toolbar no-print"><a class="btn btn-soft" href="javascript:history.back()">رجوع</a><button class="btn btn-primary" onclick="window.print()">حفظ PDF / طباعة</button></div>
<?= $content ?>
</body></html>
