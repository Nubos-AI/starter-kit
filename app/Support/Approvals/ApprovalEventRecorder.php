<?php

declare(strict_types=1);

namespace App\Support\Approvals;

use App\Enums\Approvals\ApprovalEscalationType;
use App\Enums\Approvals\ApprovalEventType;
use App\Enums\Audit\ActorType;
use App\Exceptions\Timeline\UnknownTimelineSourceException;
use App\Models\ApprovalEvent;
use App\Models\ApprovalProcess;
use App\Models\ApprovalProcessStage;
use App\Support\Timeline\TimelineRecorder;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use JsonException;
use Throwable;

class ApprovalEventRecorder
{
    public function __construct(
        private readonly TimelineRecorder $timeline,
    ) {}

    /**
     * @param  array{stage?: ApprovalProcessStage|null, actor_id?: string|null, on_behalf_of_id?: string|null, reason?: string|null, escalation_type?: ApprovalEscalationType|null, payload?: array<string, mixed>|null, occurred_at?: DateTimeInterface|null}  $attributes
     *
     * @throws UnknownTimelineSourceException
     * @throws JsonException
     * @throws Throwable
     */
    public function record(ApprovalProcess $process, ApprovalEventType $type, array $attributes = []): ApprovalEvent
    {
        $stage = $attributes['stage'] ?? null;
        $actorId = $attributes['actor_id'] ?? null;
        $escalationType = $attributes['escalation_type'] ?? null;
        $extraPayload = $attributes['payload'] ?? [];

        $storedPayload = $escalationType === null
            ? $extraPayload
            : [...$extraPayload, 'escalationType' => $escalationType->value];

        $row = [
            'tenant_id' => $process->tenant_id,
            'approval_process_id' => $process->getKey(),
            'approval_process_stage_id' => $stage?->getKey(),
            'actor_id' => $actorId,
            'on_behalf_of_id' => $attributes['on_behalf_of_id'] ?? null,
            'type' => $type,
            'reason' => $attributes['reason'] ?? null,
            'payload' => $storedPayload === [] ? null : $storedPayload,
            'occurred_at' => $attributes['occurred_at'] ?? now(),
        ];

        $payload = [
            'type' => $type->value,
            'stageId' => $stage?->getKey(),
            'stagePosition' => $stage?->position,
            'actorId' => $actorId,
            'onBehalfOfId' => $row['on_behalf_of_id'],
            'reason' => $row['reason'],
            'escalationType' => $escalationType?->value,
            ...$extraPayload,
        ];

        return DB::transaction(function () use ($process, $row, $payload, $actorId): ApprovalEvent {
            $event = ApprovalEvent::query()->create($row);

            $entry = [
                'source_id' => (string) $event->getKey(),
                'occurred_at' => $event->occurred_at,
                'payload' => $payload,
            ];

            if ($actorId !== null) {
                $entry['actor_id'] = $actorId;
                $entry['actor_type'] = ActorType::User->value;
            }

            if ($process->record_id !== null) {
                $this->timeline->record(
                    $process->record()->withTrashed()->firstOrFail(),
                    'approval',
                    [$entry],
                );
            }

            return $event;
        });
    }
}
