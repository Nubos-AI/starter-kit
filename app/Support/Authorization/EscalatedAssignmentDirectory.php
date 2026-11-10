<?php

declare(strict_types=1);

namespace App\Support\Authorization;

use App\Enums\Users\UserStatus;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class EscalatedAssignmentDirectory
{
    /**
     * @return Collection<int, RoleAssignment>
     */
    public function lockedAssignments(): Collection
    {
        return $this->assignmentQuery()->lockForUpdate()->get();
    }

    /**
     * @return Collection<int, RoleAssignment>
     */
    public function assignments(): Collection
    {
        return $this->assignmentQuery()->get();
    }

    /**
     * @return Builder<RoleAssignment>
     */
    private function assignmentQuery(): Builder
    {
        return RoleAssignment::query()
            ->with('role')
            ->whereIn('role_id', Role::query()->whereNotNull('authority')->pluck('id'))
            ->where(function (Builder $query): void {
                $query->where('model_type', '!=', (new User)->getMorphClass())
                    ->orWhereIn('model_id', User::query()
                        ->whereNull('deleted_at')
                        ->where('status', UserStatus::Accepted->value)
                        ->select('id'));
            });
    }
}
