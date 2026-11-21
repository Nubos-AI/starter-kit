<?php

declare(strict_types=1);

namespace App\Actions\Skills;

use App\Models\Skill;
use App\Support\Tenancy\TenantUserIdResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

class SyncSkillUsersAction
{
    public function __construct(private readonly TenantUserIdResolver $tenantUserIds) {}

    /**
     * @param  list<string>  $userIds
     *
     * @throws ValidationException
     * @throws Throwable
     */
    public function execute(Skill $skill, array $userIds): void
    {
        Validator::make(['user_ids' => $userIds], [
            'user_ids' => ['array'],
            'user_ids.*' => ['string', 'ulid'],
        ])->validate();

        $holderIds = $this->tenantUserIds->resolve($skill->tenant_id, $userIds);

        if (count($holderIds) !== count(array_unique($userIds))) {
            throw ValidationException::withMessages([
                'user_ids' => __('i18n.backend.actions.skills.sync_skill_users_action.at_least_one_selected_user_cannot_receive_a_skill'),
            ]);
        }

        DB::transaction(static function () use ($skill, $holderIds): void {
            $skill->users()->sync($holderIds);
        });
    }
}
