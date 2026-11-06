<?php

declare(strict_types=1);

namespace App\Actions\Teams;

use App\Actions\Abstracts\BulkDeleteAction;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class BulkDeleteTeamsAction extends BulkDeleteAction
{
    public function __construct(private readonly DeleteTeamAction $deleteTeam) {}

    /**
     * @return Builder<Team>
     */
    protected function query(User $actor, ?Model $scope): Builder
    {
        return Team::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $actor->tenant_id)
            ->whereNull('deleted_at');
    }

    /**
     * @param  Team  $model
     */
    protected function deleteOne(User $actor, Model $model): void
    {
        $this->deleteTeam->execute($actor, $model);
    }

    protected function mayDelete(User $actor, Model $model): bool
    {
        return true;
    }
}
