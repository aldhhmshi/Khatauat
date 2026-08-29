<?php

declare(strict_types=1);

namespace Khatauat\Core;

use RuntimeException;

/**
 * Resolves the authenticated user's active organization context.
 *
 * This class is deliberately fail-closed: if the Phase 1A tenant tables are
 * not installed, no organization is considered active and no session value
 * can grant access by itself.
 */
final class TenantContext
{
    private const SESSION_ORGANIZATION = '_khatauat_active_organization_id';
    private const SESSION_USER = '_khatauat_active_organization_user_id';
    private const SESSION_CHANGED_AT = '_khatauat_active_organization_changed_at';

    public static function organizationsForUser(int $userId): array
    {
        if ($userId < 1 || !self::ready()) return [];

        return Database::fetchAll(
            "SELECT o.id, o.organization_uuid, o.party_kind, o.organization_type_key,
                    o.display_name, o.legal_name, o.trade_name, o.country_code,
                    o.status, o.created_at, o.updated_at,
                    m.id AS membership_id, m.membership_status, m.joined_at,
                    ot.name_ar AS organization_type_name_ar,
                    ot.name_en AS organization_type_name_en,
                    ot.sector_key
             FROM core_memberships m
             JOIN core_organizations o ON o.id = m.organization_id
             JOIN core_organization_types ot ON ot.type_key = o.organization_type_key
             WHERE m.user_id = ?
               AND m.membership_status = 'ACTIVE'
               AND o.status = 'ACTIVE'
               AND o.deleted_at IS NULL
             ORDER BY o.display_name COLLATE NOCASE, o.id",
            [$userId]
        );
    }

    public static function current(?int $userId = null): ?array
    {
        $userId ??= Auth::id();
        if (!$userId || !self::ready()) {
            self::clear();
            return null;
        }

        $sessionUser = (int)($_SESSION[self::SESSION_USER] ?? 0);
        $organizationId = (int)($_SESSION[self::SESSION_ORGANIZATION] ?? 0);
        if ($organizationId < 1 || ($sessionUser > 0 && $sessionUser !== $userId)) {
            self::clear();
            return null;
        }

        $organization = self::organizationForUser($userId, $organizationId);
        if (!$organization) {
            self::clear();
            return null;
        }

        return $organization;
    }

    public static function resolveActive(int $userId, ?int $requestedOrganizationId = null): ?array
    {
        if ($userId < 1) return null;
        if ($requestedOrganizationId !== null) {
            return self::switchForUser($userId, $requestedOrganizationId);
        }

        $current = self::current($userId);
        if ($current) return $current;

        $organizations = self::organizationsForUser($userId);
        if (count($organizations) !== 1) return null;

        return self::setActive($userId, $organizations[0]);
    }

    public static function switchForUser(int $userId, int $organizationId): array
    {
        if ($userId < 1 || $organizationId < 1 || !self::ready()) {
            throw new TenantAccessException('سياق المنظمة غير متاح قبل تفعيل مكوّن المنظمات.');
        }

        $organization = self::organizationForUser($userId, $organizationId);
        if (!$organization) {
            self::clear();
            throw new TenantAccessException('لا تملك عضوية نشطة في هذه المنظمة.');
        }

        return self::setActive($userId, $organization);
    }

    public static function requireActive(int $userId, ?int $requestedOrganizationId = null): array
    {
        $organization = self::resolveActive($userId, $requestedOrganizationId);
        if (!$organization) {
            throw new TenantAccessException('اختر منظمة نشطة قبل متابعة العملية.');
        }
        return $organization;
    }

    public static function clear(): void
    {
        unset(
            $_SESSION[self::SESSION_ORGANIZATION],
            $_SESSION[self::SESSION_USER],
            $_SESSION[self::SESSION_CHANGED_AT]
        );
    }

    public static function id(?int $userId = null): ?int
    {
        return self::current($userId)['id'] ?? null;
    }

    public static function ready(): bool
    {
        foreach ([
            'core_organization_types',
            'core_organizations',
            'core_memberships',
        ] as $table) {
            if (!Database::tableExists($table)) return false;
        }
        return true;
    }

    private static function organizationForUser(int $userId, int $organizationId): ?array
    {
        return Database::fetch(
            "SELECT o.id, o.organization_uuid, o.party_kind, o.organization_type_key,
                    o.display_name, o.legal_name, o.trade_name, o.country_code,
                    o.status, o.created_at, o.updated_at,
                    m.id AS membership_id, m.membership_status, m.joined_at,
                    ot.name_ar AS organization_type_name_ar,
                    ot.name_en AS organization_type_name_en,
                    ot.sector_key
             FROM core_memberships m
             JOIN core_organizations o ON o.id = m.organization_id
             JOIN core_organization_types ot ON ot.type_key = o.organization_type_key
             WHERE m.user_id = ? AND m.organization_id = ?
               AND m.membership_status = 'ACTIVE'
               AND o.status = 'ACTIVE' AND o.deleted_at IS NULL
             LIMIT 1",
            [$userId, $organizationId]
        );
    }

    private static function setActive(int $userId, array $organization): array
    {
        $_SESSION[self::SESSION_ORGANIZATION] = (int)$organization['id'];
        $_SESSION[self::SESSION_USER] = $userId;
        $_SESSION[self::SESSION_CHANGED_AT] = time();
        return $organization;
    }
}
