<?php

declare(strict_types=1);

namespace App\Actions\Notifications;

use App\Actions\Reminders\CreateReminderTaskAction;
use App\Enums\Notifications\RuleActionType;
use App\Models\CustomRecord;
use App\Models\NotificationRule;
use App\Models\NotificationRuleDispatch;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class CreateRuleFollowUpReminderAction
{
    public function __construct(private readonly CreateReminderTaskAction $createReminderTask) {}

    /**
     * @throws Throwable
     */
    public function execute(NotificationRule $rule, CustomRecord $record, string $stage): void
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

            $this->createReminderTask->execute([
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

    public function isConfigured(NotificationRule $rule): bool
    {
        return is_array($rule->action[RuleActionType::CreateReminder->value] ?? null);
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
