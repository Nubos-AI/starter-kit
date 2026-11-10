<?php

declare(strict_types=1);

namespace Tests\Fixtures\Dashboards;

use App\Contracts\Dashboards\DashboardTileSourceInterface;
use App\DTOs\Dashboards\DashboardTileResultData;
use App\DTOs\Reports\ReportResultData;
use App\Enums\Reports\AggregationType;
use App\Enums\Reports\ChartType;
use App\Models\User;

class StaticDashboardTileSource implements DashboardTileSourceInterface
{
    /**
     * @var list<string>
     */
    public array $computedFor = [];

    public function __construct(
        private readonly string $key = 'fixture.revenue',
        private readonly bool $isVisible = true,
        private readonly ?string $currency = 'EUR',
        private readonly string $total = '1250.50',
    ) {}

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return 'Umsatz';
    }

    public function defaultChartType(): ChartType
    {
        return ChartType::Metric;
    }

    public function defaultColumnSpan(): int
    {
        return 1;
    }

    public function isVisibleTo(User $viewer): bool
    {
        return $this->isVisible;
    }

    public function result(User $viewer): DashboardTileResultData
    {
        $this->computedFor[] = (string) $viewer->getKey();

        return new DashboardTileResultData(
            new ReportResultData(AggregationType::Sum, [], $this->total, 3, 0, false, '2026-11-08T09:00:00+00:00'),
            $this->currency,
        );
    }
}
