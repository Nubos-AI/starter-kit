<?php

declare(strict_types=1);

use App\Actions\Tenants\CreateTenantAction;
use App\Models\User;

it('identifies tenant from subdomain', function (): void {
    $user = User::factory()->create();

    $action = app(CreateTenantAction::class);
    $action->execute($user, ['name' => 'Acme', 'slug' => 'acme']);

    $this->actingAs($user)
        ->get('/dashboard', ['HTTP_HOST' => 'acme.' . config('app.domain')])
        ->assertOk();
});

it('returns 404 for unknown subdomain', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/dashboard', ['HTTP_HOST' => 'unknown.' . config('app.domain')])
        ->assertNotFound();
});

it('returns 403 for non-member', function (): void {
    $owner = User::factory()->create();
    $other = User::factory()->create();

    $action = app(CreateTenantAction::class);
    $action->execute($owner, ['name' => 'Acme', 'slug' => 'acme']);

    $this->actingAs($other)
        ->get('/dashboard', ['HTTP_HOST' => 'acme.' . config('app.domain')])
        ->assertForbidden();
});
