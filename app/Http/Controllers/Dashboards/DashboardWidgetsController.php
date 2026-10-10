<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboards;

use App\Actions\Dashboards\AddDashboardWidgetAction;
use App\Actions\Dashboards\RemoveDashboardWidgetAction;
use App\Actions\Dashboards\UpdateDashboardLayoutAction;
use App\Actions\Dashboards\UpdateDashboardWidgetAction;
use App\Http\Controllers\Abstracts\Controller;
use App\Http\Resources\Dashboards\DashboardWidgetResource;
use App\Support\Dashboards\DashboardWidgetLocator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardWidgetsController extends Controller
{
    public function __construct(
        private readonly AddDashboardWidgetAction $addWidget,
        private readonly UpdateDashboardWidgetAction $updateWidget,
        private readonly RemoveDashboardWidgetAction $removeWidget,
        private readonly UpdateDashboardLayoutAction $updateLayout,
        private readonly DashboardWidgetLocator $locator,
    ) {}

    public function store(Request $request, string $dashboard): JsonResponse
    {
        $user = $this->actingUser($request);
        $model = $this->locator->resolveDashboard($user, $dashboard);

        $this->authorize('update', $model);

        $widget = $this->addWidget->execute($user, $model, $request->all());

        return new JsonResponse([
            'data' => (new DashboardWidgetResource($widget))->resolve($request),
        ], 201);
    }

    public function update(Request $request, string $dashboard, string $widget): JsonResponse
    {
        $user = $this->actingUser($request);
        $model = $this->locator->resolveWidget($user, $dashboard, $widget);

        $this->authorize('update', $model);

        $updated = $this->updateWidget->execute($user, $model, $request->all());

        return new JsonResponse([
            'data' => (new DashboardWidgetResource($updated))->resolve($request),
        ]);
    }

    public function destroy(Request $request, string $dashboard, string $widget): JsonResponse
    {
        $user = $this->actingUser($request);
        $model = $this->locator->resolveWidget($user, $dashboard, $widget);

        $this->authorize('delete', $model);

        $this->removeWidget->execute($user, $model);

        return new JsonResponse(null, 204);
    }

    public function updateLayout(Request $request, string $dashboard): JsonResponse
    {
        $user = $this->actingUser($request);
        $model = $this->locator->resolveDashboard($user, $dashboard);

        $this->authorize('update', $model);

        $this->updateLayout->execute($user, $model, $request->all());

        return new JsonResponse(['data' => ['id' => (string) $model->getKey()]]);
    }
}
