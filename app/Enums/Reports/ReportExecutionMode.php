<?php

declare(strict_types=1);

namespace App\Enums\Reports;

enum ReportExecutionMode: string
{
    case Viewer = 'viewer';

    case Definer = 'definer';

    public function label(): string
    {
        return match ($this) {
            self::Viewer => __('i18n.backend.enums.reports.report_execution_mode.with_the_viewer_s_permissions'),
            self::Definer => __('i18n.backend.enums.reports.report_execution_mode.with_my_permissions'),
        };
    }
}
