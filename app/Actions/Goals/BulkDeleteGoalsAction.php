<?php

declare(strict_types=1);

namespace App\Actions\Goals;

use App\Actions\Abstracts\BulkDeleteAction;
use App\Models\Goal;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class BulkDeleteGoalsAction extends BulkDeleteAction
{
    public function __construct(private readonly DeleteGoalAction $deleteGoal) {}

    /**
     * @return Builder<Goal>
     */
    protected function query(User $actor, ?Model $scope): Builder
    {
        return Goal::query()->where('tenant_id', $actor->tenant_id);
    }

    /**
     * @param  Goal  $model
     */
    protected function deleteOne(User $actor, Model $model): void
    {
        $this->deleteGoal->execute($actor, $model);
    }
}
