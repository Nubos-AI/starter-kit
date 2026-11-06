<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\CustomRecord;
use App\Models\NotificationRule;
use App\Notifications\Abstracts\EngineNotification;

class RuleNotification extends EngineNotification
{
    public function __construct(
        private readonly NotificationRule $rule,
        private readonly CustomRecord $record,
    ) {}

    public function type(): string
    {
        return 'rule.triggered';
    }

    /**
     * @return array<string, mixed>
     */
    public function toInbox(mixed $notifiable): array
    {
        return [
            'ruleName' => $this->rule->name,
            'recordId' => $this->record->getKey(),
            'url' => route('engine.records.show', ['record' => $this->record->getKey()]),
        ];
    }
}
