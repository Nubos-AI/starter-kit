<?php

declare(strict_types=1);

namespace App\Contracts\Dashboards;

use App\DTOs\Dashboards\DashboardTileResultData;
use App\Enums\Reports\ChartType;
use App\Models\User;

interface DashboardTileSourceInterface
{
    public function key(): string;

    public function label(): string;

    public function defaultChartType(): ChartType;

    public function defaultColumnSpan(): int;

    public function isVisibleTo(User $viewer): bool;

    public function result(User $viewer): DashboardTileResultData;
}
