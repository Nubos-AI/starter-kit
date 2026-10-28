<?php

declare(strict_types=1);

namespace App\Actions\Engine;

use App\Models\ObjectType;

class DeleteObjectTypeAction
{
    public function execute(ObjectType $objectType): void
    {
        $objectType->delete();
    }
}
