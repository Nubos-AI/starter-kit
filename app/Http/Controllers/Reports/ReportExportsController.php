<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reports;

use App\Actions\Reports\ExportReportAction;
use App\Http\Controllers\Abstracts\Controller;
use App\Models\ExportJob;
use App\Models\Report;
use App\Models\User;
use App\Support\Export\TemporalReportExportDispatcher;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportExportsController extends Controller
{
    public function __construct(
        private readonly ExportReportAction $exportReport,
        private readonly TemporalReportExportDispatcher $dispatcher,
    ) {}

    public function store(Request $request, Report $report): JsonResponse
    {
        $this->authorize('view', $report);

        $exportJob = $this->exportReport->execute($report, $this->actingUser($request), $request->all());

        $this->dispatcher->start($exportJob);

        return new JsonResponse(
            ['exportJobId' => (string) $exportJob->getKey()],
            Response::HTTP_ACCEPTED,
        );
    }

    public function download(Request $request, Report $report, ExportJob $exportJob): StreamedResponse|JsonResponse
    {
        $this->authorize('view', $report);

        $this->assertOwnJob($this->actingUser($request), $report, $exportJob);

        if ($exportJob->result_path === null) {
            return new JsonResponse(
                ['message' => __('i18n.backend.http.controllers.reports.report_exports_controller.the_report_export_is_not_ready_yet')],
                Response::HTTP_CONFLICT,
            );
        }

        $disk = (string) config('engine.bulk.export_disk', config('filesystems.default'));

        return Storage::disk($disk)->download(
            $exportJob->result_path,
            Str::slug($report->name).'.'.$exportJob->format->extension(),
        );
    }

    /**
     * @throws AuthorizationException
     */
    private function assertOwnJob(User $user, Report $report, ExportJob $exportJob): void
    {
        $belongsToUser = $exportJob->tenant_id === (string) $user->tenant_id
            && $exportJob->user_id === (string) $user->getKey();

        $belongsToReport = ($exportJob->scope['kind'] ?? null) === 'report'
            && ($exportJob->scope['reportId'] ?? null) === (string) $report->getKey();

        if (!$belongsToUser || !$belongsToReport) {
            throw new AuthorizationException(__('i18n.backend.http.controllers.reports.report_exports_controller.this_export_does_not_belong_to_you'));
        }
    }
}
