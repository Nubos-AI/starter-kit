<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\Models\CustomRecord;
use App\Models\RecordWatcher;
use App\Models\User;
use App\Notifications\GroupedBulkNotification;
use App\Support\Tenancy\ActingUserContext;
use Illuminate\Support\Facades\Notification;

class BulkFinalizer
{
    public function __construct(private readonly ActingUserContext $context) {}

    /**
     * @param  list<string>  $affectedIds
     */
    public function finalize(
        string $tenantId,
        string $actingUserId,
        string $reportKey,
        array $affectedIds,
        int $threshold,
        string $objectTypeSlug,
        bool $notify,
    ): void {
        BulkBatchReport::markFinished($reportKey);

        if (!$notify) {
            return;
        }

        $this->context->run($tenantId, $actingUserId, function () use ($affectedIds, $threshold, $objectTypeSlug): void {
            $this->dispatchGroupedBulkNotification($affectedIds, $threshold, $objectTypeSlug);
        });
    }

    /**
     * @param  list<string>  $affectedIds
     */
    private function dispatchGroupedBulkNotification(array $affectedIds, int $threshold, string $objectTypeSlug): void
    {
        if (count($affectedIds) <= $threshold) {
            return;
        }

        $ownerIds = CustomRecord::query()->whereKey($affectedIds)->whereNotNull('owner_id')->pluck('owner_id');
        $watcherIds = RecordWatcher::query()->whereIn('record_id', $affectedIds)->pluck('user_id');

        $userIds = $ownerIds->merge($watcherIds)->filter()->unique()->values();

        if ($userIds->isEmpty()) {
            return;
        }

        $recipients = User::query()->whereKey($userIds->all())->get();

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, new GroupedBulkNotification(count($affectedIds), $objectTypeSlug));
    }
}
