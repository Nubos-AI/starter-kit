<?php

declare(strict_types=1);

namespace App\Actions\Engine;

use App\Enums\Engine\CascadeBehavior;
use App\Enums\Engine\RelationCardinality;
use App\Models\ObjectType;
use App\Models\RecordLink;
use App\Models\RelationshipType;
use App\Support\Engine\RelationshipKeyGenerator;
use App\Support\Engine\RollupOwnerStarter;
use Illuminate\Validation\ValidationException;

class SyncHierarchyCarrierAction
{
    public function __construct(
        private readonly RelationshipKeyGenerator $keyGenerator,
        private readonly DeleteRelationshipTypeAction $deleteRelationshipType,
        private readonly RollupOwnerStarter $rollupStarter,
    ) {}

    public function enable(ObjectType $objectType, ?RelationshipType $candidate = null): RelationshipType
    {
        $existing = $this->carrierOf($objectType);

        if ($existing instanceof RelationshipType) {
            return $existing;
        }

        if ($candidate instanceof RelationshipType && $this->isAdoptable($objectType, $candidate)) {
            $candidate->update(['is_hierarchy' => true]);

            return $candidate;
        }

        $key = $this->keyGenerator->generate($objectType->slug.' parent');

        return RelationshipType::query()->create([
            'key' => $key,
            'inverse_key' => $this->keyGenerator->generate($objectType->slug.' child', [$key]),
            'name' => $objectType->name.' parent',
            'inverse_name' => $objectType->name.' child',
            'from_object_type_id' => $objectType->getKey(),
            'to_object_type_id' => $objectType->getKey(),
            'cardinality' => RelationCardinality::OneToMany,
            'cascade_behavior' => CascadeBehavior::Nullify,
            'is_required' => false,
            'is_hierarchy' => true,
        ]);
    }

    /**
     * @throws ValidationException
     */
    public function disable(string $carrierId): void
    {
        $carrier = RelationshipType::query()->whereKey($carrierId)->first();

        if (!$carrier instanceof RelationshipType) {
            return;
        }

        $parents = RecordLink::query()
            ->withoutGlobalScopes()
            ->select(['tenant_id', 'from_record_id'])
            ->distinct()
            ->where('relationship_type_id', $carrierId)
            ->get()
            ->groupBy('tenant_id');

        RecordLink::query()
            ->withoutGlobalScopes()
            ->where('relationship_type_id', $carrierId)
            ->delete();

        $this->deleteRelationshipType->execute($carrier);

        foreach ($parents as $tenantId => $links) {
            $this->rollupStarter->startForRecordIds(
                (string) $tenantId,
                $links->map(static fn (RecordLink $link): string => (string) $link->getAttribute('from_record_id'))->all(),
            );
        }
    }

    private function isAdoptable(ObjectType $objectType, RelationshipType $candidate): bool
    {
        $objectTypeId = (string) $objectType->getKey();

        return $candidate->from_object_type_id === $objectTypeId
            && $candidate->to_object_type_id === $objectTypeId
            && $candidate->cardinality === RelationCardinality::OneToMany;
    }

    private function carrierOf(ObjectType $objectType): ?RelationshipType
    {
        return RelationshipType::query()
            ->where('is_hierarchy', true)
            ->where('from_object_type_id', $objectType->getKey())
            ->where('to_object_type_id', $objectType->getKey())
            ->orderBy('created_at')
            ->orderBy('id')
            ->first();
    }
}
