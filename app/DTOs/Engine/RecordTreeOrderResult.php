<?php

declare(strict_types=1);

namespace App\DTOs\Engine;

use App\Enums\Engine\RecordTreeOrderReason;

readonly class RecordTreeOrderResult
{
    public function __construct(
        public bool $applied,
        public ?RecordTreeOrderReason $reason = null,
    ) {}

    public static function ordered(): self
    {
        return new self(true);
    }

    public static function skipped(RecordTreeOrderReason $reason): self
    {
        return new self(false, $reason);
    }

    /**
     * @return array{applied: bool, reason: string|null}
     */
    public function payload(): array
    {
        return [
            'applied' => $this->applied,
            'reason' => $this->reason?->value,
        ];
    }
}
