<?php

declare(strict_types=1);

namespace Tests\Fixtures\Timeline;

use App\Contracts\Timeline\TimelineSourceInterface;
use App\Models\CustomRecord;
use App\Models\TimelineEntry;
use App\Models\User;
use Illuminate\Support\Collection;

class CountingTimelineSource implements TimelineSourceInterface
{
    public static int $calls = 0;

    /** @var list<int> */
    public static array $batchSizes = [];

    public static function reset(): void
    {
        self::$calls = 0;
        self::$batchSizes = [];
    }

    public function key(): string
    {
        return 'counting_source';
    }

    /**
     * @return class-string<TimelineEntry>
     */
    public function modelClass(): string
    {
        return TimelineEntry::class;
    }

    /**
     * @param  Collection<int, TimelineEntry>  $entries
     * @return Collection<int, TimelineEntry>
     */
    public function filterVisible(User $user, CustomRecord $record, Collection $entries): Collection
    {
        self::$calls++;
        self::$batchSizes[] = $entries->count();

        return $entries->values();
    }
}
