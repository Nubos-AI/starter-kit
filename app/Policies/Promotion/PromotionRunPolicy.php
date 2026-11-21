<?php

declare(strict_types=1);

namespace App\Policies\Promotion;

use App\Models\PromotionRun;
use App\Models\User;

class PromotionRunPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('promotions.execute');
    }

    public function view(User $user, PromotionRun $run): bool
    {
        if (!$this->belongsToUsersTenant($user, $run)) {
            return false;
        }

        return $user->hasPermission('promotions.execute');
    }

    public function create(User $user): bool
    {
        return $this->mayTrigger($user);
    }

    public function update(User $user, PromotionRun $run): bool
    {
        return $this->belongsToUsersTenant($user, $run) && $this->mayTrigger($user);
    }

    public function submit(User $user, PromotionRun $run): bool
    {
        return $this->belongsToUsersTenant($user, $run) && $this->mayTrigger($user);
    }

    public function rollback(User $user, PromotionRun $run): bool
    {
        return $this->belongsToUsersTenant($user, $run) && $this->mayTrigger($user);
    }

    private function mayTrigger(User $user): bool
    {
        return $user->hasPermission('promotions.execute');
    }

    private function belongsToUsersTenant(User $user, PromotionRun $run): bool
    {
        return $user->tenant_id !== null && $run->tenant_id === $user->tenant_id;
    }
}
