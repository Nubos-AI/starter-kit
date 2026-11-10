<?php

declare(strict_types=1);

namespace App\Actions\Dashboards;

use App\Models\DashboardWidget;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

class RemoveDashboardWidgetAction
{
    /**
     * @throws AuthorizationException
     */
    public function execute(User $actor, DashboardWidget $widget): void
    {
        Gate::forUser($actor)->authorize('delete', $widget);

        DashboardWidget::query()->whereKey($widget->getKey())->delete();
    }
}
