<?php

declare(strict_types=1);

namespace App\Actions\Dashboards;

use App\Models\DashboardShare;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

class RevokeDashboardShareAction
{
    /**
     * @throws AuthorizationException
     */
    public function execute(User $actor, DashboardShare $share): void
    {
        Gate::forUser($actor)->authorize('share', $share->dashboard);

        $share->delete();
    }
}
