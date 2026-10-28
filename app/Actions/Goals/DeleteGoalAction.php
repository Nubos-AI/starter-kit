<?php

declare(strict_types=1);

namespace App\Actions\Goals;

use App\Models\Goal;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

class DeleteGoalAction
{
    /**
     * @throws AuthorizationException
     */
    public function execute(User $actor, Goal $goal): void
    {
        Gate::forUser($actor)->authorize('delete', $goal);

        $goal->delete();
    }
}
