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
     * @param  list<list<string>>  $failedChunks
     */
    public function finalize(
        string $tenantId,
        string $actingUserId,
        string $reportKey,
        array $affectedIds,
        int $threshold,
        string $objectTypeSlug,
        bool $notify,
        array $failedChunks = [],
    ): void {
        $this->reportFailedChunks($reportKey, $failedChunks);

        BulkBatchReport::markFinished($reportKey);

        if (!$notify) {
            return;
        }

        $this->context->run($tenantId, $actingUserId, function () use ($affectedIds, $threshold, $objectTypeSlug): void {
            $this->dispatchGroupedBulkNotification($affectedIds, $threshold, $objectTypeSlug);
        });
    }

    /**
     * @param  list<list<string>>  $failedChunks
     */
    private function reportFailedChunks(string $reportKey, array $failedChunks): void
    {
        if ($failedChunks === [] || (BulkBatchReport::progress($reportKey)['finished'] ?? false)) {
            return;
        }

        $message = __('i18n.backend.support.engine.bulk_chunk_runner.the_record_could_not_be_processed_please_try_again');

        BulkBatchReport::addErrors($reportKey, array_map(
            static fn (string $recordId): array => ['recordId' => $recordId, 'message' => $message],
            array_values(array_unique(array_merge(...$failedChunks))),
        ));

        BulkBatchReport::markChunksFailed($reportKey, count($failedChunks));
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
