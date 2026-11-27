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
        $delivered = $deliveredChannels;

        foreach (array_values(array_diff($notification->via($notifiable), $deliveredChannels ?? [])) as $channel) {
            $delivered = $this->deliverChannel($notifiable, $notification, $channel, $delivered, $trackedRow);
        }

        if ($delivered !== null) {
            $this->recordDeliveredChannels($trackedRow, null);
        }
    }

    /**
     * @template TModel of Model
     *
     * @param  list<string>|null  $deliveredChannels
     * @param  Builder<TModel>  $trackedRow
     * @return list<string>
     *
     * @throws JsonException
     */
    public function deliverChannel(User $notifiable, EngineNotification $notification, string $channel, ?array $deliveredChannels, Builder $trackedRow): array
    {
        $delivered = $deliveredChannels ?? [];

        if (in_array($channel, $delivered, true)) {
            return $delivered;
        }

        $notifiable->notifyNow($notification, [$channel]);
        $delivered[] = $channel;

        $this->recordDeliveredChannels($trackedRow, $delivered);

        return $delivered;
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
