<?php

declare(strict_types=1);

namespace App\Support\Authorization;

use App\Enums\Authorization\RoleAuthority;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

class RoleAuthorityGuard
{
    /**
     * @param  array<string, mixed>  $validated
     *
     * @throws AuthorizationException
     */
    public function assertMayChange(User $actingUser, array $validated, ?RoleAuthority $current): void
    {
        if (!array_key_exists('authority', $validated)) {
            return;
        }

        $requested = $validated['authority'] === null
            ? null
            : RoleAuthority::from((string) $validated['authority']);

        if ($requested === $current) {
            return;
        }

        if (!$actingUser->isEscalatedAuthority()) {
            throw new AuthorizationException(__('i18n.backend.support.authorization.role_authority_guard.you_may_not_change_a_role_s_authority_level'));
        }

        if ($requested === RoleAuthority::SuperAdmin && !$actingUser->hasRoleWithAuthority(RoleAuthority::SuperAdmin)) {
            throw new AuthorizationException(__('i18n.backend.support.authorization.role_authority_guard.you_may_not_grant_unrestricted_administration'));
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     *
     * @throws AuthorizationException
     */
    public function assertMayChangeSubteamVisibility(User $actingUser, array $validated, bool $current): void
    {
        if (!array_key_exists('grants_subteam_visibility', $validated)) {
            return;
        }

        if ((bool) $validated['grants_subteam_visibility'] === $current) {
            return;
        }

        if (!$actingUser->isEscalatedAuthority()) {
            throw new AuthorizationException(__('i18n.backend.support.authorization.role_authority_guard.you_may_not_change_visibility_into_subteams'));
        }
    }
}
