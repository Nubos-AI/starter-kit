<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboards;

use App\Actions\Dashboards\BulkDeleteDashboardsAction;
use App\Actions\Dashboards\CreateDashboardAction;
use App\Actions\Dashboards\DeleteDashboardAction;
use App\Actions\Dashboards\UpdateDashboardAction;
use App\Enums\Reports\ReportExecutionMode;
use App\Http\Controllers\Abstracts\Controller;
use App\Http\Resources\Dashboards\DashboardResource;
use App\Http\Resources\Dashboards\DashboardWidgetResource;
use App\Models\Dashboard;
use App\Models\DashboardWidget;
use App\Models\ObjectType;
use App\Models\Report;
use App\Models\User;
use App\Support\Goals\GoalOptions;
use App\Support\Reports\ReportEditorPresenter;
use App\Support\Reports\ReportOptions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardsController extends Controller
{
    public function __construct(
        private readonly CreateDashboardAction $createDashboard,
        private readonly UpdateDashboardAction $updateDashboard,
        private readonly DeleteDashboardAction $deleteDashboard,
        private readonly BulkDeleteDashboardsAction $bulkDeleteDashboards,
        private readonly ReportEditorPresenter $editorPresenter,
        private readonly ReportOptions $reportOptions,
        private readonly GoalOptions $goalOptions,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Dashboard::class);

        $user = $this->actingUser($request);

        $dashboards = $this->tenantDashboards($user)
            ->orderBy('name')
            ->get()
            ->filter(fn (Dashboard $dashboard): bool => $user->can('view', $dashboard))
            ->values();

        return Inertia::render('dashboards/Index', [
            'dashboards' => DashboardResource::collection($dashboards)->resolve($request),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Dashboard::class);

        return Inertia::render('dashboards/Form', [
            'mode' => 'create',
            'dashboard' => null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Dashboard::class);

        $this->createDashboard->execute($this->actingUser($request), $request->all());

        return to_route('dashboards.index');
    }

    public function show(Request $request, string $dashboard): Response
    {
        $user = $this->actingUser($request);
        $model = $this->resolveDashboard($user, $dashboard);

        $this->authorize('view', $model);

        $widgets = DashboardWidget::query()
            ->where('dashboard_id', $model->getKey())
            ->orderBy('position')
            ->orderBy('created_at')
            ->get();

        return Inertia::render('dashboards/Show', array_merge([
            'dashboard' => (new DashboardResource($model))->resolve($request),
            'widgets' => DashboardWidgetResource::collection($widgets)->resolve($request),
            'reportOptions' => $this->reportOptions->forUser($user),
            'goalOptions' => $this->goalOptions->forUser($user),
        ], $this->editorPresenter->payload($user, ObjectType::query()->orderBy('name')->get())));
    }

    public function edit(Request $request, string $dashboard): Response
    {
        $user = $this->actingUser($request);
        $model = $this->resolveDashboard($user, $dashboard);

        $this->authorize('update', $model);

        return Inertia::render('dashboards/Form', [
            'mode' => 'edit',
            'dashboard' => (new DashboardResource($model))->resolve($request),
        ]);
    }

    public function update(Request $request, string $dashboard): RedirectResponse
    {
        $user = $this->actingUser($request);
        $model = $this->resolveDashboard($user, $dashboard);

        $this->authorize('update', $model);

        $this->updateDashboard->execute($user, $model, $request->all());

        return to_route('dashboards.index');
    }

    public function destroy(Request $request, string $dashboard): RedirectResponse
    {
        $user = $this->actingUser($request);
        $model = $this->resolveDashboard($user, $dashboard);

        $this->authorize('delete', $model);

        $this->deleteDashboard->execute($user, $model);

        return to_route('dashboards.index');
    }

    public function bulkDestroy(Request $request): RedirectResponse
    {
        $this->authorize('viewAny', Dashboard::class);

        $this->bulkDeleteDashboards->execute($this->actingUser($request), $request->all());

        return to_route('dashboards.index');
    }

    private function resolveDashboard(User $user, string $dashboard): Dashboard
    {
        return $this->tenantDashboards($user)->whereKey($dashboard)->firstOrFail();
    }

    /**
     * @return Builder<Dashboard>
     */
    private function tenantDashboards(User $user): Builder
    {
        return Dashboard::query()
            ->where('tenant_id', $user->tenant_id)
            ->with('shares')
            ->withExists(['widgets as has_definer_widget' => $this->definerBoundWidgets(...)]);
    }

    /**
     * @param  Builder<DashboardWidget>  $query
     * @return Builder<DashboardWidget>
     */
    private function definerBoundWidgets(Builder $query): Builder
    {
        return $query->whereHas('report', $this->definerBoundReports(...));
    }

    /**
     * @param  Builder<Report>  $query
     * @return Builder<Report>
     */
    private function definerBoundReports(Builder $query): Builder
    {
        return $query
            ->withTrashed()
            ->where('execution_mode', ReportExecutionMode::Definer->value);
    }
}
