<?php

declare(strict_types=1);

namespace App\Policies\Governance;

use App\Models\AbsenceDelegation;
use App\Models\User;

class AbsenceDelegationPolicy
{
    public function view(User $user, AbsenceDelegation $delegation): bool
    {
        if ($delegation->tenant_id !== $user->tenant_id) {
            return false;
        }

        return $this->isOwnRow($user, $delegation) || $user->hasPermission('absences.manage');
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
