<?php

declare(strict_types=1);

namespace App\DTOs\Promotion;

use App\Enums\ConfigBundle\ArtifactKind;

readonly class RenameHint
{
    public function __construct(
        public ArtifactKind $kind,
        public string $fromKey,
        public string $toKey,
        public bool $exact,
    ) {}
}
