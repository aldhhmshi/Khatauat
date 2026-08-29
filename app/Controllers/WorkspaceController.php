<?php

declare(strict_types=1);

namespace Khatauat\Controllers;

use Khatauat\Core\Auth;
use Khatauat\Core\Authorization;
use Khatauat\Core\TenantContext;
use Khatauat\Core\View;
use Khatauat\Services\MembershipService;
use Khatauat\Services\OrganizationService;
use Throwable;

/**
 * Internal organization workspace.
 *
 * This page is separate from the public Steps navigation. It exposes only
 * organization context and membership metadata; module content remains
 * behind each module's own entitlement and authorization boundary.
 */
final class WorkspaceController
{
    public function index(): void
    {
        Auth::requireUser();
        $userId = (int)Auth::id();
        $organizationService = new OrganizationService();
        $organizations = $organizationService->listForUser($userId);
        $active = TenantContext::current($userId);
        $members = [];
        $canManageMembers = false;

        if ($active) {
            try {
                $canManageMembers = Authorization::allows($userId, 'core.organization.view', (int)$active['id']);
                if ($canManageMembers) {
                    $members = (new MembershipService())->membersForOrganization($userId, (int)$active['id']);
                }
            } catch (Throwable) {
                // A partially migrated environment must render a safe empty state.
                $members = [];
                $canManageMembers = false;
            }
        }

        View::render('workspace/index', [
            'title' => 'مساحة المنظومة | خطوات',
            'metaDescription' => 'مساحة داخلية لاختيار المنظمة وإدارة العضويات قبل فتح وحدات المنظومة.',
            'organizations' => $organizations,
            'organizationTypes' => $organizationService->organizationTypes(),
            'activeOrganization' => $active,
            'members' => $members,
            'canManageMembers' => $canManageMembers,
            'currentUser' => Auth::user(),
        ]);
    }
}
