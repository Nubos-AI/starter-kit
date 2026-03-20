<?php

declare(strict_types=1);

namespace App\Events\Teams;

use App\Models\Team;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TeamCreated
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Team $team,
    ) {}
}
