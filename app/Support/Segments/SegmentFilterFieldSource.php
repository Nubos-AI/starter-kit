<?php

declare(strict_types=1);

namespace App\Support\Segments;

use App\Models\FieldDefinition;
use App\Models\ObjectType;
use App\Support\Engine\SystemFilterFields;
use Illuminate\Database\Eloquent\Collection;

class SegmentFilterFieldSource
{
    public function __construct(private readonly SystemFilterFields $systemFields) {}

    /**
     * @return Collection<int, FieldDefinition>
     */
    public function forObjectType(ObjectType $objectType): Collection
    {
        return FieldDefinition::query()
            ->where('object_type_id', $objectType->getKey())
            ->where('is_filterable', true)
            ->get()
            ->concat($this->systemFields->agingFields((string) $objectType->getKey()));
    }
}
