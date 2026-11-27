<?php

declare(strict_types=1);

namespace App\Actions\Engine;

use App\Models\CustomRecord;
use App\Models\RecordWatcher;
use App\Models\User;
use App\Notifications\GroupedBulkNotification;
use Illuminate\Support\Facades\Notification;

class NotifyBulkRecipientsAction
{
    /**
     * @param  list<string>  $affectedIds
     */
    public function execute(array $affectedIds, string $objectTypeSlug): void
    {
        $ownerIds = CustomRecord::query()->whereKey($affectedIds)->whereNotNull('owner_id')->pluck('owner_id');
        $watcherIds = RecordWatcher::query()->whereIn('record_id', $affectedIds)->pluck('user_id');

        $userIds = $ownerIds->merge($watcherIds)->filter()->unique()->values();

        if ($userIds->isEmpty()) {
            return;
        }

        $recipients = User::query()
            ->active()
            ->whereKey($userIds->all())
            ->get();

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, new GroupedBulkNotification(count($affectedIds), $objectTypeSlug));
    }
}
