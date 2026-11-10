<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

use App\Models\FieldDefinition;
use App\Models\ObjectType;
use App\Support\Engine\ObjectTypeRegistry;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Tests\Support\ModelStub;

class StaticObjectTypeRegistry extends ObjectTypeRegistry
{
    /**
     * @param  list<string>  $relationNames
     * @param  array<string, Collection<string, FieldDefinition>>  $fieldsByObjectType
     * @param  list<string>  $objectTypeSlugs
     */
    public function __construct(
        private array $relationNames = [],
        private array $fieldsByObjectType = [],
        private array $objectTypeSlugs = [],
    ) {}

    public function isRelationName(string $name): bool
    {
        return in_array($name, $this->relationNames, true);
    }

    /**
     * @return Collection<string, FieldDefinition>
     */
    public function fields(string $objectTypeId): Collection
    {
        return $this->fieldsByObjectType[$objectTypeId] ?? new Collection;
    }

    public function bySlug(string $slug): ObjectType
    {
        if (!in_array($slug, $this->objectTypeSlugs, true)) {
            throw (new ModelNotFoundException)->setModel(ObjectType::class, [$slug]);
        }

        /** @var ObjectType $objectType */
        $objectType = ModelStub::make(ObjectType::class, [
            'id' => ModelStub::ulid("object-type-{$slug}"),
            'slug' => $slug,
        ]);

        return $objectType;
    }
}
