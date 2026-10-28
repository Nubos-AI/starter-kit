<?php

declare(strict_types=1);

namespace App\Http\Controllers\Engine;

use App\Contracts\Engine\BulkDispatcherInterface;
use App\DTOs\Engine\BulkActionData;
use App\Http\Controllers\Abstracts\Controller;
use App\Models\ObjectType;
use App\Models\TenantSetting;
use App\Models\User;
use App\Support\Engine\BulkBatchReport;
use App\Support\Engine\RecordSelectionResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BulkActionsController extends Controller
{
    private int $defaultChunkSize = 250;

    /**
     * @var array<string, int>
     */
    private array $actionChunkSizes = [
        'soft-delete' => 100,
        'export-csv' => PHP_INT_MAX,
    ];

    /**
     * @var array<string, string>
     */
    private array $actionAbilities = [
        'set-field' => 'update',
        'soft-delete' => 'delete',
        'restore' => 'delete',
        'export-csv' => 'view',
    ];

    public function __construct(
        private readonly RecordSelectionResolver $selectionResolver,
        private readonly BulkDispatcherInterface $dispatcher,
    ) {}

    public function store(Request $request, ObjectType $objectType): JsonResponse
    {
        $user = $this->actingUser($request);

        $validated = $request->validate(array_merge(
            $this->selectionResolver->selectionRules(withSortModel: true),
            ['action' => ['required', 'string'], 'payload' => ['array']],
        ));

        $action = (string) $validated['action'];
        $ability = $this->actionAbilities[$action] ?? null;

        if ($ability === null) {
            return $this->denyResponse(__('i18n.backend.http.controllers.engine.bulk_actions_controller.this_bulk_action_is_not_supported'));
        }

        if (!$user->hasPermission("{$objectType->slug}.{$ability}")) {
            throw new AuthorizationException(__('i18n.backend.http.controllers.engine.bulk_actions_controller.you_may_not_perform_this_bulk_action'));
        }

        /** @var array<string, mixed> $selection */
        $selection = $validated['selection'];

        /** @var array<string, mixed> $payload */
        $payload = is_array($validated['payload'] ?? null) ? $validated['payload'] : [];

        $filterScope = $this->selectionResolver->filterScope($objectType, $user);

        $chunks = $this->selectionResolver->chunkedIds(
            $this->selectionResolver->scopedQuery(
                $objectType,
                $selection,
                $filterScope['fields'],
                $filterScope['expressions'],
                $filterScope['readable'],
            ),
            $this->actionChunkSizes[$action] ?? $this->defaultChunkSize,
        );

        $tenantId = (string) $user->tenant_id;
        $userId = (string) $user->getKey();

        $notify = in_array($ability, ['update', 'delete'], true);
        $threshold = $notify ? TenantSetting::forTenant($tenantId)->bulk_grouping_threshold : 0;

        $bulkRunId = (string) Str::uuid();

        BulkBatchReport::initProgress($bulkRunId, $tenantId, $userId, count($chunks));

        $this->dispatcher->start(new BulkActionData(
            bulkRunId: $bulkRunId,
            tenantId: $tenantId,
            actingUserId: $userId,
            objectTypeId: (string) $objectType->getKey(),
            action: $action,
            chunks: $chunks,
            payload: $payload,
            threshold: $threshold,
            objectTypeSlug: $objectType->slug,
            notify: $notify,
        ));

        return new JsonResponse(['batchId' => $bulkRunId], Response::HTTP_ACCEPTED);
    }

    public function show(Request $request, string $batchId): JsonResponse
    {
        $user = $this->actingUser($request);

        $progress = BulkBatchReport::progress($batchId);

        if ($progress === null || !$this->startedBy($progress, $user)) {
            return $this->notFoundResponse();
        }

        $total = $progress['total'];
        $processed = $progress['processed'];
        $finished = $progress['finished'];

        return new JsonResponse([
            'batchId' => $batchId,
            'totalJobs' => $total,
            'pendingJobs' => $finished ? 0 : max(0, $total - $processed),
            'processedJobs' => $processed,
            'failedJobs' => 0,
            'progress' => $finished ? 100 : ($total > 0 ? (int) round(100 * $processed / $total) : 0),
            'finished' => $finished,
            'cancelled' => false,
            'partialErrors' => BulkBatchReport::errors($batchId),
            'downloadUrl' => BulkBatchReport::downloadPath($batchId) === null
                ? null
                : route('engine.batches.download', ['batchId' => $batchId]),
        ]);
    }

    public function download(Request $request, string $batchId): StreamedResponse|JsonResponse
    {
        $user = $this->actingUser($request);

        $progress = BulkBatchReport::progress($batchId);
        $path = BulkBatchReport::downloadPath($batchId);

        if ($progress === null || $path === null || !$this->startedBy($progress, $user)) {
            return $this->notFoundResponse();
        }

        return Storage::disk((string) config('engine.bulk.export_disk', config('filesystems.default')))
            ->download($path, basename($path));
    }

    /**
     * @param  array{tenant_id: string, user_id: string, total: int, processed: int, finished: bool}  $progress
     */
    private function startedBy(array $progress, User $user): bool
    {
        return $progress['tenant_id'] === (string) $user->tenant_id
            && $progress['user_id'] === (string) $user->getKey();
    }

    private function notFoundResponse(): JsonResponse
    {
        return new JsonResponse(
            ['message' => __('i18n.backend.http.controllers.engine.bulk_actions_controller.the_batch_was_not_found')],
            Response::HTTP_NOT_FOUND,
        );
    }

    private function denyResponse(string $message): JsonResponse
    {
        return new JsonResponse(
            ['message' => $message, 'errors' => ['action' => [$message]]],
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }
}
