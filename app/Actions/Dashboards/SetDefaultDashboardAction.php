<?php

declare(strict_types=1);

namespace App\Actions\Dashboards;

use App\Models\Dashboard;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

class SetDefaultDashboardAction
{
    /**
     * @throws AuthorizationException
     */
    public function execute(User $user, Dashboard $dashboard): User
    {
        Gate::forUser($user)->authorize('view', $dashboard);

        $user->fill(['default_dashboard_id' => $dashboard->getKey()]);

        $user->save();

        return $user;
    }
}
