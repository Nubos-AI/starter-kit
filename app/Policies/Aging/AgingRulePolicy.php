<?php

declare(strict_types=1);

namespace App\Policies\Aging;

use App\Models\AgingRule;
use App\Models\ObjectType;
use App\Models\User;

class AgingRulePolicy
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

    public function update(User $user, AgingRule $agingRule): bool
    {
        return $user->hasPermission('object-types.update');
    }

    public function delete(User $user, AgingRule $agingRule): bool
    {
        return $user->hasPermission('object-types.update');
    }
}
