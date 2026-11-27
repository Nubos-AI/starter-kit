<?php

declare(strict_types=1);

namespace App\Actions\Notifications;

use App\Models\CustomRecord;
use App\Models\NotificationRule;
use App\Models\NotificationRuleDispatch;
use App\Models\User;
use App\Notifications\RuleNotification;
use App\Support\Notifications\PendingChannelDelivery;
use App\Support\Notifications\RuleTriggerFieldGuard;
use Illuminate\Support\Carbon;
use Throwable;

class DeliverRuleNotificationAction
{
    public function __construct(
        private readonly RuleTriggerFieldGuard $triggerFieldGuard,
        private readonly PendingChannelDelivery $channelDelivery,
    ) {}

    /**
     * @throws Throwable
     */
    public function execute(NotificationRule $rule, CustomRecord $record, User $recipient, string $stage): bool
    {
        if (!$this->triggerFieldGuard->mayRead($recipient, $rule)) {
            return false;
        }

        $dispatch = $this->claimDispatch($rule, $record, $recipient, $stage);

        if ($dispatch instanceof NotificationRuleDispatch) {
            $this->deliver($dispatch, $rule, $record, $recipient);
        }

        return true;
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
}
