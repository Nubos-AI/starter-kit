<?php

declare(strict_types=1);

namespace App\Support\Notifications;

use App\Actions\Notifications\CreateRuleFollowUpReminderAction;
use App\Actions\Notifications\DeliverRuleNotificationAction;
use App\Exceptions\Notifications\RuleDispatchFailedException;
use App\Models\CustomRecord;
use App\Models\NotificationRule;
use App\Support\Watchers\WatcherResolver;
use Illuminate\Support\Facades\Log;
use Throwable;

class RuleActionRunner
{
    public function __construct(
        private readonly WatcherResolver $watcherResolver,
        private readonly DeliverRuleNotificationAction $deliverRuleNotification,
        private readonly CreateRuleFollowUpReminderAction $createFollowUpReminder,
    ) {}

    /**
     * @throws RuleDispatchFailedException
     */
    public function run(NotificationRule $rule, CustomRecord $record, string $stage): void
    {
        $failedRecipients = 0;
        $lastFailure = null;

        foreach ($this->watcherResolver->recipientsFor($record) as $recipient) {
            try {
                if ($this->deliverRuleNotification->execute($rule, $record, $recipient, $stage)) {
                    $this->createFollowUpReminder->execute($rule, $record, $stage);
                }
            } catch (Throwable $exception) {
                $failedRecipients++;
                $lastFailure = $exception;

                Log::error('Notification rule could not reach a recipient; the run continues and is retried for this recipient.', [
                    'rule_id' => $rule->getKey(),
                    'record_id' => $record->getKey(),
                    'tenant_id' => $record->tenant_id,
                    'user_id' => $recipient->getKey(),
                    'stage' => $stage,
                    'exception' => $exception::class,
                ]);
            }
        }

        if ($lastFailure instanceof Throwable) {
            throw RuleDispatchFailedException::for((string) $rule->getKey(), (string) $record->getKey(), $failedRecipients, $lastFailure);
        }
    }
}
