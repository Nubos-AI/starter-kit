<?php

declare(strict_types=1);

namespace App\Actions\Authorization;

use App\Actions\Abstracts\BulkDeleteAction;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\RoleDeletionGuard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class BulkDeleteRolesAction extends BulkDeleteAction
{
    public function __construct(
        private readonly DeleteRoleAction $deleteRole,
        private readonly RoleDeletionGuard $roleDeletionGuard,
    ) {}

    /**
     * @return Builder<Role>
     */
    protected function query(User $actor, ?Model $scope): Builder
    {
        return Role::query()->where('tenant_id', $actor->tenant_id);
    }

    /**
     * @param  Role  $model
     */
    protected function deleteOne(User $actor, Model $model): void
    {
        $this->deleteRole->execute($actor, $model);
    }

    /**
     * @param  Role  $model
     */
    protected function mayDelete(User $actor, Model $model): bool
    {
        return $actor->can('delete', $model)
            && $this->roleDeletionGuard->isUnused($model);
    }
}
