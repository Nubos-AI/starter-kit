<?php

declare(strict_types=1);

namespace App\DTOs\Engine;

use App\Enums\Engine\MergeValueOrigin;

readonly class MergeValueOutcome
{
    public function __construct(
        public mixed $value,
        public MergeValueOrigin $origin,
        public bool $requiresDecision = false,
    ) {}
}
