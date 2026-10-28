<?php

declare(strict_types=1);

namespace App\Support\Notifications;

use App\Actions\Reminders\CreateReminderTaskAction;
use App\Enums\Notifications\RuleActionType;
use App\Models\CustomRecord;
use App\Models\NotificationRule;
use App\Models\NotificationRuleDispatch;
use App\Models\User;
use App\Notifications\RuleNotification;
use App\Support\Watchers\WatcherResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Throwable;

class RuleActionRunner
{
    public function __construct(
        private readonly WatcherResolver $watcherResolver,
        private readonly CreateReminderTaskAction $createReminderTaskAction,
    ) {}

    public function run(NotificationRule $rule, CustomRecord $record, string $stage): void
    {
        foreach ($this->watcherResolver->recipientsFor($record) as $recipient) {
            $this->dispatchTo($rule, $record, $recipient, $stage);
        }
    }

    public function dispatchTo(NotificationRule $rule, CustomRecord $record, User $user, string $stage): void
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

        if (!$dispatch->wasRecentlyCreated) {
            return;
        }

        $user->notify(new RuleNotification($rule, $record));

        $this->createFollowUpReminder($rule, $record);
    }

    private function createFollowUpReminder(NotificationRule $rule, CustomRecord $record): void
    {
        $reminder = $rule->action[RuleActionType::CreateReminder->value] ?? null;

        if (!is_array($reminder)) {
            return;
        }

        $creator = $rule->creator;

        if (!$creator instanceof User) {
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
        } catch (Throwable $exception) {
            report($exception);
        } finally {
            if ($previous instanceof User) {
                Auth::setUser($previous);
            } else {
                Auth::forgetGuards();
            }
        }
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
