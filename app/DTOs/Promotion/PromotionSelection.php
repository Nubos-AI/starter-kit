<?php

declare(strict_types=1);

namespace App\DTOs\Promotion;

use App\Enums\ConfigBundle\ArtifactKind;

readonly class PromotionSelection
{
    /**
     * @param  list<array{kind: ArtifactKind, key: string}>  $pairs
     */
    public function __construct(
        public array $pairs,
    ) {}

    public function contains(ArtifactKind $kind, string $key): bool
    {
        foreach ($this->pairs as $pair) {
            if ($pair['kind'] === $kind && $pair['key'] === $key) {
                return true;
            }
        }

        return false;
    }

    public function withAdded(ArtifactKind $kind, string $key): self
    {
        if ($this->contains($kind, $key)) {
            return $this;
        }

        return new self([...$this->pairs, ['kind' => $kind, 'key' => $key]]);
    }

    public function count(): int
    {
        return count($this->pairs);
    }
}
