<?php

declare(strict_types=1);

namespace App\Actions\Teams;

use App\Models\Team;
use App\Models\User;
use App\Support\Authorization\ManagedAccessGuard;
use App\Support\Teams\TeamMembershipDirectory;
use App\Support\Tenancy\TenantUserIdResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

class SyncTeamMembersAction
{
    public function __construct(
        private readonly TenantUserIdResolver $tenantUserIds,
        private readonly ManagedAccessGuard $accessGuard,
        private readonly TeamMembershipDirectory $memberships,
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

        DB::transaction(static function () use ($user, $teams): void {
            $user->teams()->sync($teams);
        });
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
