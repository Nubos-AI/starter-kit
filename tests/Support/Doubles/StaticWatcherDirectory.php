<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

use App\Models\CustomRecord;
use App\Models\User;
use App\Support\Watchers\WatcherDirectory;
use Illuminate\Support\Collection;

class StaticWatcherDirectory extends WatcherDirectory
{
    /**
     * @var list<string>
     */
    public array $askedForCandidates = [];

    /**
     * @param  array<string, User>  $candidates
     * @param  list<string>  $watcherUserIds
     * @param  array<string, User>  $users
     */
    public function __construct(
        private readonly array $candidates = [],
        private readonly array $watcherUserIds = [],
        private readonly array $users = [],
    ) {}

    /**
     * @param  list<string>  $userIds
     * @return Collection<string, User>
     */
    public function candidatesFor(CustomRecord $record, array $userIds): Collection
    {
        $this->askedForCandidates = [...$this->askedForCandidates, ...$userIds];

        return (new Collection($this->candidates))->only($userIds);
    }

    /**
     * @return Collection<int, string>
     */
    public function watcherUserIdsFor(CustomRecord $record): Collection
    {
        return new Collection($this->watcherUserIds);
    }

    /**
     * @param  list<string>  $userIds
     * @return Collection<int, User>
     */
    public function usersByIds(array $userIds): Collection
    {
        return (new Collection($this->users))->only($userIds)->values();
    }
}
