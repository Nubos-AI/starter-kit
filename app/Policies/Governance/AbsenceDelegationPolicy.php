<?php

declare(strict_types=1);

namespace App\Policies\Governance;

use App\Models\AbsenceDelegation;
use App\Models\User;
use App\Support\Governance\AbsenceDelegationAccessGuard;

class AbsenceDelegationPolicy
{
    public function __construct(private readonly AbsenceDelegationAccessGuard $accessGuard) {}

    public function viewAny(User $user): bool
    {
        return $user->tenant_id !== null;
    }

    public function create(User $user): bool
    {
        return $user->tenant_id !== null;
    }

    public function deleteAny(User $user): bool
    {
        return $user->tenant_id !== null;
    }

    public function view(User $user, AbsenceDelegation $delegation): bool
    {
        if ($delegation->tenant_id !== $user->tenant_id) {
            return false;
        }

        if ($this->isOwnRow($user, $delegation)) {
            return true;
        }

        $absentUser = $delegation->user;

        return $user->hasPermission('absences.manage')
            && $absentUser instanceof User
            && $this->accessGuard->mayManageAbsencesOf($user, $absentUser);
    }

    public function update(User $user, AbsenceDelegation $delegation): bool
    {
        return $this->view($user, $delegation);
    }

    public function delete(User $user, AbsenceDelegation $delegation): bool
    {
        return $this->view($user, $delegation);
    }

    private function isOwnRow(User $user, AbsenceDelegation $delegation): bool
    {
        return $delegation->user_id === $user->getKey();
    }
}
