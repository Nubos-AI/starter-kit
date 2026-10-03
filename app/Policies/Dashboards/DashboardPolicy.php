<?php

declare(strict_types=1);

namespace App\Policies\Dashboards;

use App\Models\Dashboard;
use App\Models\DashboardShare;
use App\Models\User;
use App\Support\Authorization\TenantBoundary;
use App\Support\Sharing\ShareGranteeMatcher;

class DashboardPolicy
{
    public function __construct(
        private readonly TenantBoundary $tenantBoundary,
        private readonly ShareGranteeMatcher $grantees,
    ) {}

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Dashboard $dashboard): bool
    {
        if (!$this->tenantBoundary->admits($user, $dashboard)) {
            return false;
        }

        return $this->isOwner($user, $dashboard)
            || $dashboard->is_tenant_wide
            || $this->hasGrant($user, $dashboard, false)
            || $user->isEscalatedAuthority();
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Dashboard $dashboard): bool
    {
        if (!$this->view($user, $dashboard)) {
            return false;
        }

        return $this->isOwner($user, $dashboard)
            || $user->isEscalatedAuthority()
            || $this->hasGrant($user, $dashboard, true);
    }

    public function delete(User $user, Dashboard $dashboard): bool
    {
        if (!$this->view($user, $dashboard)) {
            return false;
        }

        return $this->isOwner($user, $dashboard)
            || $user->isEscalatedAuthority();
    }

    public function share(User $user, Dashboard $dashboard): bool
    {
        if (!$this->view($user, $dashboard)) {
            return false;
        }

        return $this->isOwner($user, $dashboard)
            || $user->isEscalatedAuthority();
    }

    private function isOwner(User $user, Dashboard $dashboard): bool
    {
        return $dashboard->owner_id === $user->getKey();
    }

    private function hasGrant(User $user, Dashboard $dashboard, bool $requiresEdit): bool
    {
        return $dashboard->shares->contains(
            fn (DashboardShare $grant): bool => (!$requiresEdit || $grant->can_edit)
                && $this->grantees->matches($grant->grantee_type, (string) $grant->grantee_id, $user),
        );
    }
}
