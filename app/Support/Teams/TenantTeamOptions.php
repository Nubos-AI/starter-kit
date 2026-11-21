<?php

declare(strict_types=1);

namespace App\Support\Teams;

use App\Models\Team;
use App\Models\User;

class TenantTeamOptions
{
    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function forUser(User $actingUser): array
    {
        return Team::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $actingUser->tenant_id)
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get()
            ->map(fn (Team $team): array => [
                'value' => (string) $team->getKey(),
                'label' => $team->name,
            ])
            ->values()
            ->all();
    }
}
