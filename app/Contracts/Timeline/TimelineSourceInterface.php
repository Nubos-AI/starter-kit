<?php

declare(strict_types=1);

namespace App\Contracts\Timeline;

use App\Models\CustomRecord;
use App\Models\TimelineEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

interface TimelineSourceInterface
{
    public function key(): string;

    /**
     * @return class-string<Model>
     */
    public function modelClass(): string;

    /**
     * @param  Collection<int, TimelineEntry>  $entries
     * @return Collection<int, TimelineEntry>
     */
    public function filterVisible(User $user, CustomRecord $record, Collection $entries): Collection;
}
