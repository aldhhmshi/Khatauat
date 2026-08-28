<?php

declare(strict_types=1);

namespace Khatauat\Services;

final class PdfExportService
{
    public function available(): bool
    {
        $autoload = \root_path('vendor/autoload.php');
        if (is_file($autoload)) require_once $autoload;
        return class_exists('Dompdf\\Dompdf');
    }

    public function stream(array $service, array $steps): never
    {
        if (!$this->available()) {
            \flash('info', 'مولد PDF المباشر غير مثبت؛ فُتحت نسخة الطباعة ويمكن حفظها PDF من المتصفح.');
            $query = trim((string)($_SERVER['QUERY_STRING'] ?? ''));
            \redirect('export/' . $service['slug'] . '/print' . ($query !== '' ? '?' . $query : ''));
        }
        $rows = '';
        foreach ($steps as $st) {
            $rows .= '<section><h2>' . \e((string)$st['position'] . '. ' . $st['title']) . '</h2>'
                . '<p><b>الجهة:</b> ' . \e($st['entity']) . '</p>'
                . ($st['prerequisite'] ? '<p><b>المتطلب السابق:</b> ' . \e($st['prerequisite']) . '</p>' : '')
                . '<p><b>الإجراء:</b> ' . \e($st['action_text']) . '</p>'
                . '<p><b>المخرج:</b> ' . \e($st['output_text']) . '</p>'
                . '<p><b>الرابط الرسمي:</b> ' . \e($st['official_url']) . '</p>'
                . '<p><b>آخر تحقق:</b> ' . \e($st['verified_at'] ?: $st['source_verified_at'] ?: '—') . '</p></section>';
        }
        $html = '<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><style>body{font-family:DejaVu Sans,déjàvu sans,sans-serif;direction:rtl;color:#142b29;font-size:12px;line-height:1.7}h1{color:#0d4b46}section{border-bottom:1px solid #dce7e3;padding:12px 0}h2{font-size:15px}.note{background:#edf7f4;padding:10px;border-right:3px solid #0d4b46}</style></head><body><h1>' . \e($service['name']) . '</h1><p class="note">منصة إرشادية مستقلة وليست جهة حكومية. التنفيذ داخل القنوات الرسمية فقط.</p>' . $rows . '<p>تاريخ التصدير: ' . \e(date('Y-m-d H:i')) . '</p></body></html>';
        $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled'=>false]);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();
        $filename = 'khatauat-' . preg_replace('/[^A-Za-z0-9_-]/', '-', $service['slug']) . '.pdf';
        $dompdf->stream($filename, ['Attachment'=>true]);
        exit;
    }
}
