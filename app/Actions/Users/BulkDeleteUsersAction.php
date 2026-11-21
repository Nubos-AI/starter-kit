<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Actions\Abstracts\BulkDeleteAction;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class BulkDeleteUsersAction extends BulkDeleteAction
{
    public function __construct(private readonly DeleteUserAction $deleteUser) {}

    /**
     * @return Builder<User>
     */
    protected function query(User $actor, ?Model $scope): Builder
    {
        $query = User::query();

        return $query->where($query->qualifyColumn('tenant_id'), $actor->tenant_id);
    }

    /**
     * @param  User  $model
     */
    protected function deleteOne(User $actor, Model $model): void
    {
        $this->deleteUser->execute($model);
    }
}
