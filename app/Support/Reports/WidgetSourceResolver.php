<?php

declare(strict_types=1);

namespace App\Support\Reports;

use App\Enums\Reports\AggregationType;
use App\Enums\Reports\ChartType;
use App\Enums\Reports\GroupingBucket;
use App\Enums\Reports\ReportExecutionMode;
use App\Exceptions\Reports\ReportNotExecutableException;
use App\Models\Goal;
use App\Models\ObjectType;
use App\Models\Report;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\ValidationException;

class WidgetSourceResolver
{
    public function __construct(private readonly ReportDefinitionValidator $definitionValidator) {}

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'report_id' => ['nullable', 'string'],
            'object_type_id' => ['nullable', 'string'],
            'goal_id' => ['nullable', 'string'],
            'title' => ['nullable', 'string', 'max:255'],
            'chart_type' => ['nullable', 'required_without:goal_id', new Enum(ChartType::class)],
            'column_span' => ['sometimes', 'integer', 'between:1,3'],
            'execution_mode' => ['nullable', new Enum(ReportExecutionMode::class)],
            'filter_definition' => ['nullable', 'array'],
            'aggregation_type' => ['nullable', new Enum(AggregationType::class)],
            'aggregation_field_key' => ['nullable', 'string', 'max:255'],
            'group_by_field_key' => ['nullable', 'string', 'max:255'],
            'group_by_bucket' => ['nullable', new Enum(GroupingBucket::class)],
            'series_field_key' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{report_id: ?string, goal_id: ?string, definition: ?array<string, mixed>}
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function resolve(User $actor, array $validated): array
    {
        $reportId = $this->identifier($validated['report_id'] ?? null);
        $objectTypeId = $this->identifier($validated['object_type_id'] ?? null);
        $goalId = $this->identifier($validated['goal_id'] ?? null);

        $chosen = array_filter([$reportId, $objectTypeId, $goalId], static fn (?string $value): bool => $value !== null);

        if (count($chosen) !== 1) {
            $message = __('i18n.backend.support.reports.widget_source_resolver.a_tile_shows_either_a_saved_report_a_goal');

            throw ValidationException::withMessages([
                'report_id' => $message,
                'object_type_id' => $message,
                'goal_id' => $message,
            ]);
        }

        if ($goalId !== null) {
            return ['report_id' => null, 'goal_id' => $this->visibleGoalId($actor, $goalId), 'definition' => null];
        }

        if ($reportId !== null) {
            return ['report_id' => $this->visibleReportId($actor, $reportId), 'goal_id' => null, 'definition' => null];
        }

        return [
            'report_id' => null,
            'goal_id' => null,
            'definition' => $this->embeddedDefinition($actor, $objectTypeId, $validated),
        ];
    }

    /**
     * @throws AuthorizationException
     */
    private function visibleGoalId(User $actor, string $goalId): string
    {
        $goal = Goal::query()
            ->where('tenant_id', TenantContext::currentId((string) $actor->tenant_id))
            ->with('report')
            ->whereKey($goalId)
            ->firstOrFail();

        Gate::forUser($actor)->authorize('view', $goal);

        return (string) $goal->getKey();
    }

    /**
     * @throws AuthorizationException
     */
    private function visibleReportId(User $actor, string $reportId): string
    {
        $report = Report::query()
            ->where('tenant_id', TenantContext::currentId((string) $actor->tenant_id))
            ->with('objectType')
            ->whereKey($reportId)
            ->firstOrFail();

        Gate::forUser($actor)->authorize('view', $report);

        return (string) $report->getKey();
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    private function embeddedDefinition(User $actor, string $objectTypeId, array $validated): array
    {
        if (($validated['execution_mode'] ?? null) === ReportExecutionMode::Definer->value) {
            throw ValidationException::withMessages([
                'execution_mode' => __('i18n.backend.support.reports.widget_source_resolver.a_tile_with_a_custom_definition_always_runs_with'),
            ]);
        }

        $objectType = ObjectType::query()->whereKey($objectTypeId)->firstOrFail();

        Gate::forUser($actor)->authorize('create', [Report::class, $objectType]);

        $definition = ['object_type_id' => (string) $objectType->getKey()];

        foreach (ReportInputRules::definitionKeys() as $key) {
            $definition[$key] = $validated[$key] ?? null;
        }

        $definition['filter_definition'] ??= [];

        try {
            $this->definitionValidator->validate($definition, $objectType, $actor);
        } catch (ReportNotExecutableException $exception) {
            throw ReportInputRules::toValidationException($exception, $validated);
        }

        return $definition;
    }

    private function identifier(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
