<?php

declare(strict_types=1);

namespace App\Actions\Dashboards;

use App\Models\Dashboard;

class MarkDashboardAsSystemAction
{
    public function execute(Dashboard $dashboard): void
    {
        if ($dashboard->is_system) {
            return;
        }

        Dashboard::query()
            ->whereKey($dashboard->getKey())
            ->update(['is_system' => true]);

        $dashboard->is_system = true;
    }
}
