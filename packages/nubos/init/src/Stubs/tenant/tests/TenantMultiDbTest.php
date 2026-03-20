<?php

declare(strict_types=1);

use App\Actions\Tenants\CreateTenantAction;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

it('configures tenant database connection', function (): void {
    $tenant = Tenant::factory()->create([
        'db_host' => '127.0.0.1',
        'db_port' => 5432,
        'db_database' => 'tenant_test',
        'db_username' => 'tenant_user',
        'db_password' => 'secret',
    ]);

    $tenant->configureDatabaseConnection();

    expect(config('database.connections.tenant.database'))->toBe('tenant_test')
        ->and(config('database.connections.tenant.username'))->toBe('tenant_user')
        ->and(config('database.connections.tenant.password'))->toBe('secret');
});

it('does not double-encrypt db_password', function (): void {
    $tenant = Tenant::factory()->create([
        'db_host' => '127.0.0.1',
        'db_port' => 5432,
        'db_database' => 'tenant_test',
        'db_username' => 'tenant_user',
        'db_password' => 'my-secret-password',
    ]);

    $tenant->refresh();

    expect($tenant->db_password)->toBe('my-secret-password')
        ->and($tenant->getRawOriginal('db_password'))->not->toBe('my-secret-password');
});

it('purges and reconnects on configure', function (): void {
    $tenant = Tenant::factory()->create([
        'db_host' => '127.0.0.1',
        'db_port' => 5432,
        'db_database' => 'tenant_test_a',
        'db_username' => 'user_a',
        'db_password' => 'pass_a',
    ]);

    $tenant->configureDatabaseConnection();

    expect(config('database.connections.tenant.database'))->toBe('tenant_test_a');

    $tenant->update([
        'db_database' => 'tenant_test_b',
        'db_username' => 'user_b',
        'db_password' => 'pass_b',
    ]);

    $tenant->configureDatabaseConnection();

    expect(config('database.connections.tenant.database'))->toBe('tenant_test_b')
        ->and(config('database.connections.tenant.username'))->toBe('user_b');
});

it('restores tenant context in queue jobs', function (): void {
    $tenant = Tenant::factory()->create([
        'db_host' => '127.0.0.1',
        'db_port' => 5432,
        'db_database' => 'tenant_queue_test',
        'db_username' => 'queue_user',
        'db_password' => 'queue_pass',
    ]);

    app()->instance('current_tenant', $tenant);
    $tenant->configureDatabaseConnection();

    app()->forgetInstance('current_tenant');

    $tenant->refresh();
    $tenant->configureDatabaseConnection();
    app()->instance('current_tenant', $tenant);

    expect(app('current_tenant')->id)->toBe($tenant->id)
        ->and(config('database.connections.tenant.database'))->toBe('tenant_queue_test');
});

it('keeps central tables on main database', function (): void {
    expect(Tenant::query()->getQuery()->from)->toBe('tenants')
        ->and((new Tenant())->getConnectionName())->not->toBe('tenant');
});
