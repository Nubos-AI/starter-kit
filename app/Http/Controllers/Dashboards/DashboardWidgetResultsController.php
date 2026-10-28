<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboards;

use App\DTOs\Reports\WidgetResultData;
use App\Http\Controllers\Abstracts\Controller;
use App\Http\Resources\Dashboards\WidgetResultResource;
use App\Models\DashboardWidget;
use App\Support\Dashboards\DashboardWidgetLocator;
use App\Support\Reports\WidgetResultResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardWidgetResultsController extends Controller
{
    public function __construct(
        private readonly WidgetResultResolver $resolver,
        private readonly DashboardWidgetLocator $locator,
    ) {}

    public function index(Request $request, string $dashboard): JsonResponse
    {
        $user = $this->actingUser($request);
        $model = $this->locator->resolveDashboard($user, $dashboard);

        $this->authorize('view', $model);

        $widgets = DashboardWidget::query()
            ->where('dashboard_id', $model->getKey())
            ->with('report.objectType')
            ->orderBy('position')
            ->orderBy('created_at')
            ->get();

        $results = $this->resolver->resolveMany($widgets, $user);

        return new JsonResponse([
            'data' => array_map(
                fn (WidgetResultData $result): array => (new WidgetResultResource($result))->resolve($request),
                $results,
            ),
        ]);
    }

    public function show(Request $request, string $dashboard, string $widget): JsonResponse
    {
        $user = $this->actingUser($request);
        $model = $this->locator->resolveWidget($user, $dashboard, $widget, true);

        $this->authorize('view', $model);

        return new JsonResponse([
            'data' => (new WidgetResultResource($this->resolver->resolve($model, $user)))->resolve($request),
        ]);
    }
}
