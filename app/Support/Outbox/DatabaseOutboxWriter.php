<?php

declare(strict_types=1);

namespace App\Support\Outbox;

use App\Contracts\Outbox\OutboxWriter;
use App\DTOs\Outbox\OutboxSubject;
use App\Enums\Outbox\OutboxTopic;
use App\Exceptions\Outbox\ReservedOutboxTopicException;
use App\Models\OutboxEvent;
use App\Support\Engine\RecordChangeRelayStarter;

class DatabaseOutboxWriter implements OutboxWriter
{
    public function __construct(private readonly RecordChangeRelayStarter $relayStarter) {}

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws ReservedOutboxTopicException
     */
    public function record(string $topic, string $tenantId, OutboxSubject $subject, array $payload = []): void
    {
        if ($topic === OutboxTopic::RecordChanged->value) {
            throw new ReservedOutboxTopicException($topic);
        }

        OutboxEvent::query()->create([
            'tenant_id' => $tenantId,
            'topic' => $topic,
            'subject_type' => $subject->type,
            'subject_id' => $subject->id,
            'payload' => $payload,
            'created_at' => now(),
        ]);

        $this->relayStarter->startAfterCommit();
    }
}
