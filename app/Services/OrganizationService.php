<?php

declare(strict_types=1);

namespace Khatauat\Services;

use InvalidArgumentException;
use Khatauat\Core\Database;
use Khatauat\Core\TenantAccessException;
use Khatauat\Core\TenantContext;
use PDOException;
use Throwable;

/**
 * Phase 1B organization onboarding and membership bootstrap.
 *
 * A creator receives one tenant-scoped owner role only. This never changes
 * the existing platform owner guard and never grants platform permissions.
 */
final class OrganizationService
{
    private const REQUIRED_TABLES = [
        'core_organization_types',
        'core_organizations',
        'core_organization_settings',
        'core_memberships',
        'core_modules',
        'core_permissions',
        'core_roles',
        'core_role_permissions',
        'core_role_assignments',
        'core_scopes',
        'core_role_assignment_scopes',
        'core_module_entitlements',
    ];

    public function listForUser(int $userId): array
    {
        return TenantContext::organizationsForUser($userId);
    }

    public function organizationTypes(?string $partyKind = null): array
    {
        if (!Database::tableExists('core_organization_types')) return [];
        $params = [];
        $where = "status = 'ACTIVE'";
        if ($partyKind !== null) {
            $partyKind = $this->partyKind($partyKind);
            $where .= " AND (party_kind_policy = 'BOTH' OR party_kind_policy = ? )";
            $params[] = $partyKind;
        }
        return Database::fetchAll("SELECT * FROM core_organization_types WHERE {$where} ORDER BY sort_order, type_key", $params);
    }

