<?php

declare(strict_types=1);

namespace App\Support\Notifications;

use App\Actions\Reminders\CreateReminderTaskAction;
use App\Enums\Notifications\RuleActionType;
use App\Exceptions\Notifications\RuleDispatchFailedException;
use App\Models\CustomRecord;
use App\Models\NotificationRule;
use App\Models\NotificationRuleDispatch;
use App\Models\User;
use App\Notifications\RuleNotification;
use App\Support\Watchers\WatcherResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class RuleActionRunner
{
    public function __construct(
        private readonly WatcherResolver $watcherResolver,
        private readonly CreateReminderTaskAction $createReminderTaskAction,
        private readonly PendingChannelDelivery $channelDelivery = new PendingChannelDelivery,
    ) {}

    /**
     * @throws RuleDispatchFailedException
     */
    public function run(NotificationRule $rule, CustomRecord $record, string $stage): void
    {
        $this->dispatchToEach($rule, $record, $this->watcherResolver->recipientsFor($record), $stage);
    }

    /**
     * @param  iterable<int, User>  $recipients
     *
     * @throws RuleDispatchFailedException
     */
    public function dispatchToEach(NotificationRule $rule, CustomRecord $record, iterable $recipients, string $stage): void
    {
        $failedRecipients = 0;
        $lastFailure = null;

        foreach ($recipients as $recipient) {
            try {
                $this->dispatchTo($rule, $record, $recipient, $stage);
            } catch (Throwable $exception) {
                $failedRecipients++;
                $lastFailure = $exception;

                Log::error('Notification rule could not reach a recipient; the run continues and is retried for this recipient.', [
                    'rule_id' => $rule->getKey(),
                    'record_id' => $record->getKey(),
                    'tenant_id' => $record->tenant_id,
                    'user_id' => $recipient->getKey(),
                    'stage' => $stage,
                    'exception' => $exception,
                ]);
            }
        }

        if ($lastFailure instanceof Throwable) {
            throw RuleDispatchFailedException::for((string) $rule->getKey(), (string) $record->getKey(), $failedRecipients, $lastFailure);
        }
    }

    /**
     * @throws Throwable
     */
    public function dispatchTo(NotificationRule $rule, CustomRecord $record, User $user, string $stage): void
    {
        $dispatch = $this->claimDispatch($rule, $record, $user, $stage);

        if ($dispatch instanceof NotificationRuleDispatch) {
            $this->deliver($dispatch, $rule, $record, $user);
        }

        $this->createFollowUpReminder($rule, $record, $stage);
    }

    private function claimDispatch(NotificationRule $rule, CustomRecord $record, User $user, string $stage): ?NotificationRuleDispatch
    {
        $dispatch = NotificationRuleDispatch::query()->firstOrCreate(
            [
                'rule_id' => $rule->getKey(),
                'record_id' => $record->getKey(),
                'user_id' => $user->getKey(),
                'stage' => $stage,
            ],
            ['dispatched_at' => Carbon::now()],
        );

        if ($dispatch->wasRecentlyCreated) {
            return $dispatch;
        }

        if ($dispatch->dispatched_at !== null) {
            return null;
        }

        $reclaimed = NotificationRuleDispatch::query()
            ->whereKey($dispatch->getKey())
            ->whereNull('dispatched_at')
            ->update(['dispatched_at' => Carbon::now()]);

        return $reclaimed > 0 ? $dispatch : null;
    }

    /**
     * @throws Throwable
     */
    private function deliver(NotificationRuleDispatch $dispatch, NotificationRule $rule, CustomRecord $record, User $user): void
    {
        try {
            $this->channelDelivery->deliver(
                $user,
                new RuleNotification($rule, $record),
                $dispatch->notified_channels,
                NotificationRuleDispatch::query()->whereKey($dispatch->getKey()),
            );
        } catch (Throwable $exception) {
            NotificationRuleDispatch::query()
                ->whereKey($dispatch->getKey())
                ->update(['dispatched_at' => null]);

            throw $exception;
        }
    }

    private function createFollowUpReminder(NotificationRule $rule, CustomRecord $record, string $stage): void
    {
        $reminder = $rule->action[RuleActionType::CreateReminder->value] ?? null;

        if (!is_array($reminder)) {
            return;
        }

        $creator = $rule->creator;

        if (!$creator instanceof User || !$creator->status->canAuthenticate()) {
            return;
        }

        $claim = $this->claimReminder($rule, $record, $creator, $stage);

        if (!$claim->wasRecentlyCreated) {
            return;
        }

        $previous = Auth::user();

        try {
            Auth::setUser($creator);

            $this->createReminderTaskAction->execute([
                'subject' => is_string($reminder['subject'] ?? null) ? $reminder['subject'] : $rule->name,
                'record_id' => (string) $record->getKey(),
                'due_at' => $this->reminderDueAt($reminder),
            ]);
        } catch (ValidationException $exception) {
            Log::warning('Notification rule follow-up reminder was refused for its creator; it is not retried.', [
                'rule_id' => $rule->getKey(),
                'record_id' => $record->getKey(),
                'tenant_id' => $record->tenant_id,
                'creator_id' => $creator->getKey(),
                'stage' => $stage,
                'errors' => array_keys($exception->errors()),
            ]);
        } catch (Throwable $exception) {
            NotificationRuleDispatch::query()
                ->whereKey($claim->getKey())
                ->delete();

            throw $exception;
        } finally {
            if ($previous instanceof User) {
                Auth::setUser($previous);
            } else {
                Auth::forgetGuards();
            }
        }
    }

    private function claimReminder(NotificationRule $rule, CustomRecord $record, User $creator, string $stage): NotificationRuleDispatch
    {
        return NotificationRuleDispatch::query()->firstOrCreate(
            [
                'rule_id' => $rule->getKey(),
                'record_id' => $record->getKey(),
                'user_id' => $creator->getKey(),
                'stage' => "{$stage}:reminder",
            ],
            ['dispatched_at' => Carbon::now()],
        );
    }

    /**
     * @param  array<string, mixed>  $reminder
     */
    private function reminderDueAt(array $reminder): ?string
    {
        $offset = $reminder['due_offset'] ?? null;

        if (!is_int($offset)) {
            return null;
        }

        return Carbon::now()->addDays($offset)->toDateTimeString();
    }
}
