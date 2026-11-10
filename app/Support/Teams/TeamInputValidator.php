<?php

declare(strict_types=1);

namespace App\Support\Teams;

use App\Exceptions\Authorization\TeamHierarchyException;
use App\Models\Team;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TeamInputValidator
{
    /**
     * @return list<string|Closure>
     */
    public function slugRules(): array
    {
        return [
            'string',
            'max:255',
            'regex:/^[a-z0-9-]+$/',
            static function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && Str::isUlid($value)) {
                    $fail(__('i18n.backend.support.teams.team_input_validator.the_identifier_must_not_look_like_a_team_key'));
                }
            },
        ];
    }

    /**
     * @throws ValidationException
     */
    public function parentOrFail(string $tenantId, string $parentTeamId): Team
    {
        $parent = Team::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->whereKey($parentTeamId)
            ->first();

        if (!$parent instanceof Team) {
            throw ValidationException::withMessages([
                'parent_team_id' => __('i18n.backend.support.teams.team_input_validator.the_selected_parent_team_is_not_available'),
            ]);
        }

        return $parent;
    }

    /**
     * @param  array<string, mixed>  $context
     *
     * @throws ValidationException
     */
    public function assertMayAdopt(User $actor, Team $parent, array $context): void
    {
        if (Gate::forUser($actor)->allows('adopt', $parent)) {
            return;
        }

        Log::info('A team write was refused because the parent team lies outside the reach of the actor.', [
            ...$context,
            'tenant_id' => $parent->tenant_id,
            'parent_team_id' => (string) $parent->getKey(),
            'actor_id' => (string) $actor->getKey(),
        ]);

        throw ValidationException::withMessages([
            'parent_team_id' => __('i18n.backend.actions.teams.reparent_team_action.the_parent_team_is_outside_your_reach'),
        ]);
    }

    /**
     * @throws ValidationException
     */
    public function ownerIdOrFail(string $tenantId, ?string $ownerId): ?string
    {
        if ($ownerId === null) {
            return null;
        }

        $exists = User::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($ownerId)
            ->exists();

        if (!$exists) {
            throw ValidationException::withMessages([
                'owner_id' => __('i18n.backend.support.teams.team_input_validator.the_selected_responsible_person_is_not_available'),
            ]);
        }

        return $ownerId;
    }

    /**
     * @throws ValidationException
     */
    public function freeSlugOrFail(string $tenantId, string $slug, ?string $exceptTeamId = null): string
    {
        if ($this->slugIsTaken($tenantId, $slug, $exceptTeamId)) {
            throw ValidationException::withMessages([
                'slug' => __('i18n.backend.support.teams.team_input_validator.this_identifier_is_already_used_by_a_team_in'),
            ]);
        }

        return $slug;
    }

    public function slugIsTaken(string $tenantId, string $slug, ?string $exceptTeamId = null): bool
    {
        $query = Team::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('slug', $slug);

        if ($exceptTeamId !== null) {
            $query->whereKeyNot($exceptTeamId);
        }

        return $query->exists();
    }

    /**
     * @param  array<string, mixed>  $context
     *
     * @throws ValidationException
     */
    public function refuseHierarchyFailure(TeamHierarchyException $exception, string $errorKey, array $context): never
    {
        Log::warning('Refused a team write because the team tree materializer rejected it.', [
            ...$context,
            'reason' => $exception->getMessage(),
        ]);

        throw ValidationException::withMessages([
            $errorKey => $exception->getMessage(),
        ]);
    }

    public function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
