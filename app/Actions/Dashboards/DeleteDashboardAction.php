<?php

declare(strict_types=1);

namespace App\Actions\Dashboards;

use App\Models\Dashboard;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

class DeleteDashboardAction
{
    /**
     * @throws AuthorizationException
     */
    public function execute(User $actor, Dashboard $dashboard): void
    {
        Gate::forUser($actor)->authorize('delete', $dashboard);

        $dashboard->delete();
    }
}
