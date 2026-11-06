<?php

declare(strict_types=1);

namespace App\Actions\Engine;

use App\Enums\Engine\RelationDirection;
use App\Models\CustomRecord;
use App\Models\User;
use App\Support\Engine\RecordRelationResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class LinkRecordRelationAction
{
    public function __construct(
        private readonly LinkRecordsAction $linkRecords,
        private readonly ReparentRecordAction $reparentRecord,
        private readonly RecordRelationResolver $resolver,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws Throwable
     */
    public function execute(User $actor, CustomRecord $record, array $input): void
    {
        $validated = Validator::make($input, [
            'relationship_type_id' => ['required', 'string', 'ulid'],
            'direction' => ['required', Rule::enum(RelationDirection::class)],
            'target_record_id' => ['required', 'string', 'ulid'],
        ])->validate();

        $record->loadMissing('objectType');

        $direction = RelationDirection::from((string) $validated['direction']);
        $type = $this->resolver->typeFor($record->objectType, (string) $validated['relationship_type_id']);
        $this->resolver->assertMayViewCounterpart($actor, $type, $direction);
        $target = $this->resolver->targetFor($record, $type, $direction, (string) $validated['target_record_id']);

        if (!$type->is_hierarchy) {
            $this->linkRecords->execute([
                'relationship_type_id' => $type->getKey(),
                'from_record_id' => $direction->isOutgoing() ? $record->getKey() : $target->getKey(),
                'to_record_id' => $direction->isOutgoing() ? $target->getKey() : $record->getKey(),
                'position' => 0,
            ]);

            return;
        }

        $this->resolver->assertMayReparent($actor, $record);

        $direction->isOutgoing()
            ? $this->reparentRecord->execute($target, ['parent_record_id' => (string) $record->getKey()])
            : $this->reparentRecord->execute($record, ['parent_record_id' => (string) $target->getKey()]);
    }
}
