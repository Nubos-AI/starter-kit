<?php

declare(strict_types=1);

namespace App\DTOs\ConfigBundle;

readonly class ArtifactRefusal
{
    public function __construct(
        public string $reason,
        public ?string $overwriteConsequence = null,
    ) {}

    public function isOverwritable(): bool
    {
        return $this->overwriteConsequence !== null;
    }
}
