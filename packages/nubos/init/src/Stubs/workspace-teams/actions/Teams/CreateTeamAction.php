<?php

declare(strict_types=1);

namespace App\Actions\Teams;

use App\Events\Teams\TeamCreated;
use App\Models\Team;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class CreateTeamAction
{
    /**
     * @throws Throwable
     */
    public function execute(User $owner, Workspace $workspace, array $data): Team
    {
        return DB::transaction(function () use ($owner, $workspace, $data): Team {
            $team = Team::query()->create([
                'workspace_id' => $workspace->id,
                'name' => $data['name'],
                'slug' => $data['slug'] ?? Str::slug($data['name']),
                'owner_id' => $owner->id,
            ]);

            $team->users()->attach($owner->id, ['role' => 'owner']);

            $owner->update(['current_team_id' => $team->id]);

            event(new TeamCreated($team));

            return $team;
        });
    }
}
