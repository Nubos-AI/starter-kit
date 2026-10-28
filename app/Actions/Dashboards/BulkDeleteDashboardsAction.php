<?php

declare(strict_types=1);

namespace App\Actions\Dashboards;

use App\Actions\Abstracts\BulkDeleteAction;
use App\Models\Dashboard;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class BulkDeleteDashboardsAction extends BulkDeleteAction
{
    public function __construct(private readonly DeleteDashboardAction $deleteDashboard) {}

    /**
     * @return Builder<Dashboard>
     */
    protected function query(User $actor, ?Model $scope): Builder
    {
        return Dashboard::query()->where('tenant_id', $actor->tenant_id);
    }

    /**
     * @param  Dashboard  $model
     */
    protected function deleteOne(User $actor, Model $model): void
    {
        $this->deleteDashboard->execute($actor, $model);
    }
}
