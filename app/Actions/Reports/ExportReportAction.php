<?php

declare(strict_types=1);

namespace App\Actions\Reports;

use App\Enums\Export\ExportFormat;
use App\Enums\Export\ExportJobStatus;
use App\Models\ExportJob;
use App\Models\Report;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ExportReportAction
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function execute(Report $report, User $user, array $input): ExportJob
    {
        $validated = Validator::make(
            $input,
            [
                'format' => [
                    'required',
                    'string',
                    Rule::in([ExportFormat::Csv->value, ExportFormat::Xlsx->value]),
                ],
            ]
        )->validate();

        return ExportJob::query()->create([
            'tenant_id' => (string) $user->tenant_id,
            'object_type_id' => $report->object_type_id,
            'user_id' => (string) $user->getKey(),
            'status' => ExportJobStatus::Running,
            'format' => ExportFormat::from((string) $validated['format']),
            'scope' => ['kind' => 'report', 'reportId' => (string) $report->getKey()],
            'fields' => [],
            'started_at' => now(),
        ]);
    }
}
