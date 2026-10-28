<?php

declare(strict_types=1);

namespace App\DTOs\Engine;

readonly class RecordTreeNode
{
    public function __construct(
        public string $recordId,
        public string $objectTypeId,
        public int $depth,
        public bool $isCycleDetected = false,
    ) {}
}
