<?php

declare(strict_types=1);

namespace Khatauat\Services;

use InvalidArgumentException;
use Khatauat\Core\Authorization;
use Khatauat\Core\Database;
use Khatauat\Core\TenantAccessException;
use Khatauat\Core\TenantContext;
use RuntimeException;

/**
 * Tenant membership lifecycle for known platform users.
 *
 * Email delivery and external invitations are intentionally out of scope for
 * this slice. An unknown email is rejected rather than creating an orphaned
 * membership record; a later invite-token migration can add that workflow.
 */
final class MembershipService
{
    public function membersForOrganization(int $actorUserId, int $organizationId): array
    {
        Authorization::require($actorUserId, 'core.organization.view', $organizationId);
        return Database::fetchAll(
            "SELECT m.id AS membership_id, m.organization_id, m.user_id,
                    m.membership_status, m.invited_by_user_id, m.joined_at,
                    m.suspended_at, m.ended_at, m.end_reason,
                    u.name, u.email, u.role AS platform_role,
                    (SELECT COUNT(*) FROM core_role_assignments ra
                     WHERE ra.membership_id = m.id AND ra.organization_id = m.organization_id
                       AND ra.assignment_status = 'ACTIVE') AS active_role_count
             FROM core_memberships m
             JOIN users u ON u.id = m.user_id
             WHERE m.organization_id = ?
             ORDER BY CASE m.membership_status WHEN 'ACTIVE' THEN 0 WHEN 'INVITED' THEN 1 ELSE 2 END,
                      u.name COLLATE NOCASE, m.id",
            [$organizationId]
        );
    }

    public function inviteExistingUser(int $actorUserId, int $organizationId, string $email): array
    {
        Authorization::require($actorUserId, 'core.member.invite', $organizationId);
        $email = trim($email);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('البريد الإلكتروني غير صالح.');

        $user = Database::fetch('SELECT id, name, email FROM users WHERE lower(email)=lower(?) LIMIT 1', [$email]);
        if (!$user) throw new InvalidArgumentException('الدعوات الخارجية غير مفعلة؛ يجب أن يكون المستخدم مسجلًا أولًا.');
        if ((int)$user['id'] === $actorUserId) throw new InvalidArgumentException('المستخدم عضو بالفعل بصفته منشئ المنظمة.');

        $existing = Database::fetch(
            'SELECT * FROM core_memberships WHERE organization_id = ? AND user_id = ? LIMIT 1',
            [$organizationId, (int)$user['id']]
        );
        if ($existing) {
            if ($existing['membership_status'] === 'ENDED') {
                Database::execute(
                    "UPDATE core_memberships SET membership_status='INVITED', invited_by_user_id=?,
                            ended_at=NULL, end_reason=NULL, updated_at=CURRENT_TIMESTAMP
                     WHERE id=? AND organization_id=?",
                    [$actorUserId, (int)$existing['id'], $organizationId]
                );
                $existing['membership_status'] = 'INVITED';
                $existing['invited_by_user_id'] = $actorUserId;
            }
            return $this->member($organizationId, (int)$user['id'], true);
        }

        Database::execute(
            "INSERT INTO core_memberships(organization_id,user_id,membership_status,invited_by_user_id)
             VALUES(?,?,'INVITED',?)",
            [$organizationId, (int)$user['id'], $actorUserId]
        );
        return $this->member($organizationId, (int)$user['id'], false);
    }

    public function changeStatus(int $actorUserId, int $membershipId, string $status, string $reason = ''): array
    {
        $status = strtoupper(trim($status));
        if (!in_array($status, ['ACTIVE', 'SUSPENDED', 'ENDED'], true)) {
            throw new InvalidArgumentException('حالة العضوية غير مدعومة.');
        }
        $membership = Database::fetch(
            'SELECT * FROM core_memberships WHERE id = ? LIMIT 1',
            [$membershipId]
        );
        if (!$membership) throw new InvalidArgumentException('العضوية غير موجودة.');
        $organizationId = (int)$membership['organization_id'];
        $permission = $status === 'ACTIVE' ? 'core.member.invite' : 'core.member.remove';
        Authorization::require($actorUserId, $permission, $organizationId);

        if ((int)$membership['user_id'] === $actorUserId && $status !== 'ACTIVE') {
            $activeCount = (int)Database::scalar(
                "SELECT COUNT(*) FROM core_memberships WHERE organization_id=? AND membership_status='ACTIVE'",
                [$organizationId]
            );
            if ($activeCount <= 1) throw new InvalidArgumentException('لا يمكن إنهاء عضوية المالك الوحيد.');
        }

        $reason = trim($reason);
        if (mb_strlen($reason) > 240) throw new InvalidArgumentException('سبب التغيير طويل جدًا.');
        Database::execute(
            "UPDATE core_memberships
             SET membership_status=?, joined_at=CASE WHEN ?='ACTIVE' THEN COALESCE(joined_at,CURRENT_TIMESTAMP) ELSE joined_at END,
                 suspended_at=CASE WHEN ?='SUSPENDED' THEN CURRENT_TIMESTAMP ELSE NULL END,
                 ended_at=CASE WHEN ?='ENDED' THEN CURRENT_TIMESTAMP ELSE NULL END,
                 end_reason=CASE WHEN ?='ENDED' THEN NULLIF(?, '') ELSE NULL END,
                 updated_at=CURRENT_TIMESTAMP
             WHERE id=? AND organization_id=?",
            [$status, $status, $status, $status, $status, $reason, $membershipId, $organizationId]
        );
        return $this->member($organizationId, (int)$membership['user_id'], false);
    }

    private function member(int $organizationId, int $userId, bool $replayed): array
    {
        $member = Database::fetch(
            "SELECT m.id AS membership_id, m.organization_id, m.user_id,
                    m.membership_status, m.invited_by_user_id, m.joined_at,
                    m.suspended_at, m.ended_at, m.end_reason,
                    u.name, u.email, u.role AS platform_role
             FROM core_memberships m JOIN users u ON u.id=m.user_id
             WHERE m.organization_id=? AND m.user_id=? LIMIT 1",
            [$organizationId, $userId]
        );
        if (!$member) throw new RuntimeException('تعذر قراءة العضوية بعد التعديل.');
        $member['idempotent_replay'] = $replayed;
        return $member;
    }
}
