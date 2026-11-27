<?php

declare(strict_types=1);

namespace App\Contracts\Modules;

use App\Models\ObjectType;

interface ObjectTypeModuleOwnersInterface
{
    /**
     * @return list<string>
     */
    public function modulesOf(ObjectType $objectType): array;
}
