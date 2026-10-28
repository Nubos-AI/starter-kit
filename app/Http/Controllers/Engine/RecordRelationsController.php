<?php

declare(strict_types=1);

namespace App\Http\Controllers\Engine;

use App\Actions\Engine\LinkRecordRelationAction;
use App\Actions\Engine\UnlinkRecordRelationAction;
use App\Enums\Engine\RelationDirection;
use App\Http\Controllers\Abstracts\Controller;
use App\Models\CustomRecord;
use App\Support\Engine\RecordRelationGroupBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class RecordRelationsController extends Controller
{
    public function __construct(
        private readonly LinkRecordRelationAction $linkRecordRelation,
        private readonly UnlinkRecordRelationAction $unlinkRecordRelation,
        private readonly RecordRelationGroupBuilder $groupBuilder,
    ) {}

    public function index(Request $request, CustomRecord $record): JsonResponse
    {
        $groups = $this->groupBuilder->build($record, $this->actingUser($request));

        return new JsonResponse(['data' => ['groups' => $groups]]);
    }

    /**
     * @throws ValidationException
     */
    public function entries(Request $request, CustomRecord $record): JsonResponse
    {
        $block = $this->blockOf($request);

        $page = $this->groupBuilder->entriesPage(
            $record,
            $this->actingUser($request),
            $block['relationshipTypeId'],
            $block['direction'],
            $block['offset'],
            $block['limit'],
            $block['search'],
        );

        return new JsonResponse(['data' => $page]);
    }

    /**
     * @throws ValidationException
     */
    public function candidates(Request $request, CustomRecord $record): JsonResponse
    {
        $block = $this->blockOf($request);

        $page = $this->groupBuilder->candidatesPage(
            $record,
            $this->actingUser($request),
            $block['relationshipTypeId'],
            $block['direction'],
            $block['offset'],
            $block['limit'],
            $block['search'],
        );

        return new JsonResponse(['data' => $page]);
    }

    /**
     * @throws Throwable
     */
    public function store(Request $request, CustomRecord $record): JsonResponse
    {
        $this->linkRecordRelation->execute($this->actingUser($request), $record, $request->all());

        return new JsonResponse(['data' => ['linked' => true]]);
    }

    /**
     * @throws Throwable
     */
    public function destroy(Request $request, CustomRecord $record, string $link): JsonResponse
    {
        $this->unlinkRecordRelation->execute($this->actingUser($request), $record, $link);

        return new JsonResponse(['data' => ['unlinked' => true]]);
    }

    /**
     * @return array{relationshipTypeId: string, direction: RelationDirection, offset: int, limit: int, search: string|null}
     *
     * @throws ValidationException
     */
    private function blockOf(Request $request): array
    {
        $validated = Validator::make($request->all(), [
            'relationshipTypeId' => ['required', 'string', 'ulid'],
            'direction' => ['required', 'string', Rule::enum(RelationDirection::class)],
            'startRow' => ['required', 'integer', 'min:0'],
            'endRow' => ['required', 'integer', 'gt:startRow'],
            'search' => ['nullable', 'string', 'max:255'],
        ])->validate();

        $offset = (int) $validated['startRow'];

        return [
            'relationshipTypeId' => (string) $validated['relationshipTypeId'],
            'direction' => RelationDirection::from((string) $validated['direction']),
            'offset' => $offset,
            'limit' => min((int) $validated['endRow'] - $offset, $this->maxBlockSize()),
            'search' => is_string($validated['search'] ?? null) ? $validated['search'] : null,
        ];
    }

    private function maxBlockSize(): int
    {
        return (int) config('engine.relations.max_block_size');
    }
}
