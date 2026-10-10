<?php

declare(strict_types=1);

namespace App\Http\Controllers\Engine;

use App\DTOs\Engine\RecordQueryScopeData;
use App\DTOs\Reports\ReportDrillDownData;
use App\Http\Controllers\Abstracts\Controller;
use App\Http\Resources\FieldDefinitionResource;
use App\Http\Resources\RecordResource;
use App\Models\CustomRecord;
use App\Models\FieldDefinition;
use App\Models\ObjectType;
use App\Support\Aging\AgingEvaluator;
use App\Support\Engine\ObjectTypePresenter;
use App\Support\Engine\RecordQueryScope;
use App\Support\Engine\SystemFilterFields;
use App\Support\Modules\RecordExtensions;
use App\Support\Preferences\UserPreferenceResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RecordGridsController extends Controller
{
    private int $maxBlockSize = 1000;

    public function __construct(
        private readonly RecordQueryScope $queryScope,
        private readonly AgingEvaluator $agingEvaluator,
        private readonly SystemFilterFields $systemFields,
        private readonly UserPreferenceResolver $preferences,
        private readonly ObjectTypePresenter $objectTypePresenter,
        private readonly RecordExtensions $extensions,
    ) {}

    public function index(Request $request, ObjectType $objectType): Response
    {
        $objectType->load('fieldDefinitions');

        $defaultColumns = $objectType->fieldDefinitions
            ->where('is_default_column', true)
            ->sortBy('list_position')
            ->values();

        $columns = $defaultColumns->isNotEmpty()
            ? $defaultColumns
            : $objectType->fieldDefinitions
                ->reject(fn (FieldDefinition $field): bool => $field->is_encrypted)
                ->sortBy('list_position')
                ->values();

        $user = $this->actingUser($request);

        return Inertia::render('records/Index', [
            'objectType' => $this->objectTypePresenter->summary($objectType),
            'preference' => $this->preferences->objectTypeSlice(
                $user,
                (string) $objectType->getKey(),
            ),
            'hasRecords' => CustomRecord::query()
                ->ofType($objectType)
                ->exists(),
            ...$this->extensions->index($objectType, $user),
            'columns' => FieldDefinitionResource::collection($columns)->resolve($request),
            'fieldDefinitions' => [
                ...FieldDefinitionResource::collection(
                    $objectType->fieldDefinitions->sortBy('id')->values(),
                )->resolve($request),
                ...$this->systemFields->payload($this->agingEvaluator->fields($objectType)),
            ],
        ]);
    }

    public function grid(Request $request, ObjectType $objectType): JsonResponse
    {
        $validated = $request->validate([
            'startRow' => ['required', 'integer', 'min:0'],
            'endRow' => ['required', 'integer', 'gt:startRow'],
            'hierarchy' => ['boolean'],
            'sortModel' => ['array'],
            'filterModel' => ['array'],
            'search' => ['nullable', 'string', 'max:255'],
            'segment' => ['nullable', 'string'],
            'report' => ['nullable', 'string'],
            'dashboard' => ['nullable', 'string'],
            'widget' => ['nullable', 'string', 'required_with:dashboard'],
            'group' => ['nullable', 'string', 'required_with:report,dashboard'],
            'series' => ['nullable', 'string'],
        ]);

        $startRow = (int) $validated['startRow'];
        $blockSize = min((int) $validated['endRow'] - $startRow, $this->maxBlockSize);

        $objectType->load('fieldDefinitions');

        $query = CustomRecord::query()->ofType($objectType);

        $scope = $this->queryScope->apply($query, new RecordQueryScopeData(
            $objectType,
            $this->actingUser($request),
            $validated['segment'] ?? null,
            $this->drillDown($validated),
            $this->filterModel($request),
            is_array($request->input('sortModel')) ? $request->input('sortModel') : [],
            (bool) ($validated['hierarchy'] ?? false),
            true,
            is_string($validated['search'] ?? null) ? $validated['search'] : null,
        ));

        $rows = $query->offset($startRow)->limit($blockSize)->get();

        $this->queryScope->attachRuleNames($rows);

        $lastRow = $rows->count() < $blockSize ? $startRow + $rows->count() : null;

        return new JsonResponse([
            'rows' => RecordResource::collection($rows)->resolve($request),
            'lastRow' => $lastRow,
            'hierarchy' => $scope->hierarchy->payload(),
            'searchApplied' => $scope->searchApplied,
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     *
     * @throws ValidationException
     */
    private function drillDown(array $validated): ?ReportDrillDownData
    {
        $reportId = $validated['report'] ?? null;
        $dashboardId = $validated['dashboard'] ?? null;
        $widgetId = $validated['widget'] ?? null;
        $groupToken = $validated['group'] ?? null;
        $seriesToken = $validated['series'] ?? null;

        $fromReport = is_string($reportId);
        $fromWidget = is_string($dashboardId) && is_string($widgetId);
        $hasSelection = is_string($groupToken) || is_string($seriesToken);

        if (!$fromReport && !$fromWidget && !$hasSelection) {
            return null;
        }

        if ($fromReport === $fromWidget) {
            throw ValidationException::withMessages([
                'report' => __('i18n.backend.http.controllers.engine.record_grids_controller.drill_down_requires_exactly_one_source_either_a_report'),
            ]);
        }

        if (!is_string($groupToken)) {
            throw ValidationException::withMessages([
                'group' => __('i18n.backend.http.controllers.engine.record_grids_controller.the_drill_down_request_must_specify_which_section_of'),
            ]);
        }

        if (($validated['segment'] ?? null) !== null) {
            throw ValidationException::withMessages([
                'report' => __('i18n.backend.http.controllers.engine.record_grids_controller.drill_down_cannot_be_combined_with_a_segment_because'),
            ]);
        }

        return new ReportDrillDownData(
            $fromReport ? $reportId : null,
            $fromWidget ? $dashboardId : null,
            $fromWidget ? $widgetId : null,
            $groupToken,
            is_string($seriesToken) ? $seriesToken : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function filterModel(Request $request): array
    {
        $filterModel = $request->input('filterModel', []);

        return is_array($filterModel) ? $filterModel : [];
    }
}
