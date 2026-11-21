<?php

declare(strict_types=1);

namespace App\Actions\Teams;

use App\Models\Team;
use App\Models\User;
use App\Support\Authorization\ManagedAccessGuard;
use App\Support\Authorization\RowAccess\TeamAccessRuleCoverage;
use App\Support\Http\IdentifierList;
use App\Support\Teams\TeamMembershipDirectory;
use App\Support\Tenancy\TenantUserIdResolver;
use App\Support\Users\ManageableUserDirectory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

class SyncTeamMembersAction
{
    public function __construct(
        private readonly TenantUserIdResolver $tenantUserIds,
        private readonly ManagedAccessGuard $accessGuard,
        private readonly TeamMembershipDirectory $memberships,
        private readonly TeamAccessRuleCoverage $ruleCoverage,
        private readonly ManageableUserDirectory $users,
    ) {}

    /**
     * @param  list<string>  $userIds
     *
     * @throws Throwable
     */
    public function syncMembersOfTeam(User $actingUser, Team $team, array $userIds): void
    {
        Validator::make(['member_ids' => $userIds], [
            'member_ids' => ['array'],
            'member_ids.*' => ['string', 'ulid'],
        ])->validate();

        $members = $this->tenantUserIds->resolve($team->tenant_id, $userIds);
        $current = $this->memberships->memberIdsOf($team);

        if ($this->changedIds($current, $members) !== []) {
            $actingUserId = [(string) $actingUser->getKey()];

            $this->accessGuard->assertOwnAccessUnchanged(
                $actingUser,
                $actingUser,
                array_values(array_intersect($current, $actingUserId)),
                array_values(array_intersect($members, $actingUserId)),
                'member_ids',
            );

            $this->accessGuard->assertMayChangeTeams($actingUser, [(string) $team->getKey()], 'teams.update');
            $this->accessGuard->assertMaySteerAccounts($actingUser, $this->changedIds($current, $members), 'member_ids');
            $this->accessGuard->assertManagesAccounts($actingUser, array_values(array_diff($members, $current)), 'members.update', 'member_ids');

            if (array_diff($current, $members) !== []) {
                $this->ruleCoverage->assertMayRewriteRulesAlong($actingUser, $team, $this->ruleCoverage->chainOf($team), 'member_ids');
            }

            $this->assertJoinersKeepTheirRules($actingUser, $team, array_values(array_diff($members, $current)));
        }

        DB::transaction(static function () use ($team, $members): void {
            $team->users()->sync($members);
        });
    }

    /**
     * @param  list<string>  $teamIds
     *
     * @throws Throwable
     */
    public function syncTeamsOfUser(User $actingUser, User $user, array $teamIds, string $ability): void
    {
        $teams = $this->memberships->livingTeamIdsOfTenant((string) $user->tenant_id, $teamIds);
        $current = $this->memberships->teamIdsOf($user);

        $this->accessGuard->assertMayChangeTeams($actingUser, $this->changedIds($current, $teams), $ability);

        $leftTeams = $this->memberships->livingTeamsOfTenant((string) $user->tenant_id, array_values(array_diff($current, $teams)));

        foreach ($leftTeams as $leftTeam) {
            $this->ruleCoverage->assertMayRewriteRulesAlong($actingUser, $leftTeam, $this->ruleCoverage->chainOf($leftTeam), 'team_ids');
        }

        $joinedTeams = $this->memberships->livingTeamsOfTenant((string) $user->tenant_id, array_values(array_diff($teams, $current)));

        if ($joinedTeams->isNotEmpty()) {
            $currentTeams = $this->memberships->livingTeamsOfTenant((string) $user->tenant_id, $current);

            foreach ($joinedTeams as $joinedTeam) {
                $this->ruleCoverage->assertMayJoin($actingUser, $joinedTeam, $currentTeams, 'team_ids');
            }
        }

        DB::transaction(static function () use ($user, $teams): void {
            $user->teams()->sync($teams);
        });
    }

    /**
     * @param  list<string>  $joinerIds
     *
     * @throws ValidationException
     */
    private function assertJoinersKeepTheirRules(User $actingUser, Team $team, array $joinerIds): void
    {
        if ($joinerIds === []) {
            return;
        }

        $teamIdsByUser = $this->users->teamIdsByUser($joinerIds);
        $currentTeams = $this->memberships
            ->livingTeamsOfTenant((string) $team->tenant_id, IdentifierList::from(array_merge([], ...array_values($teamIdsByUser))));

        foreach ($teamIdsByUser as $teamIds) {
            $this->ruleCoverage->assertMayJoin($actingUser, $team, $currentTeams->only($teamIds), 'member_ids');
        }
    }

    /**
     * @param  list<string>  $current
     * @param  list<string>  $submitted
     * @return list<string>
     */
    private function changedIds(array $current, array $submitted): array
    {
        return [
            ...array_diff($current, $submitted),
            ...array_diff($submitted, $current),
        ];
    }
}
