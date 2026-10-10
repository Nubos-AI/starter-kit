<?php

declare(strict_types=1);

namespace App\Actions\Engine;

use App\Enums\Engine\RelationDirection;
use App\Models\CustomRecord;
use App\Models\RecordLink;
use App\Models\User;
use App\Support\Engine\RecordRelationResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Throwable;

class UnlinkRecordRelationAction
{
    public function __construct(
        private readonly UnlinkRecordsAction $unlinkRecords,
        private readonly ReparentRecordAction $reparentRecord,
        private readonly RecordRelationResolver $resolver,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws ModelNotFoundException
     * @throws ValidationException
     * @throws Throwable
     */
    public function execute(User $actor, CustomRecord $record, string $linkId): void
    {
        $record->loadMissing('objectType');

        $link = RecordLink::query()
            ->whereKey($linkId)
            ->where(fn ($query) => $query
                ->where('from_record_id', $record->getKey())
                ->orWhere('to_record_id', $record->getKey()))
            ->firstOrFail();

        $type = $this->resolver->typeFor($record->objectType, $link->relationship_type_id);
        $direction = $link->from_record_id === (string) $record->getKey()
            ? RelationDirection::Outgoing
            : RelationDirection::Incoming;
        $this->resolver->assertMayViewCounterpart($actor, $type, $direction);

        if (!$type->is_hierarchy) {
            $this->unlinkRecords->execute($link);

            return;
        }

        $this->resolver->assertMayReparent($actor, $record);

        $child = CustomRecord::query()->whereKey($link->to_record_id)->firstOrFail();

        $this->reparentRecord->execute($child, ['parent_record_id' => null]);
    }
}
