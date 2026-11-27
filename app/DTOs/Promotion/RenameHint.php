<?php

declare(strict_types=1);

namespace App\DTOs\Promotion;

readonly class RenameHint
{
    public function __construct(
        public string $kind,
        public string $fromKey,
        public string $toKey,
        public bool $exact,
    ) {}
}
