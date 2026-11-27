<?php

declare(strict_types=1);

namespace App\DTOs\Approvals;

use App\Enums\Approvals\ApprovalEscalationType;
use Keepsuit\LaravelTemporal\Contracts\TemporalSerializable;

readonly class ApprovalEscalationResultData implements TemporalSerializable
{
    public function __construct(public ?ApprovalEscalationType $appliedType) {}

    public function isEscalated(): bool
    {
        return $this->appliedType instanceof ApprovalEscalationType;
    }

    /**
     * @return array{applied_type: string|null}
     */
    public function toTemporalPayload(): array
    {
        return ['applied_type' => $this->appliedType?->value];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromTemporalPayload(array $payload): self
    {
        $type = $payload['applied_type'] ?? null;

        return new self(is_string($type) ? ApprovalEscalationType::from($type) : null);
    }
}
