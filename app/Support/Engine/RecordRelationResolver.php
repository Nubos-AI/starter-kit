<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\Enums\Authorization\ObjectTypeAbility;
use App\Enums\Engine\RelationDirection;
use App\Models\CustomRecord;
use App\Models\ObjectType;
use App\Models\RelationshipType;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class RecordRelationResolver
{
    public function __construct(private readonly ObjectTypeRegistry $types) {}

    public function mayViewCounterpart(User $user, RelationshipType $type, RelationDirection $direction): bool
    {
        $counterpart = $this->types->find($this->counterpartObjectTypeId($type, $direction));

        return $counterpart instanceof ObjectType && $user->hasPermission("{$counterpart->slug}.view");
    }

    public function assertMayViewCounterpart(User $user, RelationshipType $type, RelationDirection $direction): void
    {
        if (!$this->mayViewCounterpart($user, $type, $direction)) {
            throw new AuthorizationException(__('i18n.backend.support.engine.record_relation_resolver.you_may_not_view_records_of_the_related_object'));
        }
    }

    /**
     * @return Collection<int, RelationshipType>
     */
    public function typesOf(ObjectType $objectType): Collection
    {
        return RelationshipType::query()
            ->where(fn (Builder $query): Builder => $query
                ->where('from_object_type_id', $objectType->getKey())
                ->orWhere('to_object_type_id', $objectType->getKey()))
            ->orderBy('name')
            ->get();
    }

    /**
     * @throws ValidationException
     */
    public function typeFor(ObjectType $objectType, string $relationshipTypeId): RelationshipType
    {
        $type = RelationshipType::query()->whereKey($relationshipTypeId)->first();

        $belongs = $type instanceof RelationshipType
            && ($type->from_object_type_id === (string) $objectType->getKey()
                || $type->to_object_type_id === (string) $objectType->getKey());

        if (!$belongs) {
            throw ValidationException::withMessages([
                'relationship_type_id' => __('i18n.backend.support.engine.record_relation_resolver.this_relationship_type_does_not_belong_to_the_record'),
            ]);
        }

        return $type;
    }

    /**
     * @throws ValidationException
     */
    public function targetFor(
        CustomRecord $record,
        RelationshipType $type,
        RelationDirection $direction,
        string $targetRecordId,
    ): CustomRecord {
        $target = CustomRecord::query()
            ->where('tenant_id', $record->tenant_id)
            ->ofType($this->counterpartObjectTypeId($type, $direction))
            ->whereKey($targetRecordId)
            ->first();

        if (!$target instanceof CustomRecord) {
            throw ValidationException::withMessages([
                'target_record_id' => __('i18n.backend.support.engine.record_relation_resolver.the_selected_record_is_not_available_for_this_relationship'),
            ]);
        }

        return $target;
    }

    public function counterpartObjectTypeId(RelationshipType $type, RelationDirection $direction): string
    {
        return $direction->isOutgoing()
            ? $type->to_object_type_id
            : $type->from_object_type_id;
    }

    public function mayReparent(User $user, CustomRecord $record): bool
    {
        $record->loadMissing('objectType');

        return $user->hasPermission(
            "{$record->objectType->slug}.".ObjectTypeAbility::Reparent->value,
        );
    }

    /**
     * @throws AuthorizationException
     */
    public function assertMayReparent(User $user, CustomRecord $record): void
    {
        if (!$this->mayReparent($user, $record)) {
            throw new AuthorizationException(__('i18n.backend.support.engine.record_relation_resolver.you_may_not_change_this_record_s_position_in'));
        }
    }
}
