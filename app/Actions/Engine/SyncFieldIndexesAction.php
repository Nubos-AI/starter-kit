<?php

declare(strict_types=1);

namespace App\Actions\Engine;

use App\Models\FieldDefinition;
use App\Models\ObjectType;
use App\Support\Engine\IndexRegistry;

class SyncFieldIndexesAction
{
    public function __construct(private readonly IndexRegistry $indexRegistry) {}

    public function execute(FieldDefinition $field): bool
    {
        $objectType = ObjectType::withoutTenantScope()
            ->where('tenant_id', $field->tenant_id)
            ->whereKey($field->object_type_id)
            ->first();

        if (!$objectType instanceof ObjectType || !$objectType->isGeneric()) {
            return false;
        }

        $this->indexRegistry->syncSortAndFilterIndexes($field);

        return true;
    }
}
