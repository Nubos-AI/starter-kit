<?php

declare(strict_types=1);

namespace App\Support\Modules;

use App\Contracts\Modules\ObjectTypeModuleOwnersInterface;
use App\Models\ObjectType;

class NoObjectTypeModuleOwners implements ObjectTypeModuleOwnersInterface
{
    /**
     * @return list<string>
     */
    public function modulesOf(ObjectType $objectType): array
    {
        return [];
    }
}
