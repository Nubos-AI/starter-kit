<?php

declare(strict_types=1);

namespace App\Support\Goals;

use App\Enums\CustomFields\FieldType;
use App\Enums\Goals\GoalActionRefusalReason;
use App\Enums\Goals\GoalDirection;
use App\Enums\Goals\GoalPeriodType;
use App\Enums\Goals\GoalScopeType;
use App\Models\FieldDefinition;
use App\Models\Report;
use App\Models\User;
use App\Support\Engine\SystemFilterFields;
use App\Support\Teams\TenantTeamOptions;
use App\Support\Users\TenantUserOptions;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

class GoalEditorPresenter
{
    public function __construct(
        private readonly GoalScopeCompiler $scopeCompiler,
        private readonly SystemFilterFields $systemFields,
        private readonly TenantTeamOptions $teamOptions,
        private readonly TenantUserOptions $userOptions,
        private readonly GoalDefinitionSource $source,
    ) {}

    /**
     * @return array{
     *     reportOptions: list<array{value: string, label: string, object_type_id: string, disabled?: bool, disabledReason?: string}>,
     *     scopeFieldsByReport: array<string, list<array{value: string, label: string}>>,
     *     periodFieldsByReport: array<string, list<array{value: string, label: string}>>,
     *     userOptions: list<array{value: string, label: string, description: string, avatar: array{name: string}}>,
     *     teamOptions: list<array{value: string, label: string}>,
     *     scopeTypeOptions: list<array{value: string, label: string}>,
     *     periodTypeOptions: list<array{value: string, label: string}>,
     *     directionOptions: list<array{value: string, label: string}>
     * }
     */
    public function payload(User $user): array
    {
        $reports = $this->sourceReports($user);

        /** @var EloquentCollection<int, Report> $singleMetricReports */
        $singleMetricReports = $reports
            ->filter(fn (Report $report): bool => $this->isSingleMetric($report))
            ->values();

        $fieldsByObjectType = $this->fieldsByObjectType($singleMetricReports);

        return [
            'reportOptions' => array_values($reports
                ->map(fn (Report $report): array => $this->reportOption($report))
                ->all()),
            'scopeFieldsByReport' => $this->scopeFieldsByReport($singleMetricReports, $fieldsByObjectType),
            'periodFieldsByReport' => $this->periodFieldsByReport($singleMetricReports, $fieldsByObjectType),
            'userOptions' => $this->userOptions->forUser($user),
            'teamOptions' => array_values($this->teamOptions->forUser($user)),
            'scopeTypeOptions' => $this->enumOptions(GoalScopeType::cases()),
            'periodTypeOptions' => $this->enumOptions(GoalPeriodType::cases()),
            'directionOptions' => $this->enumOptions(GoalDirection::cases()),
        ];
    }

    /**
     * @return EloquentCollection<int, Report>
     */
    private function sourceReports(User $user): EloquentCollection
    {
        /** @var EloquentCollection<int, Report> $reports */
        $reports = $this->source->tenantReports($user->tenant_id)
            ->filter(static fn (Report $report): bool => $user->can('view', $report))
            ->values();

        return $reports;
    }

    private function isSingleMetric(Report $report): bool
    {
        return $report->group_by_field_key === null && $report->series_field_key === null;
    }

    /**
     * @return array{value: string, label: string, object_type_id: string, disabled?: bool, disabledReason?: string}
     */
    private function reportOption(Report $report): array
    {
        $option = [
            'value' => (string) $report->getKey(),
            'label' => $report->name,
            'object_type_id' => $report->object_type_id,
        ];

        if ($this->isSingleMetric($report)) {
            return $option;
        }

        return [
            ...$option,
            'disabled' => true,
            'disabledReason' => GoalActionRefusalReason::GroupedReport->value,
        ];
    }

    /**
     * @param  EloquentCollection<int, Report>  $reports
     * @param  Collection<string, EloquentCollection<int, FieldDefinition>>  $persisted
     * @return array<string, list<array{value: string, label: string}>>
     */
    private function scopeFieldsByReport(EloquentCollection $reports, Collection $persisted): array
    {
        $byReport = [];

        foreach ($reports as $report) {
            $objectTypeId = $report->object_type_id;

            $fields = new EloquentCollection([
                ...$this->systemFields->all($objectTypeId)->all(),
                ...($persisted[$objectTypeId] ?? new EloquentCollection)->all(),
            ]);

            $supported = $fields
                ->filter(fn (FieldDefinition $field): bool => $this->scopeCompiler->supportsScopeField($field))
                ->values();

            $byReport[(string) $report->getKey()] = $this->fieldOptions($supported);
        }

        return $byReport;
    }

    /**
     * @param  EloquentCollection<int, Report>  $reports
     * @param  Collection<string, EloquentCollection<int, FieldDefinition>>  $persisted
     * @return array<string, list<array{value: string, label: string}>>
     */
    private function periodFieldsByReport(EloquentCollection $reports, Collection $persisted): array
    {
        $byReport = [];

        foreach ($reports as $report) {
            $objectTypeId = $report->object_type_id;

            $dateFields = new EloquentCollection(
                ($persisted[$objectTypeId] ?? new EloquentCollection)
                    ->filter(static fn (FieldDefinition $field): bool => $field->field_type === FieldType::Date
                        && $field->is_filterable
                        && !$field->is_encrypted)
                    ->values()
                    ->all(),
            );

            $byReport[(string) $report->getKey()] = $this->fieldOptions($dateFields);
        }

        return $byReport;
    }

    /**
     * @param  EloquentCollection<int, Report>  $reports
     * @return Collection<string, EloquentCollection<int, FieldDefinition>>
     */
    private function fieldsByObjectType(EloquentCollection $reports): Collection
    {
        $objectTypeIds = array_values(array_unique(
            $reports->map(static fn (Report $report): string => $report->object_type_id)->all(),
        ));

        return $this->source->filterableFieldsByObjectType($objectTypeIds);
    }

    /**
     * @param  EloquentCollection<int, FieldDefinition>  $fields
     * @return list<array{value: string, label: string}>
     */
    private function fieldOptions(EloquentCollection $fields): array
    {
        return array_values(array_map(
            static fn (array $field): array => [
                'value' => (string) $field['key'],
                'label' => (string) $field['label'],
            ],
            $this->systemFields->payload($fields->toBase()),
        ));
    }

    /**
     * @param  list<GoalScopeType|GoalPeriodType|GoalDirection>  $cases
     * @return list<array{value: string, label: string}>
     */
    private function enumOptions(array $cases): array
    {
        return array_map(
            static fn (GoalScopeType|GoalPeriodType|GoalDirection $case): array => [
                'value' => $case->value,
                'label' => $case->label(),
            ],
            $cases,
        );
    }
}
