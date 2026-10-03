<?php

declare(strict_types=1);

namespace App\Support\Governance;

use App\Enums\Users\UserStatus;
use App\Models\RoleAssignment;
use App\Models\Team;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class CandidateDirectory
{
    /**
     * @param  list<string>  $roleIds
     * @return list<string>
     */
    public function roleHolderIds(array $roleIds, string $tenantId, ?string $teamId): array
    {
        if ($roleIds === []) {
            return [];
        }

        /** @var list<string> $assignedIds */
        $assignedIds = RoleAssignment::query()
            ->whereIn('role_id', $roleIds)
            ->where('model_type', (new User)->getMorphClass())
            ->where(static function (Builder $query) use ($tenantId, $teamId): void {
                $query
                    ->whereNull('scope_id')
                    ->orWhere(static function (Builder $tenantScope) use ($tenantId): void {
                        $tenantScope
                            ->where('scope_type', (new Tenant)->getMorphClass())
                            ->where('scope_id', $tenantId);
                    });

                if ($teamId === null) {
                    return;
                }

                $query->orWhere(static function (Builder $teamScope) use ($teamId): void {
                    $teamScope
                        ->where('scope_type', (new Team)->getMorphClass())
                        ->where('scope_id', $teamId);
                });
            })
            ->pluck('model_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->unique()
            ->values()
            ->all();

        return $assignedIds;
    }

    /**
     * @param  list<string>  $teamIds
     * @return list<string>
     */
    public function memberIdsOfTeams(array $teamIds, string $tenantId): array
    {
        if ($teamIds === []) {
            return [];
        }

        /** @var list<string> $memberIds */
        $memberIds = Team::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->whereKey($teamIds)
            ->with('users')
            ->get()
            ->flatMap(static fn (Team $team): Collection => $team->users->map(
                static fn (User $user): string => (string) $user->getKey(),
            ))
            ->unique()
            ->values()
            ->all();

        return $memberIds;
    }

    /**
     * @param  list<string>  $userIds
     * @return Collection<int, User>
     */
    public function activeUsers(array $userIds, string $tenantId): Collection
    {
        if ($userIds === []) {
            return new Collection;
        }

        return User::query()
            ->where('tenant_id', $tenantId)
            ->where('is_service', false)
            ->where('status', UserStatus::Accepted)
            ->whereKey($userIds)
            ->orderBy('id')
            ->get();
    }
}
