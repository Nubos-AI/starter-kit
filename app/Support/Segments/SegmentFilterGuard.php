<?php

declare(strict_types=1);

namespace App\Support\Segments;

use App\Models\ObjectType;
use App\Support\Authorization\FieldVisibilityResolver;
use App\Support\Engine\FilterTreeValidator;

class SegmentFilterGuard
{
    public function __construct(
        private readonly FilterTreeValidator $filterTreeValidator,
        private readonly SegmentFilterFieldSource $filterFields,
    ) {}

    /**
     * @param  array<string, mixed>  $tree
     */
    public function assertValid(ObjectType $objectType, array $tree): void
    {
        $this->filterTreeValidator->validate(
            $tree,
            $this->filterFields->forObjectType($objectType),
            FieldVisibilityResolver::forRequest(),
            $objectType,
        );
    }
}
