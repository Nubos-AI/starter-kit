<?php

declare(strict_types=1);

namespace App\Policies\Dashboards;

use App\Models\DashboardWidget;
use App\Models\User;

class DashboardWidgetPolicy
{
    public function view(User $user, DashboardWidget $widget): bool
    {
        return $user->can('view', $widget->dashboard);
    }

    public function update(User $user, DashboardWidget $widget): bool
    {
        return $user->can('update', $widget->dashboard);
    }

    public function delete(User $user, DashboardWidget $widget): bool
    {
        return $user->can('update', $widget->dashboard);
    }
}
