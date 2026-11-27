<?php

declare(strict_types=1);

namespace App\DTOs\Engine;

use App\Enums\Engine\RelationCardinality;
use App\Enums\Engine\RelationDirection;

readonly class RecordRelationDescriptor
{
    public function __construct(
        public string $name,
        public string $relationshipTypeId,
        public RelationDirection $direction,
        public RelationCardinality $cardinality,
        public bool $isHierarchy,
        public string $counterpartObjectTypeId,
    ) {}
}
