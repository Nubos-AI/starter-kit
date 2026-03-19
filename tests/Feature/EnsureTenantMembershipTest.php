<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureTenantMembership;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;

uses(RefreshDatabase::class);

it('allows access for tenant members', function (): void {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    $tenant->members()->attach($user, ['role' => 'member']);

    app()->instance('currentTenant', $tenant);

    $request = Request::create('/');
    $request->setUserResolver(fn () => $user);

    $middleware = new EnsureTenantMembership();
    $response = $middleware->handle($request, fn () => response('ok'));

    expect($response->getContent())->toBe('ok');
});

it('denies access for non-members with 403', function (): void {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();

    app()->instance('currentTenant', $tenant);

    $request = Request::create('/');
    $request->setUserResolver(fn () => $user);

    $middleware = new EnsureTenantMembership();
    $middleware->handle($request, fn () => response('ok'));
})->throws(\Symfony\Component\HttpKernel\Exception\HttpException::class);

it('allows guest requests to pass through', function (): void {
    $tenant = Tenant::factory()->create();

    app()->instance('currentTenant', $tenant);

    $request = Request::create('/');
    $request->setUserResolver(fn () => null);

    $middleware = new EnsureTenantMembership();
    $response = $middleware->handle($request, fn () => response('ok'));

    expect($response->getContent())->toBe('ok');
});

it('aborts when no tenant context is bound', function (): void {
    $user = User::factory()->create();

    $request = Request::create('/');
    $request->setUserResolver(fn () => $user);

    $middleware = new EnsureTenantMembership();
    $middleware->handle($request, fn () => response('ok'));
})->throws(\Symfony\Component\HttpKernel\Exception\HttpException::class);
