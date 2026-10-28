<?php

declare(strict_types=1);

use App\Actions\Dashboards\MarkDashboardAsSystemAction;
use App\Actions\Reports\MarkReportAsSystemAction;
use App\Models\Dashboard;
use App\Models\Report;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant('system-insight-tenant');
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('flags exactly the given dashboard of the bound tenant as shipped content', function (): void {
    $dashboard = ModelStub::make(Dashboard::class, [
        'id' => ModelStub::ulid('shipped-dashboard'),
        'tenant_id' => $this->tenant->getKey(),
        'is_system' => false,
    ]);

    $attempt = QueryShape::attemptedBy(fn () => (new MarkDashboardAsSystemAction)->execute($dashboard));

    expect($attempt?->sql)->toStartWith('update "dashboards" set "is_system" = ?')
        ->and($attempt?->isKeyedTo('dashboards', (string) $dashboard->getKey()))->toBeTrue()
        ->and($attempt?->isScopedToTenant('dashboards', (string) $this->tenant->getKey()))->toBeTrue();
});

it('leaves a dashboard that already is shipped content untouched', function (): void {
    $dashboard = ModelStub::make(Dashboard::class, [
        'id' => ModelStub::ulid('shipped-dashboard'),
        'tenant_id' => $this->tenant->getKey(),
        'is_system' => true,
    ]);

    expect(QueryShape::attemptedBy(fn () => (new MarkDashboardAsSystemAction)->execute($dashboard)))->toBeNull();
});

it('flags exactly the given report of the bound tenant as shipped content', function (): void {
    $report = ModelStub::make(Report::class, [
        'id' => ModelStub::ulid('shipped-report'),
        'tenant_id' => $this->tenant->getKey(),
        'is_system' => false,
    ]);

    $attempt = QueryShape::attemptedBy(fn () => (new MarkReportAsSystemAction)->execute($report));

    expect($attempt?->sql)->toStartWith('update "reports" set "is_system" = ?')
        ->and($attempt?->isKeyedTo('reports', (string) $report->getKey()))->toBeTrue()
        ->and($attempt?->isScopedToTenant('reports', (string) $this->tenant->getKey()))->toBeTrue();
});

it('leaves a report that already is shipped content untouched', function (): void {
    $report = ModelStub::make(Report::class, [
        'id' => ModelStub::ulid('shipped-report'),
        'tenant_id' => $this->tenant->getKey(),
        'is_system' => true,
    ]);

    expect(QueryShape::attemptedBy(fn () => (new MarkReportAsSystemAction)->execute($report)))->toBeNull();
});
