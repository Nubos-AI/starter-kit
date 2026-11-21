<?php

declare(strict_types=1);

namespace App\Actions\Aging;

use App\Actions\Abstracts\BulkDeleteAction;
use App\Models\AgingRule;
use App\Models\ObjectType;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class BulkDeleteAgingRulesAction extends BulkDeleteAction
{
    public function __construct(private readonly DeleteAgingRuleAction $deleteAgingRule) {}

    /**
     * @return Builder<AgingRule>
     */
    protected function query(User $actor, ?Model $scope): Builder
    {
        return AgingRule::query()
            ->where('object_type_id', $scope instanceof ObjectType ? $scope->getKey() : null);
    }

    /**
     * @param  AgingRule  $model
     */
    protected function deleteOne(User $actor, Model $model): void
    {
        $this->deleteAgingRule->execute($model);
    }
}
