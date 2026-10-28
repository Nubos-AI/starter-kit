<?php

declare(strict_types=1);

namespace App\Traits\Engine;

use App\Actions\Engine\ReparentRecordAction;
use App\DTOs\Engine\RecordRelationDescriptor;
use App\DTOs\Engine\RecordTreeResult;
use App\Exceptions\Engine\AmbiguousRecordTypeException;
use App\Models\CustomRecord;
use App\Models\RecordLink;
use App\Support\Engine\ObjectTypeRegistry;
use App\Support\Engine\RecordEndpointResolver;
use App\Support\Engine\RecordTreeQuery;
use App\Support\Engine\Relations\RecordRelation;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Throwable;

trait HasRecordRelations
{
    /**
     * @param  class-string<Model>  $class
     * @param  string  $key
     */
    public function relationResolver($class, $key): ?Closure
    {
        return parent::relationResolver($class, $key)
            ?? $this->dynamicRelationResolver($key);
    }

    public function newRecordRelation(RecordRelationDescriptor $descriptor): RecordRelation
    {
        $related = $this->newRelatedInstance(
            app(RecordEndpointResolver::class)->modelClassFor($descriptor->counterpartObjectTypeId),
        );

        $relation = new RecordRelation(
            $related->newQuery(),
            $this,
            'record_links',
            $descriptor->direction->ownColumn(),
            $descriptor->direction->counterpartColumn(),
            $this->getKeyName(),
            $related->getKeyName(),
            $descriptor->name,
        );

        return $relation
            ->forDescriptor($descriptor)
            ->withPivot(['id', 'relationship_type_id', 'cardinality', 'position'])
            ->wherePivot('relationship_type_id', $descriptor->relationshipTypeId)
            ->orderByPivot('position')
            ->orderByPivot('created_at');
    }

    /**
     * @return HasOneThrough<CustomRecord, RecordLink, $this>
     */
    public function parent(): HasOneThrough
    {
        $relation = $this->hasOneThrough(
            CustomRecord::class,
            RecordLink::class,
            'to_record_id',
            'id',
            'id',
            'from_record_id',
        );

        $relation->whereIn('record_links.relationship_type_id', $this->hierarchyCarrierFilter('parent'));

        return $relation;
    }

    /**
     * @return HasManyThrough<CustomRecord, RecordLink, $this>
     */
    public function children(): HasManyThrough
    {
        $relation = $this->hasManyThrough(
            CustomRecord::class,
            RecordLink::class,
            'from_record_id',
            'id',
            'id',
            'to_record_id',
        );

        $relation
            ->whereIn('record_links.relationship_type_id', $this->hierarchyCarrierFilter('children'))
            ->orderBy('record_links.position')
            ->orderBy('record_links.created_at');

        return $relation;
    }

    public function ancestors(): RecordTreeResult
    {
        return $this->traverseHierarchy(
            fn (RecordTreeQuery $tree, string $carrierId): RecordTreeResult => $tree->ancestorsOf(
                $this->tenant_id,
                $carrierId,
                (string) $this->getKey(),
            ),
        );
    }

    public function descendants(): RecordTreeResult
    {
        return $this->traverseHierarchy(
            fn (RecordTreeQuery $tree, string $carrierId): RecordTreeResult => $tree->descendantsOf(
                $this->tenant_id,
                $carrierId,
                (string) $this->getKey(),
            ),
        );
    }

    /**
     * @throws Throwable
     */
    public function reparentTo(?CustomRecord $parent): void
    {
        app(ReparentRecordAction::class)->execute($this, [
            'parent_record_id' => $parent instanceof CustomRecord ? (string) $parent->getKey() : null,
        ]);
    }

    /**
     * @param  Closure(RecordTreeQuery, string): RecordTreeResult  $traverse
     */
    private function traverseHierarchy(Closure $traverse): RecordTreeResult
    {
        $carrierId = $this->hierarchyCarrierId();

        return $carrierId === null
            ? new RecordTreeResult([], false, false)
            : $traverse(app(RecordTreeQuery::class), $carrierId);
    }

    /**
     * @return list<string>
     */
    private function hierarchyCarrierFilter(string $relationName): array
    {
        if ($this->relationObjectTypeId() === null) {
            throw new AmbiguousRecordTypeException($relationName);
        }

        $carrierId = $this->hierarchyCarrierId();

        return $carrierId === null ? [] : [$carrierId];
    }

    private function hierarchyCarrierId(): ?string
    {
        $objectTypeId = $this->relationObjectTypeId();

        return $objectTypeId === null
            ? null
            : app(ObjectTypeRegistry::class)->byId($objectTypeId)->hierarchy_relationship_type_id;
    }

    private function dynamicRelationResolver(string $key): ?Closure
    {
        $registry = app(ObjectTypeRegistry::class);
        $objectTypeId = $this->relationObjectTypeId();

        if ($objectTypeId === null) {
            if ($registry->isRelationName($key)) {
                throw new AmbiguousRecordTypeException($key);
            }

            return null;
        }

        $descriptor = $registry->relationDescriptors($objectTypeId)[$key] ?? null;

        if (!$descriptor instanceof RecordRelationDescriptor) {
            return null;
        }

        return static fn (CustomRecord $record): RecordRelation => $record->newRecordRelation($descriptor);
    }
}
