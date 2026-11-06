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

it('flags exactly the given insight of the bound tenant as shipped content', function (string $modelClass, string $actionClass, string $table, string $seed): void {
    $insight = ModelStub::make($modelClass, [
        'id' => ModelStub::ulid($seed),
        'tenant_id' => $this->tenant->getKey(),
        'is_system' => false,
    ]);

    $attempt = QueryShape::attemptedBy(fn () => (new $actionClass)->execute($insight));

    expect($attempt?->sql)->toStartWith("update \"{$table}\" set \"is_system\" = ?")
        ->and($attempt?->isKeyedTo($table, (string) $insight->getKey()))->toBeTrue()
        ->and($attempt?->isScopedToTenant($table, (string) $this->tenant->getKey()))->toBeTrue();
})->with([
    'dashboard' => [Dashboard::class, MarkDashboardAsSystemAction::class, 'dashboards', 'shipped-dashboard'],
    'report' => [Report::class, MarkReportAsSystemAction::class, 'reports', 'shipped-report'],
]);

it('leaves an insight that already is shipped content untouched', function (string $modelClass, string $actionClass, string $seed): void {
    $insight = ModelStub::make($modelClass, [
        'id' => ModelStub::ulid($seed),
        'tenant_id' => $this->tenant->getKey(),
        'is_system' => true,
    ]);

    expect(QueryShape::attemptedBy(fn () => (new $actionClass)->execute($insight)))->toBeNull();
})->with([
    'dashboard' => [Dashboard::class, MarkDashboardAsSystemAction::class, 'shipped-dashboard'],
    'report' => [Report::class, MarkReportAsSystemAction::class, 'shipped-report'],
]);
