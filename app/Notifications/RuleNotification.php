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
        $recordNumber = $this->record->record_number;

        return [
            'title' => __('i18n.backend.notifications.rule_notification.title', ['value1' => $this->rule->name]),
            'body' => $recordNumber === null
                ? __('i18n.backend.notifications.rule_notification.a_record_matched')
                : __('i18n.backend.notifications.rule_notification.record_matched', ['value1' => $recordNumber]),
            'ruleName' => $this->rule->name,
            'recordId' => $this->record->getKey(),
            'url' => $this->linkTo('engine.records.show', ['record' => $this->record->getKey()], $notifiable),
        ];
    }
}
