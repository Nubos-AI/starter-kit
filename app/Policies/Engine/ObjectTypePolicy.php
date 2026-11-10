<?php

declare(strict_types=1);

namespace App\Policies\Engine;

use App\Models\ObjectType;
use App\Models\User;

class ObjectTypePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('object-types.view');
    }

    public function view(User $user, ObjectType $objectType): bool
    {
        return $user->hasPermission('object-types.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('object-types.create');
    }

    public function update(User $user, ObjectType $objectType): bool
    {
        return !$objectType->is_system && $user->hasPermission('object-types.update');
    }

    public function delete(User $user, ObjectType $objectType): bool
    {
        return !$objectType->is_system && $user->hasPermission('object-types.delete');
    }
}
