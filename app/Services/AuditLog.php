<?php

declare(strict_types=1);

namespace Khatauat\Services;

use Khatauat\Core\Auth;
use Khatauat\Core\Database;

final class AuditLog
{
    public static function write(string $action, ?string $entityType = null, string|int|null $entityId = null, ?string $summary = null, array $metadata = [], string $actorType = 'user'): void
    {
        try {
            Database::execute(
                'INSERT INTO audit_logs(actor_user_id,actor_type,action,entity_type,entity_id,summary,metadata_json,created_at) VALUES(?,?,?,?,?,?,?,CURRENT_TIMESTAMP)',
                [Auth::id(), $actorType, $action, $entityType, $entityId === null ? null : (string)$entityId, $summary, json_encode($metadata, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?: '{}']
            );
        } catch (\Throwable) {
            // Audit logging must never break the primary action.
        }
    }
}
