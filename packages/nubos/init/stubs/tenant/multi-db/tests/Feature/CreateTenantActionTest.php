<?php

declare(strict_types=1);

use App\Actions\Tenants\CreateTenantAction;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantDatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('creates a tenant with a valid name', function (): void {
    $manager = Mockery::mock(TenantDatabaseManager::class);
    $manager->shouldReceive('createDatabase')->once();

    $user = User::factory()->create();
    $action = new CreateTenantAction($manager);

    $tenant = $action->execute($user, ['name' => 'Acme Corp']);

    expect($tenant)
        ->toBeInstanceOf(Tenant::class)
        ->and($tenant->name)->toBe('Acme Corp')
        ->and($tenant->slug)->toBe('acme-corp');
});

it('rejects a tenant name that resolves to a reserved subdomain', function (): void {
    config(['nubos.reserved_subdomains' => ['www', 'api', 'docs']]);

    $manager = Mockery::mock(TenantDatabaseManager::class);
    $manager->shouldNotReceive('createDatabase');

    $user = User::factory()->create();
    $action = new CreateTenantAction($manager);

    $action->execute($user, ['name' => 'API']);
})->throws(ValidationException::class, 'reserved subdomain');

it('rejects all slug retry variants against reserved subdomains', function (): void {
    config(['nubos.reserved_subdomains' => ['www', 'api', 'api-1', 'api-2', 'api-3']]);

    Tenant::factory()->create(['slug' => 'api']);

    $manager = Mockery::mock(TenantDatabaseManager::class);
    $manager->shouldNotReceive('createDatabase');

    $user = User::factory()->create();
    $action = new CreateTenantAction($manager);

    $action->execute($user, ['name' => 'API']);
})->throws(ValidationException::class);

it('cleans up tenant record when database creation fails', function (): void {
    $manager = Mockery::mock(TenantDatabaseManager::class);
    $manager->shouldReceive('createDatabase')
        ->once()
        ->andThrow(new RuntimeException('Database creation failed'));

    $user = User::factory()->create();
    $action = new CreateTenantAction($manager);

    expect(fn () => $action->execute($user, ['name' => 'Failing Corp']))
        ->toThrow(RuntimeException::class);

    expect(Tenant::query()->where('slug', 'failing-corp')->exists())->toBeFalse();
});
