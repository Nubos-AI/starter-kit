<?php

declare(strict_types=1);

namespace App\Support\Authorization;

use App\Models\ObjectType;
use App\Models\User;
use Illuminate\Support\Collection;

class EffectivePermissionMap
{
    public function __construct(
        private readonly PermissionCatalog $catalog,
        private readonly PermissionResolver $resolver,
    ) {}

    /**
     * @param  Collection<int, ObjectType>|null  $objectTypes
     * @return array<string, bool>
     */
    public function forUser(User $user, ?Collection $objectTypes = null): array
    {
        return $this->resolver->map($user, $this->catalog->all($objectTypes));
    }
}
