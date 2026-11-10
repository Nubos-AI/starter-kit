<?php

declare(strict_types=1);

namespace App\Actions\Teams;

use App\Exceptions\Authorization\TeamHierarchyException;
use App\Models\Team;
use App\Models\User;
use App\Support\Teams\TeamInputValidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class DeleteTeamAction
{
    public static string $blockedByChildrenReason = 'i18n.backend.actions.teams.delete_team_action.this_team_still_has_subteams_and_cannot_be_deleted';

    public function __construct(
        private readonly TeamInputValidator $validator,
    ) {}

    /**
     * @throws ValidationException
     * @throws Throwable
     */
    public function execute(User $actor, Team $team): void
    {
        if ($this->hasLivingChildren($team)) {
            throw ValidationException::withMessages([
                'team' => __(self::$blockedByChildrenReason),
            ]);
        }

        try {
            DB::transaction(function () use ($team): void {
                $team->delete();
            });
        } catch (TeamHierarchyException $exception) {
            $this->validator->refuseHierarchyFailure($exception, 'team', [
                'team_id' => (string) $team->getKey(),
                'actor_id' => (string) $actor->getKey(),
            ]);
        }
    }

    private function hasLivingChildren(Team $team): bool
    {
        return Team::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $team->tenant_id)
            ->where('parent_team_id', (string) $team->getKey())
            ->whereNull('deleted_at')
            ->exists();
    }
}
