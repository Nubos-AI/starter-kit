<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reports;

use App\Actions\Reports\BulkDeleteReportsAction;
use App\Actions\Reports\CreateReportAction;
use App\Actions\Reports\DeleteReportAction;
use App\Actions\Reports\UpdateReportAction;
use App\Http\Controllers\Abstracts\Controller;
use App\Http\Resources\Reports\ReportResource;
use App\Models\ObjectType;
use App\Models\Report;
use App\Models\User;
use App\Support\Reports\ReportEditorPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ReportsController extends Controller
{
    public function __construct(
        private readonly CreateReportAction $createReport,
        private readonly UpdateReportAction $updateReport,
        private readonly DeleteReportAction $deleteReport,
        private readonly BulkDeleteReportsAction $bulkDeleteReports,
        private readonly ReportEditorPresenter $editorPresenter,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Report::class);

        $user = $this->actingUser($request);

        $reports = $this->tenantReports($user)
            ->orderBy('name')
            ->get()
            ->filter(fn (Report $report): bool => $user->can('view', $report))
            ->values();

        return Inertia::render('reports/Index', [
            'reports' => ReportResource::collection($reports)->resolve($request),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', Report::class);

        $user = $this->actingUser($request);

        return Inertia::render('reports/Form', array_merge([
            'mode' => 'create',
            'report' => null,
        ], $this->editorPresenter->payload($user, ObjectType::query()->generic()->orderBy('name')->get())));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Report::class);

        $this->createReport->execute($this->actingUser($request), $request->all());

        return to_route('reports.index');
    }

    public function edit(Request $request, string $report): Response
    {
        $user = $this->actingUser($request);
        $model = $this->resolveReport($user, $report);

        $this->authorize('update', $model);

        return Inertia::render('reports/Form', array_merge([
            'mode' => 'edit',
            'report' => (new ReportResource($model))->resolve($request),
        ], $this->editorPresenter->payload($user, $this->reportObjectTypes($model))));
    }

    public function update(Request $request, string $report): RedirectResponse
    {
        $user = $this->actingUser($request);
        $model = $this->resolveReport($user, $report);

        $this->authorize('update', $model);

        $this->updateReport->execute($user, $model, $request->all());

        return to_route('reports.index');
    }

    public function destroy(Request $request, string $report): RedirectResponse
    {
        $user = $this->actingUser($request);
        $model = $this->resolveReport($user, $report);

        $this->authorize('delete', $model);

        $this->deleteReport->execute($user, $model);

        return to_route('reports.index');
    }

    public function bulkDestroy(Request $request): RedirectResponse
    {
        $this->authorize('viewAny', Report::class);

        $this->bulkDeleteReports->execute($this->actingUser($request), $request->all());

        return to_route('reports.index');
    }

    /**
     * @return EloquentCollection<int, ObjectType>
     */
    private function reportObjectTypes(Report $report): EloquentCollection
    {
        /** @var EloquentCollection<int, ObjectType> $types */
        $types = ObjectType::query()->whereKey($report->object_type_id)->get();

        return $types;
    }

    private function resolveReport(User $user, string $report): Report
    {
        return $this->tenantReports($user)->whereKey($report)->firstOrFail();
    }

    /**
     * @return Builder<Report>
     */
    private function tenantReports(User $user): Builder
    {
        return Report::query()
            ->with('objectType')
            ->where('tenant_id', $user->tenant_id);
    }
}
