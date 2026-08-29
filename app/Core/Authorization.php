<?php

declare(strict_types=1);

namespace Khatauat\Core;

/**
 * Tenant authorization resolver.
 *
 * Every decision is recomputed from membership → role assignment → role
 * permission → module entitlement. Session values and client supplied ids are
 * never treated as authorization evidence.
 */
final class Authorization
{
    public static function allows(int $userId, string $permissionKey, ?int $organizationId = null, ?array $resource = null): bool
    {
        if ($userId < 1 || trim($permissionKey) === '' || !self::ready()) return false;
        try {
            $organizationId ??= TenantContext::id($userId);
            if (!$organizationId) return false;
            $activeContext = TenantContext::current($userId);
            if (!$activeContext || (int)$activeContext['id'] !== (int)$organizationId) return false;
            $permission = Database::fetch(
                "SELECT p.id, p.permission_key, p.module_key, p.status AS permission_status,
                        m.module_status, ls.launch_state
                 FROM core_permissions p
                 JOIN core_modules m ON m.module_key = p.module_key
                 LEFT JOIN core_launch_states ls ON ls.module_key = p.module_key
                 WHERE p.permission_key = ? LIMIT 1",
                [$permissionKey]
            );
            if (!$permission || $permission['permission_status'] !== 'ACTIVE' || $permission['module_status'] === 'RETIRED') return false;
            if (($permission['launch_state'] ?? '') === 'PAUSED') return false;
            if (!self::hasEntitlement((int)$organizationId, (string)$permission['module_key'])) return false;
            if (!self::activeMembership($userId, (int)$organizationId)) return false;

            $assignments = Database::fetchAll(
                "SELECT ra.id AS assignment_id, rp.effect
                 FROM core_memberships cm
                 JOIN core_role_assignments ra ON ra.membership_id = cm.id AND ra.organization_id = cm.organization_id
                 JOIN core_roles r ON r.id = ra.role_id AND r.organization_id = ra.organization_id
                 JOIN core_role_permissions rp ON rp.role_id = r.id AND rp.organization_id = r.organization_id
                 WHERE cm.user_id = ? AND cm.organization_id = ? AND cm.membership_status = 'ACTIVE'
                   AND ra.assignment_status = 'ACTIVE' AND r.status = 'ACTIVE'
                   AND (ra.valid_from IS NULL OR ra.valid_from <= CURRENT_TIMESTAMP)
                   AND (ra.valid_until IS NULL OR ra.valid_until >= CURRENT_TIMESTAMP)
                   AND rp.permission_id = ?",
                [$userId, $organizationId, (int)$permission['id']]
            );
            $allowed = false;
            foreach ($assignments as $assignment) {
                if (!self::assignmentCovers((int)$assignment['assignment_id'], (int)$organizationId, $resource)) continue;
                if ($assignment['effect'] === 'DENY') return false;
                if ($assignment['effect'] === 'ALLOW') $allowed = true;
            }
            return $allowed;
        } catch (\Throwable) {
            return false;
        }
    }

    public static function require(int $userId, string $permissionKey, ?int $organizationId = null, ?array $resource = null): void
    {
        if (!self::allows($userId, $permissionKey, $organizationId, $resource)) {
            throw new TenantAccessException('العملية غير مسموحة ضمن المنظمة أو النطاق الحالي.');
        }
    }

    public static function permissions(int $userId, int $organizationId): array
    {
        $activeContext = TenantContext::current($userId);
        if (!$activeContext || (int)$activeContext['id'] !== $organizationId || !self::activeMembership($userId, $organizationId) || !self::ready()) return [];
        $rows = Database::fetchAll(
            "SELECT DISTINCT p.permission_key, p.module_key, p.resource_key, p.action_key
             FROM core_memberships cm
             JOIN core_role_assignments ra ON ra.membership_id = cm.id AND ra.organization_id = cm.organization_id
             JOIN core_roles r ON r.id = ra.role_id AND r.organization_id = ra.organization_id
             JOIN core_role_permissions rp ON rp.role_id = r.id AND rp.organization_id = r.organization_id
             JOIN core_permissions p ON p.id = rp.permission_id
             WHERE cm.user_id = ? AND cm.organization_id = ? AND cm.membership_status = 'ACTIVE'
               AND ra.assignment_status = 'ACTIVE' AND r.status = 'ACTIVE' AND rp.effect = 'ALLOW'
               AND p.status = 'ACTIVE'",
            [$userId, $organizationId]
        );
        return array_column($rows, 'permission_key');
    }

