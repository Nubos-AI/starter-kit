<?php

declare(strict_types=1);

namespace App\Actions\Engine;

use App\Actions\Abstracts\BulkDeleteAction;
use App\Models\MergeRule;
use App\Models\ObjectType;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class BulkDeleteMergeRulesAction extends BulkDeleteAction
{
    public function __construct(private readonly DeleteMergeRuleAction $deleteMergeRule) {}

    /**
     * @return Builder<MergeRule>
     */
    protected function query(User $actor, ?Model $scope): Builder
    {
        return MergeRule::query()
            ->where('object_type_id', $scope instanceof ObjectType ? $scope->getKey() : null);
    }

    /**
     * @param  MergeRule  $model
     */
    protected function deleteOne(User $actor, Model $model): void
    {
        $this->deleteMergeRule->execute($model);
    }
}
