<?php

declare(strict_types=1);

namespace App\DTOs\Promotion;

use App\DTOs\ConfigBundle\ConfigBundle;
use App\Enums\ConfigBundle\ArtifactKind;

readonly class PromotionComparison
{
    public function __construct(
        public ConfigBundle $source,
        public ConfigBundle $target,
        public BundleDiff $diff,
    ) {}

    public function entry(ArtifactKind $kind, string $key): ?ArtifactDiff
    {
        foreach ($this->diff->diffs as $entry) {
            if ($entry->kind === $kind && $entry->key === $key) {
                return $entry;
            }
        }

        return null;
    }
}
