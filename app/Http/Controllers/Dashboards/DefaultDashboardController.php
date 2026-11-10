<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboards;

use App\Http\Controllers\Abstracts\Controller;
use App\Http\Resources\Dashboards\DashboardResource;
use App\Http\Resources\Dashboards\DashboardWidgetResource;
use App\Models\Dashboard;
use App\Models\DashboardWidget;
use App\Support\Dashboards\DefaultDashboardResolver;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DefaultDashboardController extends Controller
{
    public function __construct(private readonly DefaultDashboardResolver $resolver) {}

    public function __invoke(Request $request): Response
    {
        $this->authorize('viewAny', Dashboard::class);

        $user = $this->actingUser($request);

        $available = $this->resolver->available($user);
        $selected = $request->query('dashboard');
        $model = $this->resolver->select($user, $available, is_string($selected) ? $selected : null);
        $groups = $this->resolver->group($user, $available);

        return Inertia::render(__('i18n.backend.http.controllers.dashboards.default_dashboard_controller.dashboard'), [
            'dashboard' => $model === null
                ? null
                : (new DashboardResource($model))->resolve($request),
            'widgets' => $model === null
                ? []
                : DashboardWidgetResource::collection($this->widgetsOf($model))->resolve($request),
            'options' => [
                'own' => $this->options($groups['own']),
                'shared' => $this->options($groups['shared']),
            ],
            'defaultDashboardId' => $user->default_dashboard_id,
            'canCreate' => $user->can('create', Dashboard::class),
        ]);
    }

    /**
     * @param  array<int, Dashboard>  $dashboards
     * @return array<int, array{value: string, label: string}>
     */
    private function options(array $dashboards): array
    {
        return array_map(
            static fn (Dashboard $dashboard): array => [
                'value' => (string) $dashboard->getKey(),
                'label' => $dashboard->name,
            ],
            $dashboards,
        );
    }

    /**
     * @return Collection<int, DashboardWidget>
     */
    private function widgetsOf(Dashboard $dashboard): Collection
    {
        return DashboardWidget::query()
            ->where('dashboard_id', $dashboard->getKey())
            ->orderBy('position')
            ->orderBy('created_at')
            ->get();
    }
}
