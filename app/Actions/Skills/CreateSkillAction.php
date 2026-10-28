<?php

declare(strict_types=1);

namespace App\Actions\Skills;

use App\Models\Skill;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CreateSkillAction
{
    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function execute(User $actor, array $input): Skill
    {
        if ($actor->tenant_id === null) {
            throw ValidationException::withMessages([
                'name' => __('i18n.backend.actions.skills.create_skill_action.your_user_account_is_not_assigned_to_a_tenant'),
            ]);
        }

        $tenantId = TenantContext::currentId($actor->tenant_id);

        $validated = Validator::make($input, [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('skills', 'name')
                    ->where('tenant_id', $tenantId)
                    ->whereNull('deleted_at'),
            ],
        ])->validate();

        return Skill::query()->create([
            'tenant_id' => $tenantId,
            'name' => (string) $validated['name'],
        ]);
    }
}
