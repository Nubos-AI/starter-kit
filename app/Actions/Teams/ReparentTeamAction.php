<?php

declare(strict_types=1);

namespace App\Actions\Teams;

use App\Exceptions\Authorization\TeamHierarchyException;
use App\Models\Team;
use App\Models\User;
use App\Support\Authorization\TeamTreeMaterializer;
use App\Support\Teams\TeamInputValidator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ReparentTeamAction
{
    public function __construct(
        private readonly TeamTreeMaterializer $materializer,
        private readonly TeamInputValidator $validator,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function execute(User $actor, Team $team, ?string $parentTeamId): void
    {
        Validator::make(['parent_team_id' => $parentTeamId], [
            'parent_team_id' => ['nullable', 'string', 'ulid'],
        ])->validate();

        Gate::forUser($actor)->authorize('reparent', $team);

        $parent = $parentTeamId === null
            ? null
            : $this->validator->parentOrFail(
                $team->tenant_id,
                $parentTeamId,
            );

        try {
            $this->materializer->attach($team, $parent);
        } catch (TeamHierarchyException $exception) {
            $this->validator->refuseHierarchyFailure($exception, 'parent_team_id', [
                'team_id' => (string) $team->getKey(),
                'parent_team_id' => $parentTeamId,
                'actor_id' => (string) $actor->getKey(),
            ]);
        }
    }
}
