<?php

declare(strict_types=1);

namespace App\Policies\Engine;

use App\Models\MergeRule;
use App\Models\ObjectType;
use App\Models\User;

class MergeRulePolicy
{
    public function viewAny(User $user, ObjectType $objectType): bool
    {
        return $user->hasPermission('object-types.view');
    }

    public function create(User $user, ObjectType $objectType): bool
    {
        return $user->hasPermission('object-types.update');
    }

    public function deleteAny(User $user, ObjectType $objectType): bool
    {
        return $user->hasPermission('object-types.update');
    }

    public function update(User $user, MergeRule $mergeRule): bool
    {
        return $user->hasPermission('object-types.update');
    }

    public function delete(User $user, MergeRule $mergeRule): bool
    {
        return $user->hasPermission('object-types.update');
    }
}
