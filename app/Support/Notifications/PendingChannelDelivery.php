<?php

declare(strict_types=1);

namespace App\Support\Notifications;

use App\Models\User;
use App\Notifications\Abstracts\EngineNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use JsonException;

class PendingChannelDelivery
{
    /**
     * @template TModel of Model
     *
     * @param  list<string>|null  $deliveredChannels
     * @param  Builder<TModel>  $trackedRow
     *
     * @throws JsonException
     */
    public function deliver(User $notifiable, EngineNotification $notification, ?array $deliveredChannels, Builder $trackedRow): void
    {
        $delivered = $deliveredChannels ?? [];
        $isTracked = $deliveredChannels !== null;
        $pending = array_values(array_diff($notification->via($notifiable), $delivered));
        $lastIndex = count($pending) - 1;

        foreach ($pending as $index => $channel) {
            $notifiable->notifyNow($notification, [$channel]);
            $delivered[] = $channel;

            if ($index < $lastIndex) {
                $this->recordDeliveredChannels($trackedRow, $delivered);
                $isTracked = true;
            }
        }

        if ($isTracked) {
            $this->recordDeliveredChannels($trackedRow, null);
        }
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $trackedRow
     * @param  list<string>|null  $channels
     *
     * @throws JsonException
     */
    private function recordDeliveredChannels(Builder $trackedRow, ?array $channels): void
    {
        $trackedRow
            ->clone()
            ->update(['notified_channels' => $channels === null ? null : json_encode($channels, JSON_THROW_ON_ERROR)]);
    }
}
