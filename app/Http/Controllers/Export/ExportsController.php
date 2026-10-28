<?php

declare(strict_types=1);

namespace App\Http\Controllers\Export;

use App\Actions\Export\StartRecordExportAction;
use App\Contracts\Export\ExportDispatcherInterface;
use App\Enums\Export\ExportJobStatus;
use App\Http\Controllers\Abstracts\Controller;
use App\Models\ExportJob;
use App\Models\ObjectType;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportsController extends Controller
{
    public function __construct(
        private readonly StartRecordExportAction $startExport,
        private readonly ExportDispatcherInterface $dispatcher,
    ) {}

    public function store(Request $request, ObjectType $objectType): JsonResponse
    {
        $user = $this->actingUser($request);

        $exportJob = $this->startExport->execute($objectType, $user, $request->all());

        $this->dispatcher->start($exportJob);

        $exportJobId = (string) $exportJob->getKey();

        return new JsonResponse([
            'batchId' => $exportJobId,
            'exportJobId' => $exportJobId,
        ], Response::HTTP_ACCEPTED);
    }

    public function status(Request $request, ObjectType $objectType, string $batch): JsonResponse
    {
        $user = $this->actingUser($request);

        $exportJob = ExportJob::query()
            ->withoutGlobalScopes()
            ->whereKey($batch)
            ->where('tenant_id', $user->tenant_id)
            ->where('user_id', $user->getKey())
            ->where('object_type_id', $objectType->getKey())
            ->first();

        if (!$exportJob instanceof ExportJob || $this->isReportExport($exportJob)) {
            return new JsonResponse(['message' => __('i18n.backend.http.controllers.export.exports_controller.the_export_run_was_not_found')], Response::HTTP_NOT_FOUND);
        }

        $finished = in_array($exportJob->status, [ExportJobStatus::Completed, ExportJobStatus::Failed], true);
        $failed = $exportJob->status === ExportJobStatus::Failed;

        return new JsonResponse([
            'batchId' => (string) $exportJob->getKey(),
            'totalJobs' => 1,
            'pendingJobs' => $finished ? 0 : 1,
            'processedJobs' => $finished ? 1 : 0,
            'failedJobs' => $failed ? 1 : 0,
            'progress' => $finished ? 100 : 0,
            'finished' => $finished,
            'cancelled' => false,
        ]);
    }

    public function history(Request $request, ObjectType $objectType): JsonResponse
    {
        $user = $this->actingUser($request);

        $jobs = ExportJob::query()
            ->where('object_type_id', $objectType->getKey())
            ->where('user_id', $user->getKey())
            ->latest()
            ->get()
            ->reject(fn (ExportJob $exportJob): bool => $this->isReportExport($exportJob))
            ->values();

        return new JsonResponse(['data' => $jobs]);
    }

    public function download(Request $request, ObjectType $objectType, ExportJob $exportJob): StreamedResponse
    {
        $user = $this->actingUser($request);

        if (
            $exportJob->object_type_id !== $objectType->getKey()
            || $exportJob->tenant_id !== (string) $user->tenant_id
            || $exportJob->user_id !== (string) $user->getKey()
            || $exportJob->result_path === null
            || $this->isReportExport($exportJob)
        ) {
            throw new AuthorizationException(__('i18n.backend.http.controllers.export.exports_controller.there_is_no_file_for_this_export'));
        }

        $disk = (string) config('engine.bulk.export_disk', config('filesystems.default'));

        return Storage::disk($disk)->download(
            $exportJob->result_path,
            basename($exportJob->result_path),
        );
    }

    private function isReportExport(ExportJob $exportJob): bool
    {
        return ($exportJob->scope['kind'] ?? null) === 'report';
    }
}
