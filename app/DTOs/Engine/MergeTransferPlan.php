<?php

declare(strict_types=1);

namespace App\DTOs\Engine;

use App\Enums\Engine\MergeTransferPolicy;

readonly class MergeTransferPlan
{
    public function __construct(
        public string $category,
        public MergeTransferPolicy $policy,
        public int $count,
    ) {}
}
