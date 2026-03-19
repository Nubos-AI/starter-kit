<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\TenantDatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates a database for a new tenant', function (): void {
    $tenant = Tenant::factory()->create(['database' => 'tenant_test_create']);
    $manager = app(TenantDatabaseManager::class);

    $manager->createDatabase($tenant);

    expect(config('database.connections.tenant.database'))->toBe('tenant_test_create');
})->skip(fn () => config('database.default') === 'sqlite', 'Requires MySQL/PostgreSQL');

it('migrates a specific tenant database', function (): void {
    $tenant = Tenant::factory()->create(['database' => 'tenant_test_migrate']);
    $manager = app(TenantDatabaseManager::class);

    $manager->createDatabase($tenant);
    $manager->migrate($tenant);

    expect(config('database.connections.tenant.database'))->toBe('tenant_test_migrate');
})->skip(fn () => config('database.default') === 'sqlite', 'Requires MySQL/PostgreSQL');

it('drops a tenant database', function (): void {
    $tenant = Tenant::factory()->create(['database' => 'tenant_test_drop']);
    $manager = app(TenantDatabaseManager::class);

    $manager->createDatabase($tenant);
    $manager->dropDatabase($tenant);

    $this->expectNotToPerformAssertions();
})->skip(fn () => config('database.default') === 'sqlite', 'Requires MySQL/PostgreSQL');
