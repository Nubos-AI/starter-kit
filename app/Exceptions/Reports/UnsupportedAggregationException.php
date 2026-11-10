<?php

declare(strict_types=1);

namespace App\Exceptions\Reports;

use App\Enums\CustomFields\FieldType;
use App\Enums\Reports\AggregationType;
use RuntimeException;

class UnsupportedAggregationException extends RuntimeException
{
    public static function forFieldType(AggregationType $aggregation, FieldType $fieldType): self
    {
        return new self(
            __('i18n.backend.exceptions.reports.unsupported_aggregation_exception.the_aggregate_is_not_supported_for_field_type', ['value1' => $aggregation->value, 'value2' => $fieldType->value]),
        );
    }

    public static function forEncryptedField(): self
    {
        return new self(__('i18n.backend.exceptions.reports.unsupported_aggregation_exception.encrypted_fields_cannot_be_analysed_grouped_or_bucketed'));
    }

    public static function forTranslatableField(): self
    {
        return new self(__('i18n.backend.exceptions.reports.unsupported_aggregation_exception.translatable_fields_cannot_be_analysed_grouped_or_bucketed'));
    }

    public static function forNonTemporalField(FieldType $fieldType): self
    {
        return new self(__('i18n.backend.exceptions.reports.unsupported_aggregation_exception.a_date_or_time_field_is_required_here_was', ['value1' => $fieldType->value]));
    }

    public static function forNonNumericField(FieldType $fieldType): self
    {
        return new self(__('i18n.backend.exceptions.reports.unsupported_aggregation_exception.a_numeric_field_is_required_here_was_selected', ['value1' => $fieldType->value]));
    }

    public static function forSystemField(): self
    {
        return new self(__('i18n.backend.exceptions.reports.unsupported_aggregation_exception.a_system_field_does_not_contain_an_analysable_value'));
    }

    public static function forMissingField(AggregationType $aggregation): self
    {
        return new self(__('i18n.backend.exceptions.reports.unsupported_aggregation_exception.the_aggregate_requires_a_field', ['value1' => $aggregation->value]));
    }
}
