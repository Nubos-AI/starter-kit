<?php

declare(strict_types=1);

namespace App\Support\Watchers;

use App\Models\CustomRecord;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

class WatcherResolver
{
    public function __construct(private readonly WatcherDirectory $directory) {}

    /**
     * @return Collection<int, string>
     */
    public function watchersFor(CustomRecord $record): Collection
    {
        return $this->directory->watcherUserIdsFor($record);
    }

    /**
     * @return Collection<int, User>
     */
    public function recipientsFor(CustomRecord $record): Collection
    {
        $userIds = $this->watchersFor($record);

        if ($record->owner_id !== null) {
            $userIds->push($record->owner_id);
        }

        $unique = $userIds->unique()->values();

        if ($unique->isEmpty()) {
            return new Collection;
        }

        $record->loadMissing('objectType');

        return $this->directory->usersByIds(array_values($unique->all()))
            ->filter(fn (User $user): bool => Gate::forUser($user)->allows('view', $record))
            ->values();
    }
}
