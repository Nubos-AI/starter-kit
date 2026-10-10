<?php

declare(strict_types=1);

namespace App\Actions\Engine;

use App\Actions\Abstracts\BulkDeleteAction;
use App\Models\ObjectType;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class BulkDeleteObjectTypesAction extends BulkDeleteAction
{
    public function __construct(private readonly DeleteObjectTypeAction $deleteObjectType) {}

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
}
