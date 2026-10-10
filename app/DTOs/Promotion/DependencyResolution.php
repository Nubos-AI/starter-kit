<?php

declare(strict_types=1);

namespace App\DTOs\Promotion;

use App\Enums\ConfigBundle\ArtifactKind;

readonly class DependencyResolution
{
    /**
     * @param  list<array{kind: ArtifactKind, key: string, optional: bool}>  $pulledIn
     * @param  list<array{kind: ArtifactKind, key: string, reason: string}>  $refusals
     */
    public function __construct(
        public PromotionSelection $selection,
        public array $pulledIn,
        public array $refusals,
    ) {}
}
