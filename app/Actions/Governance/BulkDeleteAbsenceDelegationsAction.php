<?php

declare(strict_types=1);

namespace App\Actions\Governance;

use App\Actions\Abstracts\BulkDeleteAction;
use App\Models\AbsenceDelegation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class BulkDeleteAbsenceDelegationsAction extends BulkDeleteAction
{
    public function __construct(private readonly DeleteAbsenceDelegationAction $deleteAbsenceDelegation) {}

    /**
     * @return Builder<AbsenceDelegation>
     */
    protected function query(User $actor, ?Model $scope): Builder
    {
        $tenantId = $actor->tenant_id;

        if ($tenantId === null || !$scope instanceof User) {
            return AbsenceDelegation::query()->whereRaw('1 = 0');
        }

        return AbsenceDelegation::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', (string) $scope->getKey());
    }

    /**
     * @param  AbsenceDelegation  $model
     */
    protected function deleteOne(User $actor, Model $model): void
    {
        $this->deleteAbsenceDelegation->execute($actor, $model);
    }
}
