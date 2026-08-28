<?php

declare(strict_types=1);

namespace Khatauat\Services;

use Khatauat\Core\Database;
use Khatauat\Core\Auth;

final class SmartPathEvaluator
{
    public function questions(int $serviceId): array
    {
        $questions = Database::fetchAll('SELECT * FROM path_questions WHERE service_id = ? ORDER BY position, id', [$serviceId]);
        foreach ($questions as &$q) {
            $q['options'] = Database::fetchAll('SELECT * FROM path_question_options WHERE question_id = ? ORDER BY position, id', [(int)$q['id']]);
        }
        return $questions;
    }

    public function steps(int $serviceId, array $answers = []): array
    {
        $steps = Database::fetchAll(
            'SELECT ss.*, s.title AS source_title, s.url AS source_url, s.verified_at AS source_verified_at, s.verified_by AS source_verified_by
             FROM service_steps ss
             LEFT JOIN sources s ON s.id = ss.source_id
             WHERE ss.service_id = ? ORDER BY ss.position, ss.id',
            [$serviceId]
        );
        $rules = Database::fetchAll('SELECT * FROM path_rules WHERE service_id = ? ORDER BY id', [$serviceId]);
        $rulesByStep = [];
        foreach ($rules as $rule) $rulesByStep[(int)$rule['step_id']][] = $rule;

        $progress = [];
        if (Auth::id()) {
            $rows = Database::fetchAll(
                'SELECT usp.step_id, usp.completed FROM user_step_progress usp JOIN user_paths up ON up.id=usp.user_path_id WHERE up.user_id=? AND up.service_id=?',
                [Auth::id(), $serviceId]
            );
            foreach ($rows as $row) $progress[(int)$row['step_id']] = (bool)$row['completed'];
        }

        $included = [];
        foreach ($steps as $step) {
            $stepRules = $rulesByStep[(int)$step['id']] ?? [];
            $matches = !$answers || $this->rulesMatch($stepRules, $answers);
            if ($answers && !$matches) continue;
            if (!$answers && $stepRules) $step['conditional'] = true;
            $included[] = $step;
        }
        $includedIds = array_fill_keys(array_map(fn($s)=>(int)$s['id'], $included), true);

        foreach ($included as &$step) {
            $dependencyId = $step['depends_on_step_id'] ? (int)$step['depends_on_step_id'] : null;
            $step['completed'] = $progress[(int)$step['id']] ?? false;
            $step['blocked'] = false;
            if ($dependencyId && isset($includedIds[$dependencyId])) {
                $step['blocked'] = !(bool)($progress[$dependencyId] ?? false);
            } elseif (!$dependencyId && (int)$step['is_blocking'] === 1 && !empty($step['prerequisite']) && !($step['completed'] ?? false)) {
                $step['blocked'] = true;
            }
        }
        unset($step);
        return $included;
    }

    private function rulesMatch(array $rules, array $answers): bool
    {
        if (!$rules) return true;
        foreach ($rules as $rule) {
            $actual = $answers[$rule['question_key']] ?? null;
            $expected = $rule['value'];
            $ok = match ($rule['operator']) {
                'eq' => (string)$actual === (string)$expected,
                'neq' => (string)$actual !== (string)$expected,
                'in' => in_array((string)$actual, array_map('trim', explode(',', (string)$expected)), true),
                'not_in' => !in_array((string)$actual, array_map('trim', explode(',', (string)$expected)), true),
                default => false,
            };
            if (!$ok) return false;
        }
        return true;
    }

    public function savePath(int $serviceId, array $answers): int
    {
        $userId = Auth::id();
        if (!$userId) return 0;
        $existing = Database::fetch('SELECT id FROM user_paths WHERE user_id=? AND service_id=?', [$userId, $serviceId]);
        $json = json_encode($answers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($existing) {
            Database::execute('UPDATE user_paths SET answers_json=?, updated_at=CURRENT_TIMESTAMP WHERE id=?', [$json, (int)$existing['id']]);
            return (int)$existing['id'];
        }
        Database::execute('INSERT INTO user_paths(user_id,service_id,answers_json,created_at,updated_at) VALUES(?,?,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)', [$userId, $serviceId, $json]);
        return Database::lastInsertId();
    }
}