    /**
     * @param array{organization_uuid?:string,legal_name?:string,trade_name?:string,
     *             unified_number?:string,commercial_registration_number?:string,
     *             country_code?:string,metadata?:array<string,mixed>} $options
     */
    public function createForUser(
        int $userId,
        string $partyKind,
        string $organizationTypeKey,
        string $displayName,
        array $options = []
    ): array {
        $this->assertReady();
        if (!Database::fetch('SELECT id FROM users WHERE id = ? LIMIT 1', [$userId])) {
            throw new InvalidArgumentException('المستخدم المطلوب غير موجود.');
        }

        $partyKind = $this->partyKind($partyKind);
        $organizationTypeKey = strtoupper(trim($organizationTypeKey));
        $displayName = trim($displayName);
        if ($displayName === '' || mb_strlen($displayName) > 160) {
            throw new InvalidArgumentException('اسم المنظمة مطلوب وبحد أقصى 160 حرفًا.');
        }

        $type = Database::fetch(
            "SELECT type_key, party_kind_policy FROM core_organization_types
             WHERE type_key = ? AND status = 'ACTIVE' LIMIT 1",
            [$organizationTypeKey]
        );
        if (!$type) throw new InvalidArgumentException('نوع المنظمة غير متاح حاليًا.');
        if ($type['party_kind_policy'] !== 'BOTH' && $type['party_kind_policy'] !== $partyKind) {
            throw new InvalidArgumentException('نوع المنظمة لا يتوافق مع فرد/شركة.');
        }

        $organizationUuid = trim((string)($options['organization_uuid'] ?? ''));
        if ($organizationUuid === '') $organizationUuid = self::uuid4();
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $organizationUuid)) {
            throw new InvalidArgumentException('organization_uuid غير صالح.');
        }

        $countryCode = strtoupper(trim((string)($options['country_code'] ?? 'SA')));
        if (!preg_match('/^[A-Z]{2}$/', $countryCode)) {
            throw new InvalidArgumentException('رمز الدولة يجب أن يتكون من حرفين.');
        }

        $legalName = $this->optionalText($options['legal_name'] ?? null, 200);
        $tradeName = $this->optionalText($options['trade_name'] ?? null, 200);
        $unifiedNumber = $this->optionalText($options['unified_number'] ?? null, 80);
        $commercialRegistration = $this->optionalText($options['commercial_registration_number'] ?? null, 80);
        $metadata = $options['metadata'] ?? [];
        if (!is_array($metadata)) throw new InvalidArgumentException('metadata يجب أن يكون كائنًا.');
        $metadataJson = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        try {
            return Database::immediate(function () use (
                $userId, $partyKind, $organizationTypeKey, $displayName, $organizationUuid,
                $legalName, $tradeName, $unifiedNumber, $commercialRegistration,
                $countryCode, $metadataJson
            ): array {
                $existing = Database::fetch(
                    'SELECT id FROM core_organizations WHERE organization_uuid = ? LIMIT 1',
                    [$organizationUuid]
                );
                if ($existing) {
                    $member = Database::fetch(
                        "SELECT m.id AS membership_id, o.*
                         FROM core_memberships m JOIN core_organizations o ON o.id = m.organization_id
                         WHERE m.organization_id = ? AND m.user_id = ? AND m.membership_status = 'ACTIVE'
                         LIMIT 1",
                        [(int)$existing['id'], $userId]
                    );
                    if (!$member) throw new TenantAccessException('معرّف المنظمة مستخدم من كيان آخر.');
                    return $this->withIdempotentFlag($member, true);
                }

                Database::execute(
                    "INSERT INTO core_organizations
                        (organization_uuid,party_kind,organization_type_key,display_name,legal_name,
                         trade_name,unified_number,commercial_registration_number,country_code,
                         status,created_by_user_id)
                     VALUES (?,?,?,?,?,?,?,?,?,'ACTIVE',?)",
                    [
                        $organizationUuid, $partyKind, $organizationTypeKey, $displayName, $legalName,
                        $tradeName, $unifiedNumber, $commercialRegistration, $countryCode, $userId,
                    ]
                );
                $organizationId = Database::lastInsertId();

                Database::execute(
                    "INSERT INTO core_organization_settings
                        (organization_id,setting_key,value_json,is_sensitive,created_by_user_id,updated_by_user_id)
                     VALUES (?, 'onboarding', ?, 0, ?, ?)",
                    [$organizationId, json_encode(['status' => 'complete', 'version' => '1', 'metadata' => json_decode($metadataJson, true)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $userId, $userId]
                );
                Database::execute(
                    "INSERT INTO core_memberships
                        (organization_id,user_id,membership_status,invited_by_user_id,joined_at)
                     VALUES (?,?,'ACTIVE',?,CURRENT_TIMESTAMP)",
                    [$organizationId, $userId, $userId]
                );
                $membershipId = Database::lastInsertId();

                Database::execute(
                    "INSERT INTO core_roles
                        (organization_id,role_key,name,description,role_kind,created_by_user_id)
                     VALUES (?, 'org_owner', 'مالك المنظمة', 'الدور الإداري الأساسي داخل هذه المنظمة فقط', 'SYSTEM', ?)",
                    [$organizationId, $userId]
                );
                $roleId = Database::lastInsertId();

                $permissionKeys = [
                    'core.organization.view',
                    'core.organization.manage',
                    'core.member.invite',
                    'core.member.remove',
                    'core.location.manage',
                ];
                foreach ($permissionKeys as $permissionKey) {
                    $permission = Database::fetch('SELECT id FROM core_permissions WHERE permission_key = ? AND status = \'ACTIVE\'', [$permissionKey]);
                    if (!$permission) throw new RuntimeException('صلاحية النواة غير موجودة: ' . $permissionKey);
                    Database::execute(
                        "INSERT INTO core_role_permissions(role_id,organization_id,permission_id,effect,created_by_user_id)
                         VALUES(?,?,?,'ALLOW',?)",
                        [$roleId, $organizationId, (int)$permission['id'], $userId]
                    );
                }

                Database::execute(
                    "INSERT INTO core_role_assignments
                        (organization_id,membership_id,role_id,assignment_status,assigned_by_user_id)
                     VALUES (?, ?, ?, 'ACTIVE', ?)",
                    [$organizationId, $membershipId, $roleId, $userId]
                );
                $assignmentId = Database::lastInsertId();

                Database::execute(
                    "INSERT INTO core_scopes(organization_id,scope_key,scope_type,status)
                     VALUES(?,?,'ORGANIZATION','ACTIVE')",
                    [$organizationId, 'organization:' . $organizationUuid]
                );
                $scopeId = Database::lastInsertId();
                Database::execute(
                    "INSERT INTO core_role_assignment_scopes(role_assignment_id,organization_id,scope_id,created_by_user_id)
                     VALUES(?,?,?,?)",
                    [$assignmentId, $organizationId, $scopeId, $userId]
                );

                Database::execute(
                    "INSERT INTO core_module_entitlements
                        (organization_id,module_key,entitlement_status,source_type,source_reference,granted_by_user_id)
                     VALUES(?, 'core', 'ACTIVE', 'MANUAL', 'phase1b-bootstrap', ?)",
                    [$organizationId, $userId]
                );

                return $this->withIdempotentFlag(
                    Database::fetch(
                        "SELECT o.*, m.id AS membership_id, m.membership_status, m.joined_at
                         FROM core_organizations o JOIN core_memberships m ON m.organization_id = o.id
                         WHERE o.id = ? AND m.user_id = ? LIMIT 1",
                        [$organizationId, $userId]
                    ) ?? [],
                    false
                );
            });
        } catch (PDOException $e) {
            throw new RuntimeException('تعذر إنشاء المنظمة؛ تحقق من البيانات الفريدة ثم أعد المحاولة.', 0, $e);
        }
    }

    private function assertReady(): void
    {
        foreach (self::REQUIRED_TABLES as $table) {
            if (!Database::tableExists($table)) {
                throw new RuntimeException('مكوّن المنظمات غير مفعّل بعد: ' . $table);
            }
        }
    }

    private function partyKind(string $value): string
    {
        $value = strtoupper(trim($value));
        if (!in_array($value, ['INDIVIDUAL', 'COMPANY'], true)) {
            throw new InvalidArgumentException('party_kind يجب أن يكون INDIVIDUAL أو COMPANY.');
        }
        return $value;
    }

    private function optionalText(mixed $value, int $max): ?string
    {
        if ($value === null) return null;
        $value = trim((string)$value);
        if ($value === '') return null;
        if (mb_strlen($value) > $max) throw new InvalidArgumentException('قيمة نصية أطول من الحد المسموح.');
        return $value;
    }

    private function withIdempotentFlag(array $organization, bool $idempotent): array
    {
        $organization['idempotent_replay'] = $idempotent;
        return $organization;
    }

    private static function uuid4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
