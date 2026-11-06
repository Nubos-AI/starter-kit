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
     * @return Collection<int, string>
     */
    public function holderKeys(): Collection
    {
        return $this->assignmentQuery()
            ->pluck('model_id')
            ->map(static fn (mixed $key): string => (string) $key)
            ->unique()
            ->values();
    }

    /**
     * @return Builder<RoleAssignment>
     */
    private function assignmentQuery(): Builder
    {
        return RoleAssignment::query()
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
