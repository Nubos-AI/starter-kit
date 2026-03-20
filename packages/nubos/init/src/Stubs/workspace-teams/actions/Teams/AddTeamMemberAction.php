<?php

declare(strict_types=1);

namespace App\Actions\Teams;

use App\Events\Teams\TeamMemberAdded;
use App\Models\Team;
use App\Models\User;

class AddTeamMemberAction
{
    public function execute(Team $team, User $user, string $role = 'member'): void
    {
        $team->users()->attach($user->id, ['role' => $role]);

        event(new TeamMemberAdded($team, $user));
    }
}
