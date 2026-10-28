<?php

declare(strict_types=1);

namespace App\Actions\Engine;

use App\Actions\Abstracts\BulkDeleteAction;
use App\Models\ReminderType;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class BulkDeleteReminderTypesAction extends BulkDeleteAction
{
    public function __construct(private readonly DeleteReminderTypeAction $deleteReminderType) {}

    /**
     * @return Builder<ReminderType>
     */
    protected function query(User $actor, ?Model $scope): Builder
    {
        return ReminderType::query();
    }

    /**
     * @param  ReminderType  $model
     */
    protected function deleteOne(User $actor, Model $model): void
    {
        $this->deleteReminderType->execute($model);
    }

    protected function mayDelete(User $actor, Model $model): bool
    {
        return true;
    }
}
