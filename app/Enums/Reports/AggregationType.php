<?php

declare(strict_types=1);

namespace App\Enums\Reports;

use App\Enums\CustomFields\FieldType;

enum AggregationType: string
{
    case Count = 'count';

    case Sum = 'sum';

    case Avg = 'avg';

    case Min = 'min';

    case Max = 'max';

    case DistinctCount = 'distinct_count';

    public function label(): string
    {
        return match ($this) {
            self::Count => __('i18n.backend.enums.reports.aggregation_type.count'),
            self::Sum => __('i18n.backend.enums.reports.aggregation_type.sum'),
            self::Avg => __('i18n.backend.enums.reports.aggregation_type.average'),
            self::Min => __('i18n.backend.enums.reports.aggregation_type.minimum'),
            self::Max => __('i18n.backend.enums.reports.aggregation_type.maximum'),
            self::DistinctCount => __('i18n.backend.enums.reports.aggregation_type.distinct_values'),
        };
    }

    public function allowsFieldType(FieldType $fieldType): bool
    {
        $isNumeric = match ($fieldType) {
            FieldType::Number, FieldType::Decimal, FieldType::Money,
            FieldType::Computed, FieldType::Rollup => true,
            default => false,
        };

        $isTemporal = match ($fieldType) {
            FieldType::Date, FieldType::DateTime => true,
            default => false,
        };

        return match ($this) {
            self::Count, self::DistinctCount => true,
            self::Sum, self::Avg => $isNumeric,
            self::Min, self::Max => $isNumeric || $isTemporal,
        };
    }
}
