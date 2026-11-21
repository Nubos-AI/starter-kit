<?php

declare(strict_types=1);

namespace App\DTOs\Engine;

use App\Enums\Engine\MergeBlockReason;

readonly class MergeBlocker
{
    public function __construct(
        public MergeBlockReason $reason,
        public ?string $detail = null,
    ) {}
}
