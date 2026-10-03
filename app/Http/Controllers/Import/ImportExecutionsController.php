<?php

declare(strict_types=1);

namespace App\Http\Controllers\Import;

use App\Actions\Import\PrepareRecordImportAction;
use App\Contracts\Import\ImportDispatcherInterface;
use App\Enums\Import\ImportJobStatus;
use App\Http\Controllers\Abstracts\Controller;
use App\Models\ImportJob;
use App\Models\ObjectType;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ImportExecutionsController extends Controller
{
    private int $chunkSize = 250;

    public function __construct(
        private readonly PrepareRecordImportAction $prepareImport,
        private readonly ImportDispatcherInterface $dispatcher,
    ) {}

    public function execute(Request $request, ObjectType $objectType): JsonResponse
    {
        $user = $this->actingUser($request);

        try {
            $importJob = $this->prepareImport->execute($objectType, $user, $request->all());
        } catch (ValidationException $exception) {
            return new JsonResponse([
                'message' => $exception->getMessage(),
                'errors' => $exception->errors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $sheet = $request->input('sheet');
        $sheet = is_string($sheet) && $sheet !== '' ? $sheet : null;

        $this->dispatcher->start($importJob, $sheet, $this->chunkSize);

        $importJobId = (string) $importJob->getKey();

        return new JsonResponse([
            'batchId' => $importJobId,
            'importJobId' => $importJobId,
        ], Response::HTTP_ACCEPTED);
    }

    public function status(Request $request, ObjectType $objectType, string $batch): JsonResponse
    {
        $user = $this->actingUser($request);

        $importJob = ImportJob::query()
            ->withoutGlobalScopes()
            ->whereKey($batch)
            ->where('tenant_id', $user->tenant_id)
            ->where('user_id', $user->getKey())
            ->where('object_type_id', $objectType->getKey())
            ->first();

        if (!$importJob instanceof ImportJob) {
            return new JsonResponse(['message' => __('i18n.backend.http.controllers.import.import_executions_controller.the_import_run_was_not_found')], Response::HTTP_NOT_FOUND);
        }

        $finished = in_array($importJob->status, [ImportJobStatus::Completed, ImportJobStatus::Failed], true);
        $total = $importJob->total_rows;
        $processed = $importJob->created_count + $importJob->updated_count + $importJob->error_count;

        return new JsonResponse([
            'batchId' => (string) $importJob->getKey(),
            'totalJobs' => $total,
            'pendingJobs' => $finished ? 0 : max(0, $total - $processed),
            'processedJobs' => $processed,
            'failedJobs' => 0,
            'progress' => $finished ? 100 : ($total > 0 ? (int) round(100 * $processed / $total) : 0),
            'finished' => $finished,
            'cancelled' => false,
        ]);
    }

    public function history(Request $request, ObjectType $objectType): JsonResponse
    {
        $user = $this->actingUser($request);

        $jobs = ImportJob::query()
            ->where('object_type_id', $objectType->getKey())
            ->where('user_id', $user->getKey())
            ->latest()
            ->get();

        return new JsonResponse(['data' => $jobs]);
    }

    public function errorReport(Request $request, ObjectType $objectType, ImportJob $importJob): StreamedResponse
    {
        $user = $this->actingUser($request);

        if (
            $importJob->object_type_id !== $objectType->getKey()
            || $importJob->tenant_id !== (string) $user->tenant_id
            || $importJob->user_id !== (string) $user->getKey()
            || $importJob->error_report_path === null
        ) {
            throw new AuthorizationException(__('i18n.backend.http.controllers.import.import_executions_controller.there_is_no_error_report_for_this_import_run'));
        }

        return Storage::disk($importJob->source_disk)->download(
            $importJob->error_report_path,
            "import-errors-{$importJob->getKey()}.csv",
        );
    }
}
