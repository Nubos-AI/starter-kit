<?php

declare(strict_types=1);

namespace App\Support\Authorization;

use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;

class CurrentTeamResolver
{
    public function resolve(?User $user = null): ?Team
    {
        if ($this->isContextSubject($user)) {
            if (app()->bound('current_team')) {
                $team = app('current_team');

                if ($team instanceof Team) {
                    return $team;
                }
            }

            if (Context::hasHidden('team_id')) {
                $teamId = Context::getHidden('team_id');

                return is_string($teamId) ? Team::withoutTenantScope()->find($teamId) : null;
            }
        }

        if ($user?->current_team_id === null) {
            return null;
        }

        return $this->memberTeamQuery($user)
            ->whereKey($user->current_team_id)
            ->first();
    }

    public function resolveKey(?User $user = null): ?string
    {
        $team = $this->resolve($user);

        return $team === null ? null : (string) $team->getKey();
    }

    private function isContextSubject(?User $user): bool
    {
        if ($user === null) {
            return true;
        }

        $contextUser = Auth::user();

        if ($contextUser instanceof User) {
            return $contextUser->is($user);
        }

        $teamId = Context::getHidden('team_id');

        return is_string($teamId) && $this->memberTeamQuery($user)->whereKey($teamId)->exists();
    }

    /**
     * @return Builder<Team>
     */
    private function memberTeamQuery(User $user): Builder
    {
        return Team::withoutTenantScope()
            ->where('tenant_id', $user->tenant_id)
            ->whereHas('users', static fn (Builder $members): Builder => $members
                ->withoutGlobalScopes()
                ->whereKey($user->getKey()));
    }
}
