<?php

declare(strict_types=1);

use App\Actions\Tenants\CreateTenantAction;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('creates a tenant with a valid name', function (): void {
    $user = User::factory()->create();
    $action = new CreateTenantAction();

    $tenant = $action->execute($user, ['name' => 'Acme Corp']);

    expect($tenant)
        ->toBeInstanceOf(Tenant::class)
        ->and($tenant->name)->toBe('Acme Corp')
        ->and($tenant->slug)->toBe('acme-corp');
});

it('rejects a tenant name that resolves to a reserved subdomain', function (): void {
    config(['nubos.reserved_subdomains' => ['www', 'api', 'docs']]);

    $user = User::factory()->create();
    $action = new CreateTenantAction();

    $action->execute($user, ['name' => 'API']);
})->throws(ValidationException::class);

it('rejects all slug retry variants against reserved subdomains', function (): void {
    config(['nubos.reserved_subdomains' => ['www', 'api', 'api-1', 'api-2', 'api-3']]);

    Tenant::factory()->create(['slug' => 'api']);

    $user = User::factory()->create();
    $action = new CreateTenantAction();

    $action->execute($user, ['name' => 'API']);
})->throws(ValidationException::class);
