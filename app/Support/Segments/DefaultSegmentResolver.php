<?php

declare(strict_types=1);

namespace App\Support\Segments;

use App\Models\ObjectType;
use App\Models\Segment;

class DefaultSegmentResolver
{
    public function idFor(ObjectType $objectType): ?string
    {
        $id = Segment::query()
            ->where('object_type_id', $objectType->getKey())
            ->where('is_default', true)
            ->orderBy('id')
            ->value('id');

        return is_string($id) ? $id : null;
    }
}
