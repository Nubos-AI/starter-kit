<?php

declare(strict_types=1);

namespace App\Support\Reports;

use App\DTOs\Reports\ReportDefinitionData;
use App\DTOs\Reports\ReportDrillDownData;
use App\DTOs\Reports\ReportLinkedFieldBinding;
use App\Exceptions\Reports\ReportNotExecutableException;
use App\Exceptions\Reports\UnsupportedAggregationException;
use App\Models\CustomRecord;
use App\Models\FieldDefinition;
use App\Models\ObjectType;
use App\Models\User;
use App\Support\Engine\RecordFilterCompiler;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class ReportGridScope
{
    public function __construct(
        private readonly ReportDefinitionValidator $definitionValidator,
        private readonly ReportExpressionCompiler $expressions,
        private readonly RecordFilterCompiler $filters,
    ) {}

    /**
     * @param  Builder<CustomRecord>  $query
     * @param  array<string, mixed>  $source
     *
     * @throws ValidationException
     */
    public function apply(
        Builder $query,
        array $source,
        ReportDrillDownData $drillDown,
        ObjectType $objectType,
        User $viewer,
    ): void {
        $definition = $this->definition($source, $objectType, $viewer);

        if ($definition->groupByLink instanceof ReportLinkedFieldBinding) {
            throw $this->refuse(__('i18n.backend.support.reports.report_grid_scope.reports_grouped_by_a_related_field_do_not_support'));
        }

        $groupField = $definition->groupByField;

        if (!$groupField instanceof FieldDefinition) {
            throw $this->refuse(__('i18n.backend.support.reports.report_grid_scope.this_report_has_no_grouping_to_drill_into'));
        }

        $seriesField = $definition->seriesField;

        if (($seriesField instanceof FieldDefinition) !== ($drillDown->seriesToken !== null)) {
            throw $this->refuse(__('i18n.backend.support.reports.report_grid_scope.a_series_identifier_is_only_valid_for_a_report'));
        }

        $groupValue = $this->value($drillDown->groupToken);
        $seriesValue = $drillDown->seriesToken === null ? null : $this->value($drillDown->seriesToken);

        try {
            $groupExpression = $definition->groupByBucket === null
                ? $this->expressions->groupingExpression($groupField)
                : $this->expressions->bucketExpression($groupField, $definition->groupByBucket);
            $seriesExpression = $seriesField instanceof FieldDefinition
                ? $this->expressions->groupingExpression($seriesField)
                : null;
        } catch (InvalidArgumentException|UnsupportedAggregationException $exception) {
            throw $this->refuse($exception->getMessage());
        }

        $this->filters->applyValidatedTree(
            $query,
            new Collection(array_values($definition->fields)),
            $definition->filterTree,
        );

        $query->whereRaw("{$groupExpression} IS NOT DISTINCT FROM ?", [$groupValue]);

        if ($seriesExpression !== null) {
            $query->whereRaw("{$seriesExpression} IS NOT DISTINCT FROM ?", [$seriesValue]);
        }
    }

    /**
     * @param  array<string, mixed>  $source
     *
     * @throws ValidationException
     */
    private function definition(array $source, ObjectType $objectType, User $viewer): ReportDefinitionData
    {
        try {
            return $this->definitionValidator->validate($source, $objectType, $viewer);
        } catch (ReportNotExecutableException $exception) {
            throw $this->refuse($exception->getMessage());
        }
    }

    /**
     * @throws ValidationException
     */
    private function value(string $token): ?string
    {
        if ($token === 'null') {
            return null;
        }

        if ($token === 'other') {
            throw $this->refuse(__('i18n.backend.support.reports.report_grid_scope.the_combined_group_contains_suppressed_groups_and_cannot_be'));
        }

        if (!str_starts_with($token, 'v:')) {
            throw $this->refuse(__('i18n.backend.support.reports.report_grid_scope.a_drill_down_token_must_be_null_or_carry'));
        }

        return substr($token, 2);
    }

    private function refuse(string $message): ValidationException
    {
        return ValidationException::withMessages(['report' => $message]);
    }
}
