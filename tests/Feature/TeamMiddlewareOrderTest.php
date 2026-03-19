<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('binds currentTenant before setting current team', function (): void {
    config(['app.url' => 'http://example.com']);

    $tenant = Tenant::factory()->create(['slug' => 'acme']);
    $user = User::factory()->create();
    $tenant->members()->attach($user, ['role' => 'owner']);

    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'owner']);

    $this->actingAs($user)
        ->withServerVariables(['HTTP_HOST' => 'acme.example.com'])
        ->get("/teams/{$team->id}/dashboard")
        ->assertSuccessful();

    expect(app()->bound('currentTenant'))->toBeTrue()
        ->and(app('currentTenant')->id)->toBe($tenant->id)
        ->and(app()->bound('currentTeam'))->toBeTrue()
        ->and(app('currentTeam')->id)->toBe($team->id);
});

it('rejects team belonging to a different tenant', function (): void {
    config(['app.url' => 'http://example.com']);

    $tenantA = Tenant::factory()->create(['slug' => 'tenant-a']);
    $tenantB = Tenant::factory()->create(['slug' => 'tenant-b']);

    $user = User::factory()->create();
    $tenantA->members()->attach($user, ['role' => 'owner']);

    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'owner']);

    $this->actingAs($user)
        ->withServerVariables(['HTTP_HOST' => 'tenant-a.example.com'])
        ->get("/teams/{$team->id}/dashboard")
        ->assertStatus(403);
});

it('rejects non-tenant-members from accessing team routes', function (): void {
    config(['app.url' => 'http://example.com']);

    $tenant = Tenant::factory()->create(['slug' => 'acme']);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withServerVariables(['HTTP_HOST' => 'acme.example.com'])
        ->get("/teams")
        ->assertStatus(403);
});
