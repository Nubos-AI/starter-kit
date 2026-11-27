<?php

declare(strict_types=1);

namespace App\Actions\Engine;

use App\Support\Engine\IndexRegistry;

class DropObjectTypeIndexesAction
{
    public function __construct(private readonly IndexRegistry $indexRegistry) {}

    public function execute(string $objectTypeId): void
    {
        $this->indexRegistry->dropObjectTypeIndexes($objectTypeId);
    }
}
