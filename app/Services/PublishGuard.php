<?php

declare(strict_types=1);

namespace Khatauat\Services;

use Khatauat\Core\Database;

final class PublishGuard
{
    public function validateService(int $serviceId): array
    {
        $errors = [];
        $s = Database::fetch('SELECT * FROM services WHERE id=?', [$serviceId]);
        if (!$s) return ['الخدمة غير موجودة.'];
        foreach (['name'=>'اسم الخدمة','category_id'=>'التصنيف','official_entity'=>'الجهة','summary'=>'الوصف'] as $key=>$label) {
            if (empty($s[$key])) $errors[] = "الحقل الإلزامي مفقود: {$label}.";
        }
        $steps = Database::fetchAll('SELECT * FROM service_steps WHERE service_id=? ORDER BY position', [$serviceId]);
        if (!$steps) $errors[] = 'يجب إضافة خطوة واحدة على الأقل.';
        $stepIds = array_map(fn($r)=>(int)$r['id'], $steps);
        foreach ($steps as $st) {
            $label = 'الخطوة ' . (int)$st['position'] . ' (' . $st['title'] . ')';
            if (empty($st['official_url'])) $errors[] = "{$label}: رابط التنفيذ الرسمي مفقود.";
            if (empty($st['source_id'])) $errors[] = "{$label}: المصدر الرسمي مفقود.";
            if (empty($st['verified_at'])) $errors[] = "{$label}: تاريخ التحقق مفقود.";
            if (empty($st['verified_by'])) $errors[] = "{$label}: مسؤول التحقق مفقود.";
            if (($st['trust_status'] ?? '') !== 'verified') $errors[] = "{$label}: الحالة تحتاج تحقق ولا يمكن نشرها.";
            if (!empty($st['depends_on_step_id']) && !in_array((int)$st['depends_on_step_id'], $stepIds, true)) $errors[] = "{$label}: الاعتماد يشير إلى خطوة غير موجودة.";
        }
        $errors = array_merge($errors, $this->dependencyCycles($steps));
        $questions = Database::fetchAll('SELECT question_key FROM path_questions WHERE service_id=?', [$serviceId]);
        $keys = array_column($questions, 'question_key');
        $rules = Database::fetchAll('SELECT * FROM path_rules WHERE service_id=?', [$serviceId]);
        foreach ($rules as $r) {
            if (!in_array($r['question_key'], $keys, true)) $errors[] = 'قاعدة تفرع تشير إلى سؤال غير موجود: ' . $r['question_key'];
            if (!in_array((int)$r['step_id'], $stepIds, true)) $errors[] = 'قاعدة تفرع تشير إلى خطوة غير موجودة.';
        }
        return array_values(array_unique($errors));
    }

    private function dependencyCycles(array $steps): array
    {
        $parent = [];
        foreach ($steps as $st) $parent[(int)$st['id']] = $st['depends_on_step_id'] ? (int)$st['depends_on_step_id'] : null;
        foreach (array_keys($parent) as $start) {
            $seen = [];
            $cur = $start;
            while ($cur && isset($parent[$cur])) {
                if (isset($seen[$cur])) return ['يوجد اعتماد دائري بين خطوات المسار.'];
                $seen[$cur] = true;
                $cur = $parent[$cur];
            }
        }
        return [];
    }
}
