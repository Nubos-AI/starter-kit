<?php

declare(strict_types=1);

namespace App\Http\Controllers\Users;

use App\Actions\Users\InviteUsersAction;
use App\Actions\Users\ResendInvitationAction;
use App\DTOs\Users\InvitationResultData;
use App\Enums\Ui\ToastType;
use App\Http\Controllers\Abstracts\Controller;
use App\Support\Authorization\AssignableRoleOptions;
use App\Support\Http\IdentifierList;
use App\Support\Teams\TenantTeamOptions;
use App\Support\Users\TenantUserResolver;
use App\Traits\Authorization\AuthorizesRoleAssignments;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class UserInvitationsController extends Controller
{
    use AuthorizesRoleAssignments;

    public function __construct(
        private readonly TenantUserResolver $tenantUsers,
        private readonly InviteUsersAction $inviteUsers,
        private readonly ResendInvitationAction $resendInvitation,
        private readonly AssignableRoleOptions $assignableRoles,
        private readonly TenantTeamOptions $assignableTeams,
    ) {}

    public function create(Request $request): InertiaResponse
    {
        $actingUser = $this->actingUser($request);

        return Inertia::render('users/Invite', [
            'roles' => $this->assignableRoles->forUser($request, $actingUser),
            'teams' => $this->assignableTeams->forUser($actingUser),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $actingUser = $this->actingUser($request);
        $validated = $request->all();

        $roleIds = IdentifierList::from($validated['role_ids'] ?? []);

        $this->authorizeRoleAssignments($roleIds);

        $result = $this->inviteUsers->execute($actingUser, [
            'emails' => (string) ($validated['emails'] ?? ''),
            'role_ids' => $roleIds,
            'team_ids' => IdentifierList::from($validated['team_ids'] ?? []),
        ]);

        Inertia::flash('toast', [
            'type' => $result->invited === [] && $result->renewed === []
                ? ToastType::Warning->value
                : ToastType::Success->value,
            'message' => $this->summary($result),
        ]);

        return to_route('engine.users.index');
    }

    public function resend(Request $request, string $user): RedirectResponse
    {
        $actingUser = $this->actingUser($request);
        $target = $this->tenantUsers->resolve($actingUser, $user);
        $this->resendInvitation->execute($target, $actingUser);

        Inertia::flash('toast', ['type' => ToastType::Success->value, 'message' => __('i18n.backend.http.controllers.users.user_invitations_controller.invitation_resent')]);

        return to_route('engine.users.index');
    }

    private function summary(InvitationResultData $result): string
    {
        $parts = [];

        if ($result->invited !== []) {
            $parts[] = count($result->invited).' eingeladen';
        }

        if ($result->renewed !== []) {
            $parts[] = count($result->renewed).__('i18n.backend.http.controllers.users.user_invitations_controller.invited_again');
        }

        if ($result->skipped !== []) {
            $parts[] = count($result->skipped).__('i18n.backend.http.controllers.users.user_invitations_controller.skipped');
        }

        $summary = implode(', ', $parts);

        return "{$summary}.";
    }
}
