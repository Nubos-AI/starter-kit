<?php

declare(strict_types=1);

namespace App\DTOs\Approvals;

use App\Enums\Approvals\ApprovalProcessStatus;
use Carbon\CarbonImmutable;
use Keepsuit\LaravelTemporal\Contracts\TemporalSerializable;

readonly class ApprovalProcessStateData implements TemporalSerializable
{
    public function __construct(
        public ApprovalProcessStatus $status,
        public ?string $currentStageId,
        public ?string $deadlineAt,
        public bool $isEscalationApplied,
        public ?string $decisionEventId,
    ) {}

    public function isPending(): bool
    {
        return $this->status === ApprovalProcessStatus::Pending;
    }

    public function awaitsDeadline(): bool
    {
        return $this->isPending() && $this->currentStageId !== null && $this->deadlineAt !== null && !$this->isEscalationApplied;
    }

    public function deadline(): ?CarbonImmutable
    {
        return $this->deadlineAt === null ? null : CarbonImmutable::parse($this->deadlineAt);
    }

    /**
     * @return array{status: string, current_stage_id: string|null, deadline_at: string|null, is_escalation_applied: bool, decision_event_id: string|null}
     */
    public function toTemporalPayload(): array
    {
        return [
            'status' => $this->status->value,
            'current_stage_id' => $this->currentStageId,
            'deadline_at' => $this->deadlineAt,
            'is_escalation_applied' => $this->isEscalationApplied,
            'decision_event_id' => $this->decisionEventId,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromTemporalPayload(array $payload): self
    {
        $stageId = $payload['current_stage_id'] ?? null;
        $deadlineAt = $payload['deadline_at'] ?? null;
        $eventId = $payload['decision_event_id'] ?? null;

        return new self(
            status: ApprovalProcessStatus::from((string) $payload['status']),
            currentStageId: is_string($stageId) ? $stageId : null,
            deadlineAt: is_string($deadlineAt) ? $deadlineAt : null,
            isEscalationApplied: (bool) ($payload['is_escalation_applied'] ?? false),
            decisionEventId: is_string($eventId) ? $eventId : null,
        );
    }
}
