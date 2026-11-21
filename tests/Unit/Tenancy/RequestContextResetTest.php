<?php

declare(strict_types=1);

use App\Http\Middleware\ResolveTeamContext;
use App\Http\Middleware\ResolveTenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

it('forgets a stale current_tenant binding for a request without a tenant user', function (): void {
    app()->instance('current_tenant', new stdClass);

    app(ResolveTenantContext::class)->handle(
        Request::create('/dashboard', 'GET'),
        static fn (Request $request): Response => new Response('ok'),
    );

    expect(app()->bound('current_tenant'))->toBeFalse();
});

it('forgets a stale current_team binding for a request without a team segment', function (): void {
    app()->instance('current_team', new stdClass);

    app(ResolveTeamContext::class)->handle(
        Request::create('/dashboard', 'GET'),
        static fn (Request $request): Response => new Response('ok'),
    );

    expect(app()->bound('current_team'))->toBeFalse();
});
