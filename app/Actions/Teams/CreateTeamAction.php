<?php

declare(strict_types=1);

namespace App\Actions\Teams;

use App\Exceptions\Authorization\TeamHierarchyException;
use App\Models\Team;
use App\Models\User;
use App\Support\Teams\TeamInputValidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class CreateTeamAction
{
    private int $slugSuffixLimit = 50;

    public function __construct(
        private readonly TeamInputValidator $validator,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     * @throws Throwable
     */
    public function execute(User $actor, array $input): Team
    {
        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'regex:/^[a-z0-9-]+$/'],
            'owner_id' => ['nullable', 'string', 'ulid'],
            'parent_team_id' => ['nullable', 'string', 'ulid'],
        ])->validate();

        $tenantId = (string) $actor->tenant_id;

        $name = trim((string) ($input['name'] ?? ''));
        $submittedSlug = $this->validator->stringOrNull($input['slug'] ?? null);
        $parentTeamId = $this->validator->stringOrNull($input['parent_team_id'] ?? null);

        $parent = $parentTeamId === null
            ? null
            : $this->validator->parentOrFail($tenantId, $parentTeamId);

        $ownerId = $this->validator->ownerIdOrFail(
            $tenantId,
            $this->validator->stringOrNull($input['owner_id'] ?? null),
        );

        $slug = $this->resolveSlug($tenantId, $name, $submittedSlug);

        try {
            return DB::transaction(fn (): Team => Team::query()->create([
                'tenant_id' => $tenantId,
                'parent_team_id' => $parent === null ? null : (string) $parent->getKey(),
                'owner_id' => $ownerId,
                'name' => $name,
                'slug' => $slug,
            ]));
        } catch (TeamHierarchyException $exception) {
            $this->validator->refuseHierarchyFailure($exception, 'parent_team_id', [
                'parent_team_id' => $parentTeamId,
                'actor_id' => (string) $actor->getKey(),
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function resolveSlug(string $tenantId, string $name, ?string $submittedSlug): string
    {
        if ($submittedSlug !== null) {
            return $this->validator->freeSlugOrFail($tenantId, $submittedSlug);
        }

        $base = Str::slug($name);

        if ($base === '') {
            $base = 'team';
        }

        for ($suffix = 1; $suffix <= $this->slugSuffixLimit; $suffix++) {
            $candidate = $suffix === 1 ? $base : "{$base}-{$suffix}";

            if (!$this->validator->slugIsTaken($tenantId, $candidate)) {
                return $candidate;
            }
        }

        throw ValidationException::withMessages([
            'slug' => __('i18n.backend.actions.teams.create_team_action.no_available_code_could_be_derived_from_this_name'),
        ]);
    }
}
