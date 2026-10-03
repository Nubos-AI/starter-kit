<?php

declare(strict_types=1);

namespace App\Actions\Skills;

use App\Models\Skill;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UpdateSkillAction
{
    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
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
        ])->validate();

        $skill->fill(['name' => (string) $validated['name']]);
        $skill->save();

        return $skill;
    }
}
