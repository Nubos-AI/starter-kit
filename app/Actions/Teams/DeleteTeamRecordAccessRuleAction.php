<?php

declare(strict_types=1);

namespace App\Actions\Teams;

use App\Models\ObjectType;
use App\Models\Team;
use App\Models\TeamRecordAccessRule;

class DeleteTeamRecordAccessRuleAction
{
    public function execute(Team $team, ObjectType $objectType): void
    {
        TeamRecordAccessRule::query()
            ->where('team_id', $team->getKey())
            ->where('object_type_id', $objectType->getKey())
            ->get()
            ->each(fn (TeamRecordAccessRule $rule): ?bool => $rule->delete());
    }
}
