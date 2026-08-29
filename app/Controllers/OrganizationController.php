<?php

declare(strict_types=1);

namespace Khatauat\Controllers;

use InvalidArgumentException;
use Khatauat\Core\Auth;
use Khatauat\Core\Csrf;
use Khatauat\Core\TenantAccessException;
use Khatauat\Core\TenantContext;
use Khatauat\Services\MembershipService;
use Khatauat\Services\OrganizationService;
use RuntimeException;
use Throwable;

/**
 * Minimal JSON boundary for the Phase 1B organization context.
 * The existing public Steps pages are intentionally not changed here.
 */
final class OrganizationController
{
    public function index(): void
    {
        Auth::requireUser();
        $userId = (int)Auth::id();
        $service = new OrganizationService();
        \json_response([
            'ok' => true,
            'organizations' => $service->listForUser($userId),
            'organization_types' => $service->organizationTypes(),
            'active' => TenantContext::current($userId),
        ]);
    }

    public function create(): void
    {
        Auth::requireUser();
        Csrf::verify();
        try {
            $organization = (new OrganizationService())->createForUser(
                (int)Auth::id(),
                (string)($_POST['party_kind'] ?? ''),
                (string)($_POST['organization_type_key'] ?? ''),
                (string)($_POST['display_name'] ?? ''),
                [
                    'organization_uuid' => $_POST['organization_uuid'] ?? null,
                    'legal_name' => $_POST['legal_name'] ?? null,
                    'trade_name' => $_POST['trade_name'] ?? null,
                    'unified_number' => $_POST['unified_number'] ?? null,
                    'commercial_registration_number' => $_POST['commercial_registration_number'] ?? null,
                    'country_code' => $_POST['country_code'] ?? 'SA',
                ]
            );
            \json_response(['ok' => true, 'organization' => $organization], 201);
        } catch (InvalidArgumentException $e) {
            \json_response(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (TenantAccessException $e) {
            \json_response(['ok' => false, 'error' => $e->getMessage()], 409);
        } catch (RuntimeException $e) {
            \json_response(['ok' => false, 'error' => $e->getMessage()], 503);
        }
    }

    public function switchContext(): void
    {
        Auth::requireUser();
        Csrf::verify();
        $organizationId = (int)($_POST['organization_id'] ?? 0);
        try {
            $organization = TenantContext::switchForUser((int)Auth::id(), $organizationId);
            \json_response(['ok' => true, 'active' => $organization]);
        } catch (TenantAccessException $e) {
            \json_response(['ok' => false, 'error' => $e->getMessage()], 403);
        } catch (Throwable $e) {
            \json_response(['ok' => false, 'error' => 'تعذر تغيير المنظمة الحالية.'], 503);
        }
    }

    public function members(string $organizationId): void
    {
        Auth::requireUser();
        try {
            \json_response([
                'ok' => true,
                'members' => (new MembershipService())->membersForOrganization((int)Auth::id(), (int)$organizationId),
            ]);
        } catch (TenantAccessException $e) {
            \json_response(['ok' => false, 'error' => $e->getMessage()], 403);
        } catch (Throwable) {
            \json_response(['ok' => false, 'error' => 'تعذر قراءة أعضاء المنظمة.'], 503);
        }
    }

    public function inviteMember(): void
    {
        Auth::requireUser();
        Csrf::verify();
        try {
            $member = (new MembershipService())->inviteExistingUser(
                (int)Auth::id(),
                (int)($_POST['organization_id'] ?? 0),
                (string)($_POST['email'] ?? '')
            );
            \json_response(['ok' => true, 'member' => $member], 201);
        } catch (InvalidArgumentException $e) {
            \json_response(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (TenantAccessException $e) {
            \json_response(['ok' => false, 'error' => $e->getMessage()], 403);
        } catch (Throwable) {
            \json_response(['ok' => false, 'error' => 'تعذر إنشاء الدعوة.'], 503);
        }
    }

    public function changeMemberStatus(): void
    {
        Auth::requireUser();
        Csrf::verify();
        try {
            $member = (new MembershipService())->changeStatus(
                (int)Auth::id(),
                (int)($_POST['membership_id'] ?? 0),
                (string)($_POST['status'] ?? ''),
                (string)($_POST['reason'] ?? '')
            );
            \json_response(['ok' => true, 'member' => $member]);
        } catch (InvalidArgumentException $e) {
            \json_response(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (TenantAccessException $e) {
            \json_response(['ok' => false, 'error' => $e->getMessage()], 403);
        } catch (Throwable) {
            \json_response(['ok' => false, 'error' => 'تعذر تحديث حالة العضوية.'], 503);
        }
    }
}
