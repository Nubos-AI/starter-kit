<?php

declare(strict_types=1);

namespace App\Policies\Teams;

use App\Models\Team;
use App\Models\User;

class TeamPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('teams.view');
    }

    public function view(User $user, Team $team): bool
    {
        return $user->hasPermission('teams.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('teams.create');
    }

    public function update(User $user, Team $team): bool
    {
        return $user->hasPermission('teams.update');
    }

    public function delete(User $user, Team $team): bool
    {
        return $user->hasPermission('teams.delete');
    }

    public function deleteAny(User $user): bool
    {
        return $user->hasPermission('teams.delete');
    }

    public function reparent(User $user, Team $team): bool
    {
        return $user->hasPermission('teams.reparent');
    }
}
