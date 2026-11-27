<?php

declare(strict_types=1);

namespace App\Actions\Reports;

use App\Models\Report;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

class DeleteReportAction
{
    /**
     * @throws AuthorizationException
     */
    public function execute(User $actor, Report $report): void
    {
        Gate::forUser($actor)->authorize('delete', $report);

        $report->delete();
    }
}
