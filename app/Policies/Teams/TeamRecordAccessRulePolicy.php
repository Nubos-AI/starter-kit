<?php

declare(strict_types=1);

namespace App\Policies\Teams;

use App\Models\TeamRecordAccessRule;
use App\Models\User;

class TeamRecordAccessRulePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('teams.manage');
    }

    public function view(User $user, TeamRecordAccessRule $rule): bool
    {
        return $user->hasPermission('teams.manage');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('teams.manage');
    }

    public function update(User $user, TeamRecordAccessRule $rule): bool
    {
        return $user->hasPermission('teams.manage');
    }

    public function delete(User $user, TeamRecordAccessRule $rule): bool
    {
        return $user->hasPermission('teams.manage');
    }

    public function deleteAny(User $user): bool
    {
        return $user->hasPermission('teams.manage');
    }
}
