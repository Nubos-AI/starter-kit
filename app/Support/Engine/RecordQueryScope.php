<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\DTOs\Engine\RecordQueryScopeData;
use App\DTOs\Engine\RecordQueryScopeResult;
use App\DTOs\Engine\RecordTreeOrderResult;
use App\DTOs\Reports\ReportDrillDownData;
use App\Enums\Engine\RecordTreeOrderReason;
use App\Enums\Engine\SystemFilterField;
use App\Models\CustomRecord;
use App\Models\FieldDefinition;
use App\Models\ObjectType;
use App\Models\Report;
use App\Models\Segment;
use App\Models\User;
use App\Support\Aging\AgingEvaluator;
use App\Support\Authorization\FieldVisibilityResolver;
use App\Support\Dashboards\DashboardWidgetLocator;
use App\Support\Reports\ReportGridScope;
use App\Support\Reports\ReportInputRules;
use App\Support\Reports\WidgetResultResolver;
use App\Support\Segments\SegmentResolver;
use App\Support\Segments\SystemSegmentDescriptor;
use App\Support\Segments\SystemSegmentRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class RecordQueryScope
{
    public function __construct(
        private readonly IndexRegistry $indexRegistry,
        private readonly RecordFilterCompiler $filterCompiler,
        private readonly ReportGridScope $reportGridScope,
        private readonly DashboardWidgetLocator $widgetLocator,
        private readonly WidgetResultResolver $widgetResults,
        private readonly SegmentResolver $segmentResolver,
        private readonly SystemSegmentRegistry $systemSegmentRegistry,
        private readonly AgingEvaluator $agingEvaluator,
        private readonly SystemFilterFields $systemFields,
        private readonly RecordTreeOrder $treeOrder,
        private readonly RecordSearchPredicate $searchPredicate,
    ) {}

    /**
     * @param  Builder<CustomRecord>  $query
     *
     * @throws AuthorizationException
     */
    public function apply(Builder $query, RecordQueryScopeData $scope): RecordQueryScopeResult
    {
        $objectType = $scope->objectType;

        $readable = FieldVisibilityResolver::forRequest()->readableFields($scope->viewer, $objectType);

        $agingExpressions = $this->agingEvaluator->select($query, $objectType, $readable);

        $gridFields = $readable->concat($this->systemFields->agingFields((string) $objectType->getKey()));

        $descriptor = $this->applySegmentScope($query, $scope, $agingExpressions);

        $this->applyReportScope($query, $scope);

        $ordered = $this->applySort($query, $gridFields, $scope->sortModel, $descriptor?->sort, $agingExpressions);

        $this->filterCompiler->apply(
            $query,
            $gridFields->filter(fn (FieldDefinition $field): bool => $field->is_filterable)->values(),
            $scope->filterModel,
            $agingExpressions,
        );

        $searchApplied = $this->searchPredicate->apply($query, $readable, $scope->search);

        $hierarchy = $this->applyHierarchy($query, $scope, $ordered);

        return new RecordQueryScopeResult(
            $readable,
            $gridFields,
            $agingExpressions,
            $descriptor,
            $hierarchy,
            $searchApplied,
        );
    }

    /**
     * @param  EloquentCollection<int, CustomRecord>  $rows
     */
    public function attachRuleNames(EloquentCollection $rows): void
    {
        $this->agingEvaluator->attachRuleNames($rows);
    }

    /**
     * @param  Builder<CustomRecord>  $query
     * @param  array<string, array{sql: literal-string, bindings: list<mixed>}>  $agingExpressions
     *
     * @throws AuthorizationException
     */
    private function applySegmentScope(
        Builder $query,
        RecordQueryScopeData $scope,
        array $agingExpressions,
    ): ?SystemSegmentDescriptor {
        $segmentId = $scope->segmentId;

        if ($segmentId === null || $segmentId === '') {
            return null;
        }

        $descriptor = $this->systemSegmentRegistry->find($segmentId);

        if ($descriptor !== null) {
            $this->segmentResolver->applyScope(
                $query,
                $descriptor->toSegment($scope->viewer),
                $scope->objectType,
                $scope->viewer,
                $descriptor,
                $agingExpressions,
            );

            return $descriptor;
        }

        $segment = Segment::query()->whereKey($segmentId)->firstOrFail();

        if ($scope->viewer->cannot('view', $segment)) {
            throw new AuthorizationException(__('i18n.backend.support.engine.record_query_scope.you_may_not_view_this_segment'));
        }

        $this->segmentResolver->applyScope($query, $segment, $scope->objectType, $scope->viewer, null, $agingExpressions);

        return null;
    }

    /**
     * @param  Builder<CustomRecord>  $query
     *
     * @throws AuthorizationException
     */
    private function applyReportScope(Builder $query, RecordQueryScopeData $scope): void
    {
        $drillDown = $scope->drillDown;

        if (!$drillDown instanceof ReportDrillDownData) {
            return;
        }

        $definition = $drillDown->reportId === null
            ? $this->widgetDefinition($drillDown, $scope->objectType, $scope->viewer)
            : $this->reportDefinition($drillDown, $scope->objectType, $scope->viewer);

        $this->reportGridScope->apply($query, $definition, $drillDown, $scope->objectType, $scope->viewer);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws AuthorizationException
     */
    private function reportDefinition(ReportDrillDownData $drillDown, ObjectType $objectType, User $user): array
    {
        $report = Report::query()
            ->where('tenant_id', $user->tenant_id)
            ->whereKey($drillDown->reportId)
            ->firstOrFail();

        if ($user->cannot('view', $report) || $report->object_type_id !== (string) $objectType->getKey()) {
            throw new AuthorizationException(__('i18n.backend.support.engine.record_query_scope.you_may_not_view_this_report'));
        }

        return $report->only(ReportInputRules::definitionKeys());
    }

    /**
     * @return array<string, mixed>
     *
     * @throws AuthorizationException
     */
    private function widgetDefinition(ReportDrillDownData $drillDown, ObjectType $objectType, User $user): array
    {
        $widget = $this->widgetLocator->resolveWidget(
            $user,
            (string) $drillDown->dashboardId,
            (string) $drillDown->widgetId,
            true,
        );

        Gate::forUser($user)->authorize('view', $widget);

        return $this->widgetResults->visibleDefinition($widget, $objectType, $user);
    }

    /**
     * @param  Builder<CustomRecord>  $query
     * @param  Collection<int, FieldDefinition>  $readable
     * @param  array<array-key, mixed>  $sortModel
     * @param  array{column: string, direction: 'asc'|'desc'}|null  $fallbackSort
     * @param  array<string, array{sql: literal-string, bindings: list<mixed>}>  $agingExpressions
     */
    private function applySort(
        Builder $query,
        Collection $readable,
        array $sortModel,
        ?array $fallbackSort,
        array $agingExpressions,
    ): bool {
        $sortable = $readable->filter(fn (FieldDefinition $field): bool => $field->is_sortable);

        foreach ($sortModel as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $colId = $entry['colId'] ?? null;

            if (!is_string($colId)) {
                continue;
            }

            $direction = ($entry['sort'] ?? null) === 'desc' ? 'desc' : 'asc';

            if ($colId === $this->businessKeyColumn()) {
                $query->orderBy($this->businessKeyColumn(), $direction)->orderBy('id');

                return true;
            }

            if ($this->systemFields->isAgingField($colId)) {
                if (!array_key_exists($colId, $agingExpressions)) {
                    continue;
                }

                $query->orderByRaw($this->agingSortExpression($colId, $direction))->orderBy('id');

                return true;
            }

            $field = $sortable->firstWhere('key', $colId);

            if (!$field instanceof FieldDefinition) {
                continue;
            }

            $query->orderBy(DB::raw($this->indexRegistry->sortExpression($field)), $direction)->orderBy('id');

            return true;
        }

        if ($fallbackSort !== null) {
            $query->orderBy($fallbackSort['column'], $fallbackSort['direction'])->orderBy('id');

            return true;
        }

        return false;
    }

    /**
     * @param  Builder<CustomRecord>  $query
     */
    private function applyHierarchy(Builder $query, RecordQueryScopeData $scope, bool $ordered): RecordTreeOrderResult
    {
        if ($ordered) {
            return RecordTreeOrderResult::skipped(RecordTreeOrderReason::ExplicitSort);
        }

        $result = $scope->hierarchy && $scope->allowTreeOrder
            ? $this->treeOrder->apply($query, $scope->objectType, (string) $scope->viewer->tenant_id)
            : RecordTreeOrderResult::skipped(RecordTreeOrderReason::NotRequested);

        if (!$result->applied) {
            $query->orderBy('created_at')->orderBy('id');
        }

        return $result;
    }

    private function businessKeyColumn(): string
    {
        return 'record_number';
    }

    /**
     * @param  'asc'|'desc'  $direction
     * @return literal-string
     */
    private function agingSortExpression(string $key, string $direction): string
    {
        $column = SystemFilterField::tryFrom($key) === SystemFilterField::AgingStage
            ? SystemFilterField::AgingStage->value
            : SystemFilterField::AgingAge->value;

        return "{$column} {$direction} nulls last";
    }
}
