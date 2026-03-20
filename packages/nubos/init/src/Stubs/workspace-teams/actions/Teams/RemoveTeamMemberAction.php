<?php

declare(strict_types=1);

namespace App\Actions\Teams;

use App\Events\Teams\TeamMemberRemoved;
use App\Models\Team;
use App\Models\User;

class RemoveTeamMemberAction
{
    public function execute(Team $team, User $user): void
    {
        $team->users()->detach($user->id);

        event(new TeamMemberRemoved($team, $user));
    }
}
