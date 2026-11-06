<?php

declare(strict_types=1);

namespace App\Actions\Skills;

use App\Actions\Abstracts\BulkDeleteAction;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class BulkDeleteSkillsAction extends BulkDeleteAction
{
    public function __construct(private readonly DeleteSkillAction $deleteSkill) {}

    /**
     * @return Builder<Skill>
     */
    protected function query(User $actor, ?Model $scope): Builder
    {
        $tenantId = $actor->tenant_id;

        if ($tenantId === null) {
            return Skill::query()->whereRaw('1 = 0');
        }

        return Skill::query()->where('tenant_id', $tenantId);
    }

    /**
     * @param  Skill  $model
     */
    protected function deleteOne(User $actor, Model $model): void
    {
        $this->deleteSkill->execute($model);
    }
}
