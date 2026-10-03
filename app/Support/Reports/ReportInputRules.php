<?php

declare(strict_types=1);

namespace App\Support\Reports;

use App\Enums\Reports\AggregationType;
use App\Enums\Reports\ChartType;
use App\Enums\Reports\GroupingBucket;
use App\Enums\Reports\ReportExecutionMode;
use App\Enums\Reports\ReportNotExecutableReason;
use App\Exceptions\Reports\ReportNotExecutableException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\ValidationException;

class ReportInputRules
{
    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(bool $withObjectType): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'filter_definition' => ['nullable', 'array'],
            'aggregation_type' => ['required', new Enum(AggregationType::class)],
            'aggregation_field_key' => ['nullable', 'string', 'max:255'],
            'group_by_field_key' => ['nullable', 'string', 'max:255'],
            'group_by_bucket' => ['nullable', new Enum(GroupingBucket::class)],
            'series_field_key' => ['nullable', 'string', 'max:255'],
            'chart_type' => ['required', new Enum(ChartType::class)],
            'execution_mode' => ['nullable', new Enum(ReportExecutionMode::class)],
        ];

        if ($withObjectType) {
            $rules['object_type_id'] = [
                'required',
                'string',
                Rule::exists('object_types', 'id')->where('tenant_id', (string) TenantContext::currentId()),
            ];
        }

        return $rules;
    }

    /**
     * @return list<string>
     */
    public static function definitionKeys(): array
    {
        return [
            'filter_definition',
            'aggregation_type',
            'aggregation_field_key',
            'group_by_field_key',
            'group_by_bucket',
            'series_field_key',
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public static function attributes(array $validated, ReportExecutionMode $mode): array
    {
        return [
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'filter_definition' => $validated['filter_definition'] ?? [],
            'aggregation_type' => $validated['aggregation_type'],
            'aggregation_field_key' => $validated['aggregation_field_key'] ?? null,
            'group_by_field_key' => $validated['group_by_field_key'] ?? null,
            'group_by_bucket' => $validated['group_by_bucket'] ?? null,
            'series_field_key' => $validated['series_field_key'] ?? null,
            'chart_type' => $validated['chart_type'],
            'execution_mode' => $mode,
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function toValidationException(ReportNotExecutableException $exception, array $validated): ValidationException
    {
        return ValidationException::withMessages(
            array_fill_keys(self::fieldsFor($exception->reason, $validated), $exception->getMessage()),
        );
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return list<string>
     */
    private static function fieldsFor(ReportNotExecutableReason $reason, array $validated): array
    {
        return match ($reason) {
            ReportNotExecutableReason::InvalidFilterTree,
            ReportNotExecutableReason::FieldNotFilterable => ['filter_definition'],
            ReportNotExecutableReason::UnsupportedGrouping => ['group_by_bucket'],
            ReportNotExecutableReason::UnsupportedAggregation => ['aggregation_field_key'],
            ReportNotExecutableReason::MalformedDefinition => self::malformedFields($validated),
            ReportNotExecutableReason::UnknownField,
            ReportNotExecutableReason::FieldNotReadable,
            ReportNotExecutableReason::EncryptedField => self::referencedFields($validated),
            ReportNotExecutableReason::ReportMissing,
            ReportNotExecutableReason::SourceNotVisible => ['report_id'],
            ReportNotExecutableReason::GoalMissing,
            ReportNotExecutableReason::GoalNotVisible => ['goal_id'],
        };
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return list<string>
     */
    private static function malformedFields(array $validated): array
    {
        $missingGroupBy = ($validated['group_by_field_key'] ?? null) === null
            && (($validated['group_by_bucket'] ?? null) !== null || ($validated['series_field_key'] ?? null) !== null);

        return $missingGroupBy ? ['group_by_field_key'] : ['aggregation_field_key'];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return list<string>
     */
    private static function referencedFields(array $validated): array
    {
        /** @var list<string> $fields */
        $fields = [];

        foreach (['aggregation_field_key', 'group_by_field_key', 'series_field_key'] as $slot) {
            if (($validated[$slot] ?? null) !== null) {
                $fields[] = $slot;
            }
        }

        if (($validated['filter_definition'] ?? []) !== []) {
            $fields[] = 'filter_definition';
        }

        return $fields === [] ? ['aggregation_field_key'] : $fields;
    }
}
