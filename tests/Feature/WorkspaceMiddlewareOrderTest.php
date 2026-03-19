<?php

declare(strict_types=1);

use App\Models\Workspace;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('binds currentTenant before setting current workspace', function (): void {
    config(['app.url' => 'http://example.com']);

    $tenant = Tenant::factory()->create(['slug' => 'acme']);
    $user = User::factory()->create();
    $tenant->members()->attach($user, ['role' => 'owner']);

    $workspace = Workspace::factory()->create();
    $workspace->members()->attach($user, ['role' => 'owner']);

    $this->actingAs($user)
        ->withServerVariables(['HTTP_HOST' => 'acme.example.com'])
        ->get("/workspaces/{$workspace->id}/dashboard")
        ->assertSuccessful();

    expect(app()->bound('currentTenant'))->toBeTrue()
        ->and(app('currentTenant')->id)->toBe($tenant->id)
        ->and(app()->bound('currentWorkspace'))->toBeTrue()
        ->and(app('currentWorkspace')->id)->toBe($workspace->id);
});

it('rejects workspace belonging to a different tenant', function (): void {
    config(['app.url' => 'http://example.com']);

    $tenantA = Tenant::factory()->create(['slug' => 'tenant-a']);
    $tenantB = Tenant::factory()->create(['slug' => 'tenant-b']);

    $user = User::factory()->create();
    $tenantA->members()->attach($user, ['role' => 'owner']);

    $workspace = Workspace::factory()->create();
    $workspace->members()->attach($user, ['role' => 'owner']);

    $this->actingAs($user)
        ->withServerVariables(['HTTP_HOST' => 'tenant-a.example.com'])
        ->get("/workspaces/{$workspace->id}/dashboard")
        ->assertStatus(403);
});

it('rejects non-tenant-members from accessing workspace routes', function (): void {
    config(['app.url' => 'http://example.com']);

    $tenant = Tenant::factory()->create(['slug' => 'acme']);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withServerVariables(['HTTP_HOST' => 'acme.example.com'])
        ->get("/workspaces")
        ->assertStatus(403);
});
