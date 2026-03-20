<?php

declare(strict_types=1);

namespace App\Events\Teams;

use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TeamMemberRemoved
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Team $team,
        public readonly User $user,
    ) {}
}
