<?php

declare(strict_types=1);

namespace App\Contracts\Modules;

use App\Models\ObjectType;

interface RecordRelocatedFieldsInterface
{
    /** @return list<string> */
    public function relocatedFieldKeys(ObjectType $objectType): array;
}
