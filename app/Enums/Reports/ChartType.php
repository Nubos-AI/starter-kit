<?php

declare(strict_types=1);

namespace App\Enums\Reports;

enum ChartType: string
{
    case Metric = 'metric';

    case Bar = 'bar';

    case Line = 'line';

    case Pie = 'pie';

    case Area = 'area';

    case Donut = 'donut';

    public function label(): string
    {
        return match ($this) {
            self::Metric => __('i18n.backend.enums.reports.chart_type.metric'),
            self::Bar => __('i18n.backend.enums.reports.chart_type.bar'),
            self::Line => __('i18n.backend.enums.reports.chart_type.line'),
            self::Pie => __('i18n.backend.enums.reports.chart_type.pie'),
            self::Area => __('i18n.backend.enums.reports.chart_type.area'),
            self::Donut => __('i18n.backend.enums.reports.chart_type.donut'),
        };
    }
}
