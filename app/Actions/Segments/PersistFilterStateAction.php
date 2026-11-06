<?php

declare(strict_types=1);

namespace App\Actions\Segments;

use App\Models\FilterState;
use App\Models\ObjectType;
use App\Models\User;
use App\Support\Tenancy\TenantContext;

class PersistFilterStateAction
{
    /**
     * @param  array<string, mixed>  $tree
     */
    public function execute(array $tree, ObjectType $objectType, User $creator): FilterState
    {
        return FilterState::query()->create([
            'tenant_id' => TenantContext::currentId(),
            'object_type_id' => $objectType->getKey(),
            'created_by' => $creator->getKey(),
            'tree' => $tree,
        ]);
    }
}
