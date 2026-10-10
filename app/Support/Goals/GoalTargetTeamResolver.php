<?php

declare(strict_types=1);

namespace App\Support\Goals;

use App\Models\Goal;
use App\Models\Team;
use App\Scopes\TenantScope;

class GoalTargetTeamResolver
{
    public function resolve(Goal $goal): ?Team
    {
        $team = Team::query()
            ->withoutGlobalScopes([TenantScope::class])
            ->where('tenant_id', $goal->tenant_id)
            ->whereKey($goal->target_team_id)
            ->first();

        return $team instanceof Team ? $team : null;
    }
}
