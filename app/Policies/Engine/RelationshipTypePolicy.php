<?php

declare(strict_types=1);

namespace App\Policies\Engine;

use App\Models\RelationshipType;
use App\Models\User;

class RelationshipTypePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('object-types.view');
    }

    public function view(User $user, RelationshipType $relationshipType): bool
    {
        return $user->hasPermission('object-types.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('object-types.create');
    }

    public function update(User $user, RelationshipType $relationshipType): bool
    {
        return $user->hasPermission('object-types.update');
    }

    public function delete(User $user, RelationshipType $relationshipType): bool
    {
        return $user->hasPermission('object-types.delete');
    }
}
