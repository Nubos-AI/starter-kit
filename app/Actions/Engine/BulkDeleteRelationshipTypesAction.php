<?php

declare(strict_types=1);

namespace App\Actions\Engine;

use App\Actions\Abstracts\BulkDeleteAction;
use App\Models\RelationshipType;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class BulkDeleteRelationshipTypesAction extends BulkDeleteAction
{
    public function __construct(private readonly DeleteRelationshipTypeAction $deleteRelationshipType) {}

    /**
     * @return Builder<RelationshipType>
     */
    protected function query(User $actor, ?Model $scope): Builder
    {
        return RelationshipType::query()->where('is_hierarchy', false);
    }

    /**
     * @param  RelationshipType  $model
     */
    protected function deleteOne(User $actor, Model $model): void
    {
        $this->deleteRelationshipType->execute($model);
    }
}
