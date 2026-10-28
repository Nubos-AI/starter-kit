<?php

declare(strict_types=1);

namespace App\Actions\Teams;

use App\Models\Team;
use App\Models\User;
use App\Support\Teams\TeamInputValidator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

class UpdateTeamAction
{
    /**
     * @var list<string>
     */
    private array $writableAttributes = ['name', 'slug', 'owner_id'];

    public function __construct(
        private readonly TeamInputValidator $validator,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     * @throws Throwable
     */
    public function execute(User $actor, Team $team, array $input): Team
    {
        Validator::make($input, [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:255', 'regex:/^[a-z0-9-]+$/'],
            'owner_id' => ['sometimes', 'nullable', 'string', 'ulid'],
        ])->validate();

        $attributes = Arr::only($input, $this->writableAttributes);

        if (array_key_exists('name', $attributes)) {
            $attributes['name'] = trim((string) $attributes['name']);
        }

        if (array_key_exists('slug', $attributes)) {
            $slug = $this->validator->stringOrNull($attributes['slug']);

            if ($slug === null) {
                unset($attributes['slug']);
            } else {
                $attributes['slug'] = $this->validator->freeSlugOrFail(
                    $team->tenant_id,
                    $slug,
                    (string) $team->getKey(),
                );
            }
        }

        if (array_key_exists('owner_id', $attributes)) {
            $attributes['owner_id'] = $this->validator->ownerIdOrFail(
                (string) $actor->tenant_id,
                $this->validator->stringOrNull($attributes['owner_id']),
            );
        }

        if ($attributes === []) {
            return $team;
        }

        return DB::transaction(function () use ($team, $attributes): Team {
            $team->fill($attributes)->save();

            return $team;
        });
    }
}
