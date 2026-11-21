<?php

declare(strict_types=1);

namespace App\Support\Users;

use App\Enums\Users\UserStatus;
use App\Models\User;
use App\Support\Authorization\ManagedUserResolver;
use App\Support\Authorization\PermissionSubsetGuard;
use App\Support\Authorization\SelfLockoutGuard;
use App\Support\Http\IdentifierList;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

class ManageableUserRows
{
    private string $selfDeleteReason = 'i18n.backend.support.users.manageable_user_rows.your_own_account_delete_it_in_your_profile';

    private string $lastEscalatedHolderReason = 'i18n.backend.support.users.manageable_user_rows.last_administrator_promote_another_person_first';

    private string $selfBlockReason = 'i18n.backend.support.users.manageable_user_rows.your_own_account_you_cannot_block_yourself';

    private string $beyondOwnRightsReason = 'i18n.backend.support.users.manageable_user_rows.holds_more_rights_than_you';

    public function __construct(
        private readonly ManagedUserResolver $managedUsers,
        private readonly SelfLockoutGuard $selfLockoutGuard,
        private readonly ManageableUserDirectory $directory,
        private readonly PermissionSubsetGuard $subsetGuard,
    ) {}

    /**
     * @param  EloquentCollection<int, User>  $users
     * @return array<int, array<string, mixed>>
     */
    public function forActingUser(EloquentCollection $users, User $actingUser): array
    {
        $lastEscalatedHolderKeys = $this->selfLockoutGuard->lastEscalatedHolderKeys();
        $userKeys = IdentifierList::from($users->modelKeys());

        $roleIdsByUser = $this->directory->roleIdsByUser($userKeys);
        $escalatedKeys = $this->directory->escalatedUserKeys($userKeys);
        $teamIdsByUser = $this->directory->teamIdsByUser($userKeys);

        $mayUpdate = $this->managedUsers->reachOf($actingUser, 'members.update');
        $mayRemove = $this->managedUsers->reachOf($actingUser, 'members.remove');
        $mayBlock = $this->managedUsers->reachOf($actingUser, 'members.block');
        $mayInvite = $actingUser->can('invite', User::class)
            ? $this->managedUsers->reachOf($actingUser, 'members.invite')
            : ['tenant' => false, 'teams' => []];

        $judgedKeys = IdentifierList::from($users
            ->filter(fn (User $user): bool => !$user->is_service
                && !$actingUser->is($user)
                && $this->reachesAny([$mayUpdate, $mayRemove, $mayBlock, $mayInvite], $teamIdsByUser[(string) $user->getKey()] ?? []))
            ->modelKeys());
        $steerableKeys = $this->subsetGuard->steerableAccountKeys($actingUser, $judgedKeys, $roleIdsByUser, $teamIdsByUser);

        return $users->map(function (User $user) use (
            $actingUser,
            $lastEscalatedHolderKeys,
            $roleIdsByUser,
            $escalatedKeys,
            $teamIdsByUser,
            $mayUpdate,
            $mayRemove,
            $mayBlock,
            $mayInvite,
            $judgedKeys,
            $steerableKeys,
        ): array {
            $key = (string) $user->getKey();
            $isSelf = $actingUser->is($user);
            $isEscalated = in_array($key, $escalatedKeys, true);
            $isLastEscalatedHolder = $lastEscalatedHolderKeys->contains($key);

            $teamIds = $teamIdsByUser[$key] ?? [];

            $isBeyondOwnRights = in_array($key, $judgedKeys, true) && !in_array($key, $steerableKeys, true);

            $mayEditThis = !$user->is_service
                && $this->reaches($mayUpdate, $teamIds)
                && ($actingUser->isEscalatedAuthority() || !$isEscalated)
                && !$isBeyondOwnRights;
            $mayDeleteThis = !$isSelf && !$user->is_service && $this->reaches($mayRemove, $teamIds) && !$isBeyondOwnRights;
            $mayBlockThis = !$isSelf && !$user->is_service && $this->reaches($mayBlock, $teamIds) && !$isBeyondOwnRights;

            return [
                'id' => $user->getKey(),
                'name' => $user->name,
                'email' => $user->email,
                'status' => $user->status->value,
                'role_ids' => $roleIdsByUser[$key] ?? [],
                'is_escalated' => $isEscalated,
                'can_update' => $mayEditThis,
                'can_delete' => $mayDeleteThis && !$isLastEscalatedHolder,
                'delete_reason' => $this->reason($isSelf, __($this->selfDeleteReason), $mayDeleteThis, $isLastEscalatedHolder, $isBeyondOwnRights),
                'can_block' => $mayBlockThis && !$isLastEscalatedHolder,
                'block_reason' => $this->reason($isSelf, __($this->selfBlockReason), $mayBlockThis, $isLastEscalatedHolder, $isBeyondOwnRights),
                'can_resend_invitation' => $user->status === UserStatus::Invited && !$user->is_service && $this->reaches($mayInvite, $teamIds) && !$isBeyondOwnRights,
            ];
        })->all();
    }

    /**
     * @param  array{tenant: bool, teams: list<string>}  $reach
     * @param  list<string>  $teamIds
     */
    private function reaches(array $reach, array $teamIds): bool
    {
        if ($reach['tenant']) {
            return true;
        }

        return array_intersect($reach['teams'], $teamIds) !== [];
    }

    /**
     * @param  list<array{tenant: bool, teams: list<string>}>  $reaches
     * @param  list<string>  $teamIds
     */
    private function reachesAny(array $reaches, array $teamIds): bool
    {
        return array_any($reaches, fn (array $reach): bool => $this->reaches($reach, $teamIds));
    }

    private function reason(bool $isSelf, string $selfReason, bool $mayAct, bool $isLastEscalatedHolder, bool $isBeyondOwnRights): ?string
    {
        if ($isSelf) {
            return $selfReason;
        }

        if ($isBeyondOwnRights) {
            return __($this->beyondOwnRightsReason);
        }

        if ($mayAct && $isLastEscalatedHolder) {
            return __($this->lastEscalatedHolderReason);
        }

        return null;
    }
}
