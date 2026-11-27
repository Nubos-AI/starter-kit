<?php

declare(strict_types=1);

namespace App\Support\Governance;

use App\Models\User;
use App\Support\Authorization\ManagedUserResolver;
use App\Support\Authorization\PermissionSubsetGuard;
use Illuminate\Validation\ValidationException;

class AbsenceDelegationAccessGuard
{
    public function __construct(
        private readonly ManagedUserResolver $managedUsers,
        private readonly PermissionSubsetGuard $subsetGuard,
    ) {}

    /**
     * @throws ValidationException
     */
    public function assertMayDelegateFor(User $actor, User $absentUser, string $delegateId): void
    {
        if ((string) $absentUser->getKey() === (string) $actor->getKey()) {
            return;
        }

        if ($delegateId === (string) $actor->getKey()) {
            throw ValidationException::withMessages([
                'delegateId' => __('i18n.backend.support.governance.absence_delegation_access_guard.you_cannot_name_yourself_the_delegate_of_another_person'),
            ]);
        }

        if ($this->mayManageAbsencesOf($actor, $absentUser)) {
            return;
        }

        throw ValidationException::withMessages([
            'userId' => __('i18n.backend.support.governance.absence_delegation_access_guard.this_person_is_outside_your_management_reach'),
        ]);
    }

    public function mayManageAbsencesOf(User $actor, User $absentUser): bool
    {
        if ((string) $absentUser->getKey() === (string) $actor->getKey()) {
            return true;
        }

        return $this->managedUsers->manages($actor, $absentUser, 'absences.manage')
            && $this->subsetGuard->maySteerAccount($actor, $absentUser);
    }
}
