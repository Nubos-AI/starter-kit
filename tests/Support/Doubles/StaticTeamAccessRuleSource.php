<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

use App\Models\TeamRecordAccessRule;
use App\Support\Authorization\RowAccess\TeamAccessRuleSource;
use Illuminate\Database\Eloquent\Collection;

class StaticTeamAccessRuleSource extends TeamAccessRuleSource
{
    /**
     * @var list<list<string>>
     */
    public array $askedFor = [];

    /**
     * @param  Collection<int, TeamRecordAccessRule>  $rules
     */
    public function __construct(private Collection $rules) {}

    /**
     * @param  list<string>  $teamIds
     * @return Collection<int, TeamRecordAccessRule>
     */
    public function activeFor(array $teamIds): Collection
    {
        $this->askedFor[] = $teamIds;

        return $this->rules->filter(
            static fn (TeamRecordAccessRule $rule): bool => in_array($rule->team_id, $teamIds, true),
        )->values();
    }
}
