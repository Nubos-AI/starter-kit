<?php

declare(strict_types=1);

namespace App\Support\Sharing;

use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use Closure;

class ShareGranteeMatcher
{
    /**
     * @param  (Closure(string): bool)|null  $holdsTeam
     */
    public function matches(string $granteeType, string $granteeId, User $user, ?Closure $holdsTeam = null): bool
    {
        if ($granteeType === $user->getMorphClass()) {
            return $granteeId === $user->getKey();
        }

        if ($granteeType === (new Team)->getMorphClass()) {
            return $holdsTeam === null
                ? $user->teams->contains(fn (Team $team): bool => $team->getKey() === $granteeId)
                : $holdsTeam($granteeId);
        }

        if ($granteeType === (new Role)->getMorphClass()) {
            return $user->rolesFor()->contains(
                fn (Role $role): bool => $role->getKey() === $granteeId,
            );
        }

        return false;
    }
}
