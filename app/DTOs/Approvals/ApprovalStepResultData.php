<?php

declare(strict_types=1);

namespace App\DTOs\Approvals;

use App\Enums\Approvals\ApprovalStepOutcome;
use Keepsuit\LaravelTemporal\Contracts\TemporalSerializable;

readonly class ApprovalStepResultData implements TemporalSerializable
{
    public function __construct(
        public ApprovalStepOutcome $outcome,
        public ?string $eventId,
    ) {}

    public function isDecision(): bool
    {
        return in_array($this->outcome, [ApprovalStepOutcome::Approved, ApprovalStepOutcome::Rejected], true);
    }

    /**
     * @return array{outcome: string, event_id: string|null}
     */
    public function toTemporalPayload(): array
    {
        return [
            'outcome' => $this->outcome->value,
            'event_id' => $this->eventId,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromTemporalPayload(array $payload): self
    {
        $eventId = $payload['event_id'] ?? null;

        return new self(
            outcome: ApprovalStepOutcome::from((string) $payload['outcome']),
            eventId: is_string($eventId) ? $eventId : null,
        );
    }
}
