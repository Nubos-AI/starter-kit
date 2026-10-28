<?php

declare(strict_types=1);

namespace App\DTOs\Promotion;

use App\Enums\ConfigBundle\ArtifactKind;
use App\Enums\Promotion\DiffState;

readonly class ArtifactDiff
{
    /**
     * @param  list<string>  $changedPaths
     */
    public function __construct(
        public ArtifactKind $kind,
        public string $key,
        public DiffState $state,
        public ?string $sourceHash,
        public ?string $targetHash,
        public ?string $baselineHash,
        public array $changedPaths,
    ) {}

    /**
     * @param  list<ConflictDecision>  $decisions
     */
    public function isDecidable(array $decisions): bool
    {
        if ($this->state !== DiffState::Conflicted) {
            return true;
        }

        foreach ($decisions as $decision) {
            if ($decision->kind === $this->kind && $decision->key === $this->key) {
                return true;
            }
        }

        return false;
    }
}
