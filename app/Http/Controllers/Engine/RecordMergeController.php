<?php

declare(strict_types=1);

namespace App\Http\Controllers\Engine;

use App\Actions\Engine\MergeRecordsAction;
use App\Actions\Engine\UndoRecordMergeAction;
use App\DTOs\Engine\MergeRequestData;
use App\Exceptions\Engine\MergeRefusedException;
use App\Exceptions\Engine\MergeUndoRefusedException;
use App\Http\Controllers\Abstracts\Controller;
use App\Http\Resources\Engine\MergePlanResource;
use App\Http\Resources\RecordResource;
use App\Models\CustomRecord;
use App\Models\ObjectType;
use App\Models\RecordMerge;
use App\Support\Engine\MergePlanner;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class RecordMergeController extends Controller
{
    private int $candidateLimit = 50;

    public function __construct(
        private readonly MergePlanner $planner,
        private readonly MergeRecordsAction $mergeRecords,
        private readonly UndoRecordMergeAction $undoRecordMerge,
    ) {}

    public function show(Request $request, CustomRecord $record): Response
    {
        $this->authorize('merge', $record);

        return Inertia::render('records/Merge', [
            'objectType' => [
                'slug' => $record->objectType->slug,
                'name' => $record->objectType->name,
            ],
            'record' => (new RecordResource($record))->resolve($request),
            'candidates' => $this->candidatePayload($record),
        ]);
    }

    public function candidates(Request $request, CustomRecord $record): JsonResponse
    {
        $this->authorize('merge', $record);

        return new JsonResponse(['data' => $this->candidatePayload($record)]);
    }

    public function preview(Request $request, CustomRecord $record): JsonResponse
    {
        $this->authorize('merge', $record);

        $mergeRequest = $this->mergeRequest($request, $record);

        [$target, $source] = $this->authorizePair($record, $mergeRequest);

        $plan = $this->planner->plan($target, $source, $mergeRequest);

        return new JsonResponse(['data' => (new MergePlanResource($plan))->resolve($request)]);
    }

    public function store(Request $request, CustomRecord $record): JsonResponse
    {
        $this->authorize('merge', $record);

        $mergeRequest = $this->mergeRequest($request, $record);

        $this->authorizePair($record, $mergeRequest);

        try {
            $merge = $this->mergeRecords->execute($mergeRequest);
        } catch (MergeRefusedException $exception) {
            return new JsonResponse([
                'message' => __('i18n.backend.http.controllers.engine.record_merge_controller.merging_was_denied'),
                'blockers' => $exception->payload(),
            ], 422);
        }

        return new JsonResponse([
            'data' => [
                'merge_id' => (string) $merge->getKey(),
                'target_id' => $merge->target_record_id,
                'source_id' => $merge->source_record_id,
            ],
        ], 201);
    }

    public function undo(Request $request, CustomRecord $record, RecordMerge $merge): JsonResponse
    {
        $this->authorize('merge', $record);

        if ($merge->target_record_id !== (string) $record->getKey()) {
            throw new NotFoundHttpException;
        }

        try {
            $report = $this->undoRecordMerge->execute($merge);
        } catch (MergeUndoRefusedException $exception) {
            return new JsonResponse([
                'message' => __('i18n.backend.http.controllers.engine.record_merge_controller.the_merge_can_no_longer_be_reverted'),
                'reason' => $exception->reason->value,
            ], 422);
        }

        return new JsonResponse([
            'data' => [
                'merge_id' => $report->mergeId,
                'target_id' => $report->targetId,
                'source_id' => $report->sourceId,
                'restored_fields' => $report->restoredFields,
                'kept_fields' => $report->keptFields,
                'restored_transfers' => $report->restoredTransfers,
                'unrecoverable' => $report->unrecoverable,
            ],
        ]);
    }

    private function mergeRequest(Request $request, CustomRecord $record): MergeRequestData
    {
        $validated = $request->validate([
            'targetId' => ['required', 'string'],
            'sourceId' => ['required', 'string'],
            'targetVersion' => ['nullable', 'integer'],
            'sourceVersion' => ['nullable', 'integer'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'overrides' => ['array'],
            'overrides.*' => ['string', 'in:target,source'],
        ]);

        $targetId = (string) $validated['targetId'];
        $sourceId = (string) $validated['sourceId'];

        if (!in_array((string) $record->getKey(), [$targetId, $sourceId], true)) {
            throw new AuthorizationException(__('i18n.backend.http.controllers.engine.record_merge_controller.the_merge_must_include_the_record_from_which_it'));
        }

        /** @var array<string, string> $overrides */
        $overrides = $validated['overrides'] ?? [];

        return new MergeRequestData(
            $targetId,
            $sourceId,
            isset($validated['targetVersion']) ? (int) $validated['targetVersion'] : null,
            isset($validated['sourceVersion']) ? (int) $validated['sourceVersion'] : null,
            $validated['reason'] ?? null,
            $overrides,
        );
    }

    /**
     * @return array{0: CustomRecord, 1: CustomRecord}
     */
    private function authorizePair(CustomRecord $record, MergeRequestData $mergeRequest): array
    {
        $recordId = (string) $record->getKey();

        $target = $mergeRequest->targetId === $recordId
            ? $record
            : CustomRecord::query()->whereKey($mergeRequest->targetId)->firstOrFail();

        $source = $mergeRequest->sourceId === $recordId
            ? $record
            : CustomRecord::query()->whereKey($mergeRequest->sourceId)->firstOrFail();

        $this->authorize('merge', $target);
        $this->authorize('merge', $source);
        $this->authorize('update', $target);
        $this->authorize('delete', $source);

        return [$target, $source];
    }

    /**
     * @return array<string, mixed>
     */
    private function candidatePayload(CustomRecord $record): array
    {
        $objectType = ObjectType::query()->whereKey($record->object_type_id)->firstOrFail();

        $duplicateIds = [];

        foreach ($objectType->findDuplicates($record->data ?? [], $record->tenant_id) as $candidate) {
            if ($candidate->getKey() !== $record->getKey()) {
                $duplicateIds[] = (string) $candidate->getKey();
            }
        }

        $rows = CustomRecord::query()
            ->ofType($record->object_type_id)
            ->whereKeyNot($record->getKey())
            ->whereNull('merged_into_record_id')
            ->orderByRaw('updated_at DESC')
            ->limit($this->candidateLimit + 1)
            ->get(['id', 'record_number', 'updated_at']);

        $isTruncated = $rows->count() > $this->candidateLimit;

        return [
            'suggested_ids' => $duplicateIds,
            'options' => $this->options($rows->take($this->candidateLimit), $duplicateIds),
            'is_truncated' => $isTruncated,
        ];
    }

    /**
     * @param  Collection<int, CustomRecord>  $rows
     * @param  list<string>  $duplicateIds
     * @return list<array<string, mixed>>
     */
    private function options(Collection $rows, array $duplicateIds): array
    {
        $options = [];

        foreach ($rows as $candidate) {
            $option = [
                'value' => (string) $candidate->getKey(),
                'label' => $this->label($candidate),
            ];

            if (in_array((string) $candidate->getKey(), $duplicateIds, true)) {
                $option['description'] = __('i18n.backend.http.controllers.engine.record_merge_controller.possible_duplicate');
            }

            $options[] = $option;
        }

        return $options;
    }

    private function label(CustomRecord $record): string
    {
        $number = $record->record_number;

        return $number === null || $number === '' ? (string) $record->getKey() : $number;
    }
}
