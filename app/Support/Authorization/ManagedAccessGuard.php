<?php

declare(strict_types=1);

namespace App\Support\Authorization;

use App\Models\User;
use App\Support\Users\ManageableUserDirectory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

class ManagedAccessGuard
{
    public function __construct(
        private readonly ManagedUserResolver $managedUsers,
        private readonly PermissionSubsetGuard $subsetGuard,
        private readonly AuthorizationDirectory $directory,
        private readonly ManageableUserDirectory $users,
    ) {}

    /**
     * @param  list<string>  $teamIds
     *
     * @throws AuthorizationException
     */
    public function assertMayChangeTeams(User $actingUser, array $teamIds, string $ability): void
    {
        if ($teamIds === []) {
            return;
        }

        $reach = $this->managedUsers->reachOf($actingUser, $ability);

        if (!$reach['tenant'] && array_diff($teamIds, $reach['teams']) !== []) {
            throw new AuthorizationException(__('i18n.backend.support.authorization.managed_access_guard.this_team_is_outside_your_management_reach'));
        }

        foreach ($this->directory->rolesAssignedToTeams($teamIds) as $role) {
            $this->subsetGuard->assertMayAssignRole($role);
        }
    }

    /**
     * @param  list<string>  $userIds
     *
     * @throws ValidationException
     */
    public function assertMaySteerAccounts(User $actingUser, array $userIds, string $field): void
    {
        $others = array_values(array_diff($userIds, [(string) $actingUser->getKey()]));

        if ($others === []) {
            return;
        }

        $steerable = $this->subsetGuard->steerableAccountKeys(
            $actingUser,
            $others,
            $this->users->roleIdsByUser($others),
            $this->users->teamIdsByUser($others),
        );

        if (array_diff($others, $steerable) === []) {
            return;
        }

        throw ValidationException::withMessages([
            $field => __('i18n.backend.support.authorization.managed_access_guard.a_member_holds_more_rights_than_you'),
        ]);
    }

    /**
     * @param  list<string>  $userIds
     *
     * @throws ValidationException
     */
    public function assertManagesAccounts(User $actingUser, array $userIds, string $ability, string $field): void
    {
        $others = array_values(array_diff($userIds, [(string) $actingUser->getKey()]));

        if ($others === []) {
            return;
        }

        foreach ($this->users->accountsOf($others) as $account) {
            if ($this->managedUsers->manages($actingUser, $account, $ability)) {
                continue;
            }

            throw ValidationException::withMessages([
                $field => __('i18n.backend.support.authorization.managed_access_guard.a_selected_person_is_outside_your_management_reach'),
            ]);
        }
    }

    /**
     * @param  list<string>  $current
     * @param  list<string>  $submitted
     *
     * @throws ValidationException
     */
    public function assertOwnAccessUnchanged(User $actingUser, User $target, array $current, array $submitted, string $field): void
    {
        if (!$actingUser->is($target)) {
            return;
        }

        if (array_diff($current, $submitted) === [] && array_diff($submitted, $current) === []) {
            return;
        }

        throw ValidationException::withMessages([
            $field => __('i18n.backend.support.authorization.managed_access_guard.you_cannot_change_your_own_roles_teams_or_denied_permissions'),
        ]);
    }
}
