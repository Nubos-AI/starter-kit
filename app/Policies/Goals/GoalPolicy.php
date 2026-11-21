<?php

declare(strict_types=1);

namespace App\Policies\Goals;

use App\Enums\Goals\GoalScopeType;
use App\Models\Goal;
use App\Models\Report;
use App\Models\Team;
use App\Models\User;
use App\Support\Authorization\TenantBoundary;
use Illuminate\Support\Facades\Gate;

class GoalPolicy
{
    public function __construct(private readonly TenantBoundary $tenantBoundary) {}

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Goal $goal): bool
    {
        if (!$this->tenantBoundary->admits($user, $goal)) {
            return false;
        }

        return $this->maySeeSource($user, $goal) && $this->isEntitled($user, $goal);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Goal $goal): bool
    {
        return $this->view($user, $goal) && $this->mayGovern($user, $goal);
    }

    public function delete(User $user, Goal $goal): bool
    {
        return $this->view($user, $goal) && $this->mayGovern($user, $goal);
    }

    private function maySeeSource(User $user, Goal $goal): bool
    {
        $report = $goal->report;

        return $report instanceof Report && Gate::forUser($user)->allows('view', $report);
    }

    private function isEntitled(User $user, Goal $goal): bool
    {
        if ($user->isEscalatedAuthority()) {
            return true;
        }

        return match ($goal->scope_type) {
            GoalScopeType::User => $goal->target_user_id === $user->getKey() || $this->isCreator($user, $goal),
            GoalScopeType::Team => $this->isTargetTeamMember($user, $goal) || $this->isCreator($user, $goal),
            GoalScopeType::Tenant => false,
        };
    }

    private function isTargetTeamMember(User $user, Goal $goal): bool
    {
        return $user->teams->contains(
            fn (Team $team): bool => $team->getKey() === $goal->target_team_id,
        );
    }

    private function isCreator(User $user, Goal $goal): bool
    {
        return $goal->owner_id === $user->getKey();
    }

    private function mayGovern(User $user, Goal $goal): bool
    {
        return $this->isCreator($user, $goal) || $user->isEscalatedAuthority();
    }
}
