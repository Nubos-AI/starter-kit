<?php

declare(strict_types=1);

namespace App\DTOs\Promotion;

use App\Enums\Promotion\DiffState;

readonly class BundleDiff
{
    /**
     * @param  list<ArtifactDiff>  $diffs
     */
    public function __construct(
        public array $diffs,
    ) {}

    /**
     * @return list<ArtifactDiff>
     */
    public function conflicts(): array
    {
        return array_values(array_filter(
            $this->diffs,
            static fn (ArtifactDiff $diff): bool => $diff->state === DiffState::Conflicted,
        ));
    }

    /**
     * @param  list<ConflictDecision>  $decisions
     */
    public function hasUndecidedConflicts(PromotionSelection $selection, array $decisions): bool
    {
        foreach ($this->conflicts() as $diff) {
            if ($selection->contains($diff->kind, $diff->key) && !$diff->isDecidable($decisions)) {
                return true;
            }
        }

        return false;
    }
}
