<?php

declare(strict_types=1);

namespace App\Support\Reports;

use App\DTOs\Reports\ReportResultData;
use App\DTOs\Reports\WidgetResultData;
use App\Enums\Reports\ReportExecutionMode;
use App\Enums\Reports\ReportNotExecutableReason;
use App\Exceptions\Reports\ReportNotExecutableException;
use App\Http\Resources\Goals\GoalResource;
use App\Models\DashboardWidget;
use App\Models\Goal;
use App\Models\ObjectType;
use App\Models\Report;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

class WidgetResultResolver
{
    public function __construct(
        private readonly ReportDefinitionValidator $definitionValidator,
        private readonly ReportRunner $runner,
        private readonly ReportExecutionContext $context,
        private readonly ReportExecutionModeResolver $modeResolver,
    ) {}

    public function resolve(DashboardWidget $widget, User $viewer): WidgetResultData
    {
        $widgets = new Collection([$widget]);

        return $this->resolveWidget(
            $widget,
            $viewer,
            $this->embeddedObjectTypes($widgets),
            $this->goals($widgets, $viewer),
        );
    }

    /**
     * @param  Collection<int, DashboardWidget>  $widgets
     * @return list<WidgetResultData>
     */
    public function resolveMany(Collection $widgets, User $viewer): array
    {
        $objectTypes = $this->embeddedObjectTypes($widgets);
        $goals = $this->goals($widgets, $viewer);

        /** @var list<WidgetResultData> $results */
        $results = [];

        foreach ($widgets as $widget) {
            $results[] = $this->resolveWidget($widget, $viewer, $objectTypes, $goals);
        }

        return $results;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws AuthorizationException
     */
    public function visibleDefinition(DashboardWidget $widget, ObjectType $objectType, User $viewer): array
    {
        if ($widget->goal_id !== null) {
            throw new AuthorizationException(__('i18n.backend.support.reports.widget_result_resolver.goal_tiles_do_not_support_drill_down'));
        }

        $report = $widget->report;

        if ($widget->report_id !== null && !$report instanceof Report) {
            throw new AuthorizationException(__('i18n.backend.support.reports.widget_result_resolver.you_may_not_drill_into_this_tile'));
        }

        $sourceObjectTypeId = $report instanceof Report
            ? $report->object_type_id
            : $this->embeddedObjectTypeId($widget);

        if ($sourceObjectTypeId !== (string) $objectType->getKey()) {
            throw new AuthorizationException(__('i18n.backend.support.reports.widget_result_resolver.this_tile_does_not_analyse_the_requested_object_type'));
        }

        if (!$this->maySeeSource($viewer, $report, $objectType)) {
            throw new AuthorizationException(__('i18n.backend.support.reports.widget_result_resolver.you_may_not_view_this_tile_s_underlying_data'));
        }

        return $report instanceof Report
            ? $report->only(ReportInputRules::definitionKeys())
            : $this->embeddedDefinition($widget);
    }

    /**
     * @param  array<string, ObjectType>  $objectTypes
     * @param  array<string, Goal>  $goals
     */
    private function resolveWidget(
        DashboardWidget $widget,
        User $viewer,
        array $objectTypes,
        array $goals,
    ): WidgetResultData {
        if ($widget->goal_id !== null) {
            return $this->resolveGoalWidget($widget, $viewer, $goals[$widget->goal_id] ?? null);
        }

        $report = $widget->report;
        $mode = $this->modeResolver->effectiveMode($report);

        if ($widget->report_id !== null && !$report instanceof Report) {
            return $this->notice($widget, ReportExecutionMode::Viewer, ReportNotExecutableReason::ReportMissing);
        }

        $objectType = $report instanceof Report
            ? $report->objectType
            : ($objectTypes[$this->embeddedObjectTypeId($widget)] ?? null);

        if (!$objectType instanceof ObjectType) {
            return $this->notice($widget, $mode, ReportNotExecutableReason::SourceNotVisible);
        }

        if (!$this->maySeeSource($viewer, $report, $objectType)) {
            return $this->notice($widget, $mode, ReportNotExecutableReason::SourceNotVisible);
        }

        $definition = $report instanceof Report
            ? $report->only(ReportInputRules::definitionKeys())
            : $this->embeddedDefinition($widget);

        $run = fn (User $actingUser): ReportResultData => $this->runner->run(
            $this->definitionValidator->validate($definition, $objectType, $actingUser),
        );

        try {
            $result = $mode === ReportExecutionMode::Definer && $report instanceof Report
                ? $this->context->runAsDefiner((string) $viewer->tenant_id, $report->owner_id, $run)
                : $this->context->runAsViewer((string) $viewer->tenant_id, (string) $viewer->getKey(), $run);
        } catch (ReportNotExecutableException $exception) {
            return $this->notice($widget, $mode, $exception->reason);
        } catch (AuthorizationException) {
            return $this->notice($widget, $mode, ReportNotExecutableReason::SourceNotVisible);
        }

        return new WidgetResultData($widget, $mode, $result->generatedAt, $result, null, $objectType);
    }

    private function resolveGoalWidget(DashboardWidget $widget, User $viewer, ?Goal $goal): WidgetResultData
    {
        if (!$goal instanceof Goal) {
            return $this->notice($widget, ReportExecutionMode::Viewer, ReportNotExecutableReason::GoalMissing);
        }

        if (!Gate::forUser($viewer)->allows('view', $goal)) {
            return $this->notice($widget, ReportExecutionMode::Viewer, ReportNotExecutableReason::GoalNotVisible);
        }

        return new WidgetResultData(
            $widget,
            ReportExecutionMode::Viewer,
            $this->goalGeneratedAt($goal),
            null,
            null,
            null,
            $goal,
        );
    }

    /**
     * @param  Collection<int, DashboardWidget>  $widgets
     * @return array<string, Goal>
     */
    private function goals(Collection $widgets, User $viewer): array
    {
        $ids = $widgets
            ->map(static fn (DashboardWidget $widget): ?string => $widget->goal_id === null
                ? null
                : $widget->goal_id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return [];
        }

        /** @var array<string, Goal> $goals */
        $goals = Goal::query()
            ->where('tenant_id', $viewer->tenant_id)
            ->with([
                'report',
                'periods' => static function (Relation $query): void {
                    $query
                        ->orderByDesc('period_start')
                        ->orderByDesc('created_at')
                        ->limit(GoalResource::$periodLimit);
                },
            ])
            ->whereKey($ids)
            ->get()
            ->keyBy(static fn (Goal $goal): string => (string) $goal->getKey())
            ->all();

        return $goals;
    }

    private function goalGeneratedAt(Goal $goal): string
    {
        $calculatedAt = $goal->periods
            ->pluck('calculated_at')
            ->filter()
            ->max();

        return $calculatedAt instanceof CarbonInterface
            ? $calculatedAt->toIso8601String()
            : CarbonImmutable::now()->toIso8601String();
    }

    private function maySeeSource(User $viewer, ?Report $report, ObjectType $objectType): bool
    {
        return $report instanceof Report
            ? Gate::forUser($viewer)->allows('view', $report)
            : Gate::forUser($viewer)->allows('create', [Report::class, $objectType]);
    }

    private function notice(
        DashboardWidget $widget,
        ReportExecutionMode $mode,
        ReportNotExecutableReason $reason,
    ): WidgetResultData {
        Log::warning('A dashboard widget could not be executed.', [
            'widget_id' => (string) $widget->getKey(),
            'reason' => $reason->value,
        ]);

        return new WidgetResultData(
            $widget,
            $mode,
            CarbonImmutable::now()->toIso8601String(),
            null,
            $reason,
            null,
        );
    }

    /**
     * @param  Collection<int, DashboardWidget>  $widgets
     * @return array<string, ObjectType>
     */
    private function embeddedObjectTypes(Collection $widgets): array
    {
        $ids = $widgets
            ->filter(static fn (DashboardWidget $widget): bool => $widget->report_id === null && $widget->goal_id === null)
            ->map(fn (DashboardWidget $widget): string => $this->embeddedObjectTypeId($widget))
            ->filter(static fn (string $id): bool => $id !== '')
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return [];
        }

        /** @var array<string, ObjectType> $objectTypes */
        $objectTypes = ObjectType::query()
            ->whereKey($ids)
            ->get()
            ->keyBy(static fn (ObjectType $objectType): string => (string) $objectType->getKey())
            ->all();

        return $objectTypes;
    }

    private function embeddedObjectTypeId(DashboardWidget $widget): string
    {
        $objectTypeId = ($widget->definition ?? [])['object_type_id'] ?? null;

        return is_string($objectTypeId) ? $objectTypeId : '';
    }

    /**
     * @return array<string, mixed>
     */
    private function embeddedDefinition(DashboardWidget $widget): array
    {
        $definition = $widget->definition ?? [];

        /** @var array<string, mixed> $only */
        $only = array_intersect_key($definition, array_flip(ReportInputRules::definitionKeys()));

        return $only;
    }
}
