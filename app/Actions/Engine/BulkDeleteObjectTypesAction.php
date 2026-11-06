<?php

declare(strict_types=1);

namespace App\Actions\Engine;

use App\Actions\Abstracts\BulkDeleteAction;
use App\Models\ObjectType;
use App\Models\User;
use App\Support\Engine\ObjectTypeRecordPresence;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class BulkDeleteObjectTypesAction extends BulkDeleteAction
{
    public function __construct(
        private readonly DeleteObjectTypeAction $deleteObjectType,
        private readonly ObjectTypeRecordPresence $recordPresence,
    ) {}

    /**
     * @return Builder<ObjectType>
     */
    protected function query(User $actor, ?Model $scope): Builder
    {
        return ObjectType::query();
    }

    /**
     * @param  ObjectType  $model
     */
    protected function deleteOne(User $actor, Model $model): void
    {
        $this->deleteObjectType->execute($model);
    }

    /**
     * @param  ObjectType  $model
     */
    protected function mayDelete(User $actor, Model $model): bool
    {
        return parent::mayDelete($actor, $model) && !$this->recordPresence->holdsRecords($model);
    }
}
