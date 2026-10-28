<?php

declare(strict_types=1);

namespace App\Support\Authorization\RowAccess;

use App\Models\TeamRecordAccessRule;
use Illuminate\Database\Eloquent\Collection;

class TeamAccessRuleSource
{
    /**
     * @param  list<string>  $teamIds
     * @return Collection<int, TeamRecordAccessRule>
     */
    public function activeFor(array $teamIds): Collection
    {
        return TeamRecordAccessRule::query()
            ->whereIn('team_id', $teamIds)
            ->where('is_active', true)
            ->get();
    }
}
