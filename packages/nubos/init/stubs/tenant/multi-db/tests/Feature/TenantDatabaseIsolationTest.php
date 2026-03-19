<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenantA = Tenant::factory()->create([
        'slug' => 'alpha',
        'database' => 'tenant_alpha',
    ]);
    $this->tenantB = Tenant::factory()->create([
        'slug' => 'bravo',
        'database' => 'tenant_bravo',
    ]);
});

it('configures unique database per tenant', function (): void {
    expect($this->tenantA->database)->not->toBe($this->tenantB->database);
});

it('switches database connection when configuring tenant', function (): void {
    $this->tenantA->configureDatabaseConnection();
    expect(config('database.connections.tenant.database'))->toBe('tenant_alpha');

    $this->tenantB->configureDatabaseConnection();
    expect(config('database.connections.tenant.database'))->toBe('tenant_bravo');
});

it('resolves tenant membership correctly', function (): void {
    $user = User::factory()->create();
    $this->tenantA->members()->attach($user, ['role' => 'owner']);

    expect($user->belongsToTenant($this->tenantA))->toBeTrue()
        ->and($user->belongsToTenant($this->tenantB))->toBeFalse()
        ->and($user->ownsTenant($this->tenantA))->toBeTrue()
        ->and($user->ownsTenant($this->tenantB))->toBeFalse();
});

it('stores tenants in central database', function (): void {
    $tenant = new Tenant();

    expect($tenant->getConnectionName())->not->toBe('tenant');
});
