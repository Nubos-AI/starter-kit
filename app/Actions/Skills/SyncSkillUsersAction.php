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
     * @param  array<array-key, mixed>  $userIds
     *
     * @throws ValidationException
     * @throws Throwable
     */
    public function execute(Skill $skill, array $userIds): void
    {
        $holderIds = $this->holderIdsFor($skill, $userIds);

        DB::transaction(static function () use ($skill, $holderIds): void {
            $skill->users()->sync($holderIds);
        });
    }

    /**
     * @param  array<array-key, mixed>  $userIds
     * @return list<string>
     *
     * @throws ValidationException
     */
    public function holderIdsFor(Skill $skill, array $userIds): array
    {
        Validator::make(['user_ids' => $userIds], [
            'user_ids' => ['array'],
            'user_ids.*' => ['string', 'ulid'],
        ])->validate();

        $requestedIds = array_values(array_unique(array_map(
            static fn (mixed $id): string => is_scalar($id) ? (string) $id : '',
            $userIds,
        )));

        $holderIds = $this->tenantUserIds->resolve($skill->tenant_id, $requestedIds);

        if (count($holderIds) !== count($requestedIds)) {
            throw ValidationException::withMessages([
                'user_ids' => __('i18n.backend.actions.skills.sync_skill_users_action.at_least_one_selected_user_cannot_receive_a_skill'),
            ]);
        }

        return $holderIds;
    }
}
