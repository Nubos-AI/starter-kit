<?php

declare(strict_types=1);

namespace App\Support\Watchers;

use App\Enums\Watchers\WatcherSource;
use App\Models\CustomRecord;
use App\Models\RecordWatcher;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

class WatcherAutoSubscriber
{
    public function subscribe(string $recordId, string $userId, WatcherSource $source, ?string $addedById = null): RecordWatcher
    {
        return RecordWatcher::query()->firstOrCreate(
            ['record_id' => $recordId, 'user_id' => $userId],
            ['source' => $source, 'added_by_id' => $addedById, 'tenant_id' => $this->tenantIdFor($recordId)],
        );
    }

    public function unsubscribe(string $recordId, string $userId): void
    {
        RecordWatcher::query()
            ->where('record_id', $recordId)
            ->where('user_id', $userId)
            ->delete();
    }

    private function tenantIdFor(string $recordId): string
    {
        return (string) CustomRecord::query()
            ->withoutGlobalScopes()
            ->whereKey($recordId)
            ->value('tenant_id');
    }

    public function onRecordCreated(CustomRecord $record): void
    {
        $actor = Auth::user();

        if (!$actor instanceof User) {
            return;
        }

        $this->subscribe($record->getKey(), (string) $actor->getKey(), WatcherSource::Auto);

        if ($record->owner_id !== null) {
            $this->subscribe($record->getKey(), $record->owner_id, WatcherSource::Auto);
        }
    }

    public function onOwnerAssigned(string $recordId, ?string $newOwnerId): void
    {
        if ($newOwnerId === null || !Auth::user() instanceof User) {
            return;
        }

        $this->subscribe($recordId, $newOwnerId, WatcherSource::Auto);
    }

    public function onActivityAdded(string $recordId, string $userId): void
    {
        $this->subscribe($recordId, $userId, WatcherSource::Auto);
    }
}
