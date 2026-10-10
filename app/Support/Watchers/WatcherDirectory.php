<?php

declare(strict_types=1);

namespace App\Support\Watchers;

use App\Models\CustomRecord;
use App\Models\RecordWatcher;
use App\Models\User;
use Illuminate\Support\Collection;

class WatcherDirectory
{
    /**
     * @param  list<string>  $userIds
     * @return Collection<string, User>
     */
    public function candidatesFor(CustomRecord $record, array $userIds): Collection
    {
        return User::query()
            ->where('tenant_id', $record->tenant_id)
            ->where('is_service', false)
            ->whereKey($userIds)
            ->get()
            ->keyBy(static fn (User $user): string => (string) $user->getKey())
            ->toBase();
    }

    /**
     * @return Collection<int, string>
     */
    public function watcherUserIdsFor(CustomRecord $record): Collection
    {
        return RecordWatcher::query()
            ->where('record_id', $record->getKey())
            ->pluck('user_id');
    }

    /**
     * @param  list<string>  $userIds
     * @return Collection<int, User>
     */
    public function usersByIds(array $userIds): Collection
    {
        return User::query()
            ->whereKey($userIds)
            ->get()
            ->toBase();
    }
}
