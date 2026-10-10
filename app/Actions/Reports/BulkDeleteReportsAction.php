<?php

declare(strict_types=1);

namespace App\Actions\Reports;

use App\Actions\Abstracts\BulkDeleteAction;
use App\Models\Report;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class BulkDeleteReportsAction extends BulkDeleteAction
{
    public function __construct(private readonly DeleteReportAction $deleteReport) {}

    /**
     * @return Builder<Report>
     */
    protected function query(User $actor, ?Model $scope): Builder
    {
        return Report::query()->where('tenant_id', $actor->tenant_id);
    }

    /**
     * @param  Report  $model
     */
    protected function deleteOne(User $actor, Model $model): void
    {
        $this->deleteReport->execute($actor, $model);
    }
}
