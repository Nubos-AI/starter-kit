<?php

declare(strict_types=1);

namespace App\Http\Controllers\Engine;

use App\Actions\Engine\ReparentRecordAction;
use App\Enums\Authorization\ObjectTypeAbility;
use App\Http\Controllers\Abstracts\Controller;
use App\Models\CustomRecord;
use App\Models\User;
use App\Support\Engine\RecordTreeQuery;
use App\Traits\Http\RespondsWithValidationErrors;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class RecordHierarchiesController extends Controller
{
    use RespondsWithValidationErrors;

    private int $candidateLimit = 50;

    public function __construct(
        private readonly ReparentRecordAction $reparentRecordAction,
        private readonly RecordTreeQuery $recordTreeQuery,
    ) {}

    public function update(Request $request, string $record): JsonResponse
    {
        $user = $this->actingUser($request);
        $subject = $this->resolveRecord($record);

        $this->authorizeReparent($user, $subject);

        $submitted = $request->input('parent_record_id');
        $parentRecordId = is_string($submitted) ? $submitted : null;

        try {
            $this->reparentRecordAction->execute($subject, $request->only(['parent_record_id']));
        } catch (ValidationException $exception) {
            return $this->validationResponse($exception);
        }

        return new JsonResponse(['data' => ['parentRecordId' => $parentRecordId]]);
    }

    public function candidates(Request $request, string $record): JsonResponse
    {
        $user = $this->actingUser($request);
        $subject = $this->resolveRecord($record);

        $this->authorizeReparent($user, $subject);

        $excludedIds = [(string) $subject->getKey()];
        $isTruncated = false;

        if ($subject->objectType->hasHierarchy()) {
            $descendants = $this->recordTreeQuery->descendantsOf(
                $subject->tenant_id,
                (string) $subject->objectType->hierarchy_relationship_type_id,
                (string) $subject->getKey(),
            );

            $isTruncated = $descendants->isTruncated;

            foreach ($descendants->nodes as $descendant) {
                $excludedIds[] = $descendant->recordId;
            }
        }

        $rows = CustomRecord::query()
            ->where('tenant_id', $subject->tenant_id)
            ->ofType($subject->object_type_id)
            ->whereNotIn('id', $excludedIds)
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit($this->candidateLimit + 1)
            ->get(['id', 'record_number']);

        return new JsonResponse([
            'data' => [
                'candidates' => $rows
                    ->take($this->candidateLimit)
                    ->map(fn (CustomRecord $candidate): array => [
                        'id' => (string) $candidate->getKey(),
                        'label' => $this->candidateLabel($candidate),
                    ])
                    ->values()
                    ->all(),
                'isTruncated' => $isTruncated || $rows->count() > $this->candidateLimit,
            ],
        ]);
    }

    /**
     * @throws AuthorizationException
     */
    private function authorizeReparent(User $user, CustomRecord $record): void
    {
        if ($user->cannot('view', $record)) {
            throw new AuthorizationException(__('i18n.backend.http.controllers.engine.record_hierarchies_controller.you_may_not_view_this_record'));
        }

        if (!$user->hasPermission("{$record->objectType->slug}.".ObjectTypeAbility::Reparent->value)) {
            throw new AuthorizationException(__('i18n.backend.http.controllers.engine.record_hierarchies_controller.you_may_not_move_this_record_in_the_hierarchy'));
        }
    }

    private function resolveRecord(string $id): CustomRecord
    {
        return CustomRecord::query()
            ->with('objectType')
            ->whereKey($id)
            ->firstOrFail();
    }

    private function candidateLabel(CustomRecord $record): string
    {
        $recordNumber = $record->record_number;

        return $recordNumber === null || $recordNumber === ''
            ? (string) $record->getKey()
            : $recordNumber;
    }
}
