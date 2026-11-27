<?php

declare(strict_types=1);

namespace App\DTOs\Engine;

use App\Enums\Engine\MergeValueOrigin;

readonly class MergeRequestData
{
    /**
     * @param  array<string, string>  $overrides
     */
    public function __construct(
        public string $targetId,
        public string $sourceId,
        public ?int $targetVersion = null,
        public ?int $sourceVersion = null,
        public ?string $reason = null,
        public array $overrides = [],
    ) {}

    public function overrideFor(string $fieldKey): ?MergeValueOrigin
    {
        $chosen = $this->overrides[$fieldKey] ?? null;

        if (!is_string($chosen)) {
            return null;
        }

        $origin = MergeValueOrigin::tryFrom($chosen);

        return in_array($origin, [MergeValueOrigin::Target, MergeValueOrigin::Source], true)
            ? $origin
            : null;
    }

    public function hasReason(): bool
    {
        return is_string($this->reason) && trim($this->reason) !== '';
    }
}
