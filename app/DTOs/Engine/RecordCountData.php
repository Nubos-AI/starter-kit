<?php

declare(strict_types=1);

namespace App\DTOs\Engine;

readonly class RecordCountData
{
    public function __construct(
        public int $count,
        public bool $isEstimated,
    ) {}
}
