<?php

declare(strict_types=1);

namespace App\Actions\Engine;

use App\Actions\Abstracts\BulkDeleteAction;
use App\Models\ActivityType;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class BulkDeleteActivityTypesAction extends BulkDeleteAction
{
    public function __construct(private readonly DeleteActivityTypeAction $deleteActivityType) {}

    /**
     * @return Builder<ActivityType>
     */
    protected function query(User $actor, ?Model $scope): Builder
    {
        return ActivityType::query();
    }

    /**
     * @param  ActivityType  $model
     */
    protected function deleteOne(User $actor, Model $model): void
    {
        $this->deleteActivityType->execute($model);
    }

    protected function mayDelete(User $actor, Model $model): bool
    {
        return true;
    }
}
