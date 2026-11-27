<?php

declare(strict_types=1);

namespace App\DTOs\Notifications;

use Keepsuit\LaravelTemporal\Contracts\TemporalSerializable;

readonly class DateRuleCase implements TemporalSerializable
{
    /**
     * @param  list<string>  $recipientIds
     */
    public function __construct(
        public string $tenantId,
        public string $ruleId,
        public string $recordId,
        public string $stage,
        public array $recipientIds,
        public bool $hasFollowUpReminder,
    ) {}

    public function stageKey(): string
    {
        return str_replace(':', '-', $this->stage);
    }

    /**
     * @return array{tenant_id: string, rule_id: string, record_id: string, stage: string, recipient_ids: list<string>, has_follow_up_reminder: bool}
     */
    public function toTemporalPayload(): array
    {
        return [
            'tenant_id' => $this->tenantId,
            'rule_id' => $this->ruleId,
            'record_id' => $this->recordId,
            'stage' => $this->stage,
            'recipient_ids' => $this->recipientIds,
            'has_follow_up_reminder' => $this->hasFollowUpReminder,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromTemporalPayload(array $payload): self
    {
        $recipientIds = is_array($payload['recipient_ids'] ?? null) ? $payload['recipient_ids'] : [];

        return new self(
            tenantId: (string) $payload['tenant_id'],
            ruleId: (string) $payload['rule_id'],
            recordId: (string) $payload['record_id'],
            stage: (string) $payload['stage'],
            recipientIds: array_values(array_map(strval(...), array_filter($recipientIds, is_string(...)))),
            hasFollowUpReminder: (bool) ($payload['has_follow_up_reminder'] ?? false),
        );
    }
}
