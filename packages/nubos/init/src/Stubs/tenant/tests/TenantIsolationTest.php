<?php

declare(strict_types=1);

use App\Actions\Tenants\CreateTenantAction;
use App\Models\Tenant;
use App\Models\User;

it('isolates tenant data with tenant scope', function (): void {
    $ownerA = User::factory()->create();
    $ownerB = User::factory()->create();

    $action = app(CreateTenantAction::class);
    $tenantA = $action->execute($ownerA, ['name' => 'Tenant A', 'slug' => 'tenant-a']);
    $tenantB = $action->execute($ownerB, ['name' => 'Tenant B', 'slug' => 'tenant-b']);

    app()->instance('current_tenant', $tenantA);

    expect($tenantA->users)->toHaveCount(1)
        ->and($tenantA->users->first()->id)->toBe($ownerA->id);

    app()->instance('current_tenant', $tenantB);

    expect($tenantB->users)->toHaveCount(1)
        ->and($tenantB->users->first()->id)->toBe($ownerB->id);
});

it('does not leak tenants between requests', function (): void {
    $ownerA = User::factory()->create();
    $ownerB = User::factory()->create();

    $action = app(CreateTenantAction::class);
    $tenantA = $action->execute($ownerA, ['name' => 'Tenant A', 'slug' => 'tenant-a']);
    $tenantB = $action->execute($ownerB, ['name' => 'Tenant B', 'slug' => 'tenant-b']);

    $this->actingAs($ownerA)
        ->get('/dashboard', ['HTTP_HOST' => 'tenant-a.' . config('app.domain')])
        ->assertOk();

    $this->actingAs($ownerA)
        ->get('/dashboard', ['HTTP_HOST' => 'tenant-b.' . config('app.domain')])
        ->assertForbidden();
});

it('returns 403 when accessing another tenant resource', function (): void {
    $ownerA = User::factory()->create();
    $ownerB = User::factory()->create();

    $action = app(CreateTenantAction::class);
    $action->execute($ownerA, ['name' => 'Tenant A', 'slug' => 'tenant-a']);
    $action->execute($ownerB, ['name' => 'Tenant B', 'slug' => 'tenant-b']);

    $this->actingAs($ownerB)
        ->get('/dashboard', ['HTTP_HOST' => 'tenant-a.' . config('app.domain')])
        ->assertForbidden();
});

it('shows all tenants without tenant scope', function (): void {
    $ownerA = User::factory()->create();
    $ownerB = User::factory()->create();

    $action = app(CreateTenantAction::class);
    $action->execute($ownerA, ['name' => 'Tenant A', 'slug' => 'tenant-a']);
    $action->execute($ownerB, ['name' => 'Tenant B', 'slug' => 'tenant-b']);

    expect(Tenant::query()->count())->toBe(2);
});
