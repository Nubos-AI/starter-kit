<?php

declare(strict_types=1);

namespace App\Actions\Skills;

use App\Models\Skill;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class UpdateSkillAction
{
    public function __construct(private readonly SyncSkillUsersAction $syncSkillUsers) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     * @throws Throwable
     */
    public function execute(Skill $skill, array $input): Skill
    {
        $validated = Validator::make($input, [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('skills', 'name')
                    ->where('tenant_id', $skill->tenant_id)
                    ->whereNull('deleted_at')
                    ->ignore($skill->getKey()),
            ],
            'user_ids' => ['sometimes', 'array'],
            'user_ids.*' => ['string', 'ulid'],
        ])->validate();

        $holderIds = array_key_exists('user_ids', $validated)
            ? $this->syncSkillUsers->holderIdsFor($skill, $validated['user_ids'])
            : null;

        return DB::transaction(static function () use ($skill, $validated, $holderIds): Skill {
            $skill->fill(['name' => (string) $validated['name']]);
            $skill->save();

            if ($holderIds !== null) {
                $skill->users()->sync($holderIds);
            }

            return $skill;
        });
    }
}
