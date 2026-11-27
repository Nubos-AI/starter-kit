<?php

declare(strict_types=1);

namespace App\Enums\Reports;

enum GroupingBucket: string
{
    case Day = 'day';

    case Week = 'week';

    case Month = 'month';

    case Quarter = 'quarter';

    case Year = 'year';

    public function label(): string
    {
        return match ($this) {
            self::Day => __('i18n.backend.enums.reports.grouping_bucket.day'),
            self::Week => __('i18n.backend.enums.reports.grouping_bucket.week'),
            self::Month => __('i18n.backend.enums.reports.grouping_bucket.month'),
            self::Quarter => __('i18n.backend.enums.reports.grouping_bucket.quarter'),
            self::Year => __('i18n.backend.enums.reports.grouping_bucket.year'),
        };
    }
}
