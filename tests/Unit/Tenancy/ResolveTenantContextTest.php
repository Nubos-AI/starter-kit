<?php

declare(strict_types=1);

use App\Http\Middleware\ResolveTenantContext;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    AccessContext::forgetTenant();
    Config::set('modules.middleware.tenant', []);

    $this->tenant = ModelStub::make(Tenant::class, ['id' => ModelStub::ulid('tenant'), 'name' => 'Nubos']);

    /** @var callable(?User):Request */
    $this->requestFor = function (?User $user): Request {
        $request = Request::create('/engine/users', 'GET');
        $request->setUserResolver(static fn (): ?User => $user);

        return $request;
    };

    $this->reached = static fn (): Response => new Response('reached');

    $this->middleware = fn (): ResolveTenantContext => new ResolveTenantContext(app(Pipeline::class));
});

afterEach(function (): void {
    AccessContext::forgetTenant();
    Context::forgetHidden('tenant_id');
});

it('binds the tenant of the acting user for the whole request', function (): void {
    $user = ModelStub::make(User::class, [
        'id' => ModelStub::ulid('user'),
        'tenant_id' => $this->tenant->getKey(),
    ], ['tenant' => $this->tenant]);

    $response = ($this->middleware)()->handle(($this->requestFor)($user), $this->reached);

    expect($response->getContent())->toBe('reached')
        ->and(TenantContext::currentId())->toBe((string) $this->tenant->getKey())
        ->and(Context::getHidden('tenant_id'))->toBe($this->tenant->getKey());
});

it('leaves a user without a tenant unbound so every scope denies', function (): void {
    $user = ModelStub::make(User::class, ['id' => ModelStub::ulid('user'), 'tenant_id' => null]);

    ($this->middleware)()->handle(($this->requestFor)($user), $this->reached);

    expect(TenantContext::current())->toBeNull();
});

it('refuses a user whose tenant is gone', function (): void {
    $user = ModelStub::make(User::class, [
        'id' => ModelStub::ulid('user'),
        'tenant_id' => $this->tenant->getKey(),
    ], ['tenant' => null]);

    $request = ($this->requestFor)($user);

    expect(fn (): Response => ($this->middleware)()->handle($request, $this->reached))
        ->toThrow(HttpException::class);
});

it('leaves an unauthenticated request unbound', function (): void {
    $response = ($this->middleware)()->handle(($this->requestFor)(null), $this->reached);

    expect($response->getContent())->toBe('reached')
        ->and(TenantContext::current())->toBeNull();
});

it('falls back to the given key only while no tenant is bound', function (): void {
    expect(TenantContext::currentId('fallback'))->toBe('fallback');

    app()->instance('current_tenant', $this->tenant);

    expect(TenantContext::currentId('fallback'))->toBe((string) $this->tenant->getKey());
});