    private static function activeMembership(int $userId, int $organizationId): bool
    {
        return (bool)Database::scalar(
            "SELECT 1 FROM core_memberships m JOIN core_organizations o ON o.id = m.organization_id
             WHERE m.user_id = ? AND m.organization_id = ? AND m.membership_status = 'ACTIVE'
               AND o.status = 'ACTIVE' AND o.deleted_at IS NULL LIMIT 1",
            [$userId, $organizationId]
        );
    }

    private static function hasEntitlement(int $organizationId, string $moduleKey): bool
    {
        return (bool)Database::scalar(
            "SELECT 1 FROM core_module_entitlements
             WHERE organization_id = ? AND module_key = ?
               AND entitlement_status IN ('ACTIVE','TRIAL')
               AND (starts_at IS NULL OR starts_at <= CURRENT_TIMESTAMP)
               AND (ends_at IS NULL OR ends_at >= CURRENT_TIMESTAMP)
             LIMIT 1",
            [$organizationId, $moduleKey]
        );
    }

    private static function assignmentCovers(int $assignmentId, int $organizationId, ?array $resource): bool
    {
        $scopeCount = (int)Database::scalar(
            'SELECT COUNT(*) FROM core_role_assignment_scopes WHERE role_assignment_id = ? AND organization_id = ?',
            [$assignmentId, $organizationId]
        );
        if ($scopeCount === 0) return true;

        if ($resource !== null && isset($resource['scope_id'])) {
            return (bool)Database::scalar(
                "SELECT 1 FROM core_role_assignment_scopes ras
                 JOIN core_scopes s ON s.id = ras.scope_id AND s.organization_id = ras.organization_id
                 WHERE ras.role_assignment_id = ? AND ras.organization_id = ? AND ras.scope_id = ? AND s.status = 'ACTIVE' LIMIT 1",
                [$assignmentId, $organizationId, (int)$resource['scope_id']]
            );
        }

        if ($resource !== null && isset($resource['scope_type'])) {
            $params = [$assignmentId, $organizationId, strtoupper((string)$resource['scope_type'])];
            $sql = "SELECT 1 FROM core_role_assignment_scopes ras
                    JOIN core_scopes s ON s.id = ras.scope_id AND s.organization_id = ras.organization_id
                    WHERE ras.role_assignment_id = ? AND ras.organization_id = ?
                      AND s.scope_type = ? AND s.status = 'ACTIVE'";
            if (isset($resource['resource_type'])) { $sql .= ' AND s.resource_type = ?'; $params[] = (string)$resource['resource_type']; }
            if (isset($resource['resource_id'])) { $sql .= ' AND s.resource_id = ?'; $params[] = (string)$resource['resource_id']; }
            $sql .= ' LIMIT 1';
            return (bool)Database::scalar($sql, $params);
        }

        return (bool)Database::scalar(
            "SELECT 1 FROM core_role_assignment_scopes ras
             JOIN core_scopes s ON s.id = ras.scope_id AND s.organization_id = ras.organization_id
             WHERE ras.role_assignment_id = ? AND ras.organization_id = ?
               AND s.scope_type = 'ORGANIZATION' AND s.status = 'ACTIVE' LIMIT 1",
            [$assignmentId, $organizationId]
        );
    }

    private static function ready(): bool
    {
        foreach ([
            'core_memberships', 'core_organizations', 'core_permissions', 'core_modules',
            'core_roles', 'core_role_permissions', 'core_role_assignments',
            'core_module_entitlements', 'core_role_assignment_scopes', 'core_scopes',
        ] as $table) {
            if (!Database::tableExists($table)) return false;
        }
        return true;
    }
}
