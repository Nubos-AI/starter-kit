<?php

declare(strict_types=1);

namespace App\Support\Dashboards;

use App\Models\Dashboard;
use App\Models\DashboardWidget;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class DashboardWidgetLocator
{
    /**
     * @throws ModelNotFoundException<Dashboard>
     */
    public function resolveDashboard(User $user, string $dashboard): Dashboard
    {
        return Dashboard::query()
            ->where('tenant_id', $user->tenant_id)
            ->with('shares')
            ->whereKey($dashboard)
            ->firstOrFail();
    }

    /**
     * @throws ModelNotFoundException<Dashboard>
     * @throws ModelNotFoundException<DashboardWidget>
     */
    public function resolveWidget(User $user, string $dashboard, string $widget, bool $withReport = false): DashboardWidget
    {
        $model = $this->resolveDashboard($user, $dashboard);

        $query = DashboardWidget::query()->where('dashboard_id', $model->getKey());

        if ($withReport) {
            $query->with('report.objectType');
        }

        $widgetModel = $query->whereKey($widget)->firstOrFail();

        $widgetModel->setRelation('dashboard', $model);

        return $widgetModel;
    }
}
