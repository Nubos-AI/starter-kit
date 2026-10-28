<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

use App\Models\FieldDefinition;
use App\Support\Engine\ObjectTypeRegistry;
use Illuminate\Support\Collection;

class StaticObjectTypeRegistry extends ObjectTypeRegistry
{
    /**
     * @param  list<string>  $relationNames
     * @param  array<string, Collection<string, FieldDefinition>>  $fieldsByObjectType
     */
    public function __construct(
        private array $relationNames = [],
        private array $fieldsByObjectType = [],
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
}
