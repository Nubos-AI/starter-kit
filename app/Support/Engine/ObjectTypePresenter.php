<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\Models\ObjectType;

class ObjectTypePresenter
{
    /**
     * @return array<string, mixed>
     */
    public function summary(ObjectType $objectType): array
    {
        return [
            'id' => $objectType->id,
            'key' => $objectType->key,
            'slug' => $objectType->slug,
            'name' => $objectType->name,
            'requiresDeletionReason' => $objectType->requires_deletion_reason,
            'hasHierarchy' => $objectType->hasHierarchy(),
        ];
    }
}
