<?php

declare(strict_types=1);

namespace App\Support\Reports;

use App\DTOs\Reports\ReportDrillDownData;
use Illuminate\Validation\ValidationException;

class ReportDrillDownInput
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(string $prefix = ''): array
    {
        return [
            "{$prefix}report" => ['nullable', 'string'],
            "{$prefix}dashboard" => ['nullable', 'string'],
            "{$prefix}widget" => ['nullable', 'string', "required_with:{$prefix}dashboard"],
            "{$prefix}group" => ['nullable', 'string', "required_with:{$prefix}report,{$prefix}dashboard"],
            "{$prefix}series" => ['nullable', 'string'],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function parse(array $input, ?string $segmentId, string $errorPrefix = ''): ?ReportDrillDownData
    {
        $reportId = $input['report'] ?? null;
        $dashboardId = $input['dashboard'] ?? null;
        $widgetId = $input['widget'] ?? null;
        $groupToken = $input['group'] ?? null;
        $seriesToken = $input['series'] ?? null;

        $fromReport = is_string($reportId);
        $fromWidget = is_string($dashboardId) && is_string($widgetId);
        $hasSelection = is_string($groupToken) || is_string($seriesToken);

        if (!$fromReport && !$fromWidget && !$hasSelection) {
            return null;
        }

        if ($fromReport === $fromWidget) {
            throw ValidationException::withMessages([
                "{$errorPrefix}report" => __('i18n.backend.support.reports.report_drill_down_input.drill_down_requires_exactly_one_source_either_a_report'),
            ]);
        }

        if (!is_string($groupToken)) {
            throw ValidationException::withMessages([
                "{$errorPrefix}group" => __('i18n.backend.support.reports.report_drill_down_input.the_drill_down_request_must_specify_which_section_of'),
            ]);
        }

        if ($segmentId !== null) {
            throw ValidationException::withMessages([
                "{$errorPrefix}report" => __('i18n.backend.support.reports.report_drill_down_input.drill_down_cannot_be_combined_with_a_segment_because'),
            ]);
        }

        return new ReportDrillDownData(
            $fromReport ? $reportId : null,
            $fromWidget ? $dashboardId : null,
            $fromWidget ? $widgetId : null,
            $groupToken,
            is_string($seriesToken) ? $seriesToken : null,
        );
    }
}
