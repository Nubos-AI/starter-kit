<?php

declare(strict_types=1);

use App\Http\Middleware\Authorization\EnsurePermission;
use App\Models\CustomRecord;
use App\Models\ObjectType;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();

    $this->objectType = ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('companies'),
        'tenant_id' => $this->tenant->getKey(),
        'slug' => 'companies',
    ]);

    /** @var callable(?User, array<string, mixed>):Request */
    $this->requestFor = function (?User $user, array $parameters = []): Request {
        $request = Request::create('/probe', 'GET');
        $request->setUserResolver(static fn (): ?User => $user);

        $route = new Route('GET', 'probe', []);
        $route->bind($request);

        foreach ($parameters as $name => $value) {
            $route->setParameter($name, $value);
        }

        $request->setRouteResolver(static fn (): Route => $route);

        return $request;
    };

    $this->reached = static fn (): Response => new Response('reached');
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('lets a literal ability through when the actor holds the permission', function (): void {
    AccessContext::grant('roles.view');

    $response = (new EnsurePermission)->handle(
        ($this->requestFor)(AccessContext::user($this->tenant)),
        $this->reached,
        'roles.view',
    );

    expect($response->getContent())->toBe('reached');
});

it('rejects a literal ability the actor does not hold', function (): void {
    $resolver = AccessContext::grant('roles.create');

    $request = ($this->requestFor)(AccessContext::user($this->tenant));

    expect(fn (): Response => (new EnsurePermission)->handle($request, $this->reached, 'roles.view'))
        ->toThrow(AuthorizationException::class)
        ->and($resolver->askedFor)->toBe(['roles.view']);
});

it('resolves a placeholder against the bound object type', function (): void {
    $resolver = AccessContext::grant('companies.view');

    $response = (new EnsurePermission)->handle(
        ($this->requestFor)(AccessContext::user($this->tenant), ['objectType' => $this->objectType]),
        $this->reached,
        '{objectType}.view',
    );

    expect($response->getContent())->toBe('reached')
        ->and($resolver->askedFor)->toBe(['companies.view']);
});

it('rejects a placeholder ability the actor holds only for another object type', function (): void {
    AccessContext::grant('contacts.view');

    $request = ($this->requestFor)(AccessContext::user($this->tenant), ['objectType' => $this->objectType]);

    expect(fn (): Response => (new EnsurePermission)->handle($request, $this->reached, '{objectType}.view'))
        ->toThrow(AuthorizationException::class);
});

it('resolves a placeholder through a bound record to its object type', function (): void {
    $resolver = AccessContext::grant('companies.audit-view');

    $record = ModelStub::make(CustomRecord::class, [
        'id' => ModelStub::ulid('company-record'),
        'tenant_id' => $this->tenant->getKey(),
        'object_type_id' => $this->objectType->getKey(),
    ], ['objectType' => $this->objectType]);

    $response = (new EnsurePermission)->handle(
        ($this->requestFor)(AccessContext::user($this->tenant), ['record' => $record]),
        $this->reached,
        '{record}.audit-view',
    );

    expect($response->getContent())->toBe('reached')
        ->and($resolver->askedFor)->toBe(['companies.audit-view']);
});

it('fails closed when the route does not bind the placeholder parameter', function (): void {
    AccessContext::grant('companies.view');

    $request = ($this->requestFor)(AccessContext::user($this->tenant), ['objectType' => 'companies']);

    expect(fn (): Response => (new EnsurePermission)->handle($request, $this->reached, '{objectType}.view'))
        ->toThrow(LogicException::class, 'does not resolve to an object type');
});

it('rejects an unauthenticated request before resolving any ability', function (): void {
    $resolver = AccessContext::grant('roles.view');

    $request = ($this->requestFor)(null);

    expect(fn (): Response => (new EnsurePermission)->handle($request, $this->reached, 'roles.view'))
        ->toThrow(AuthorizationException::class)
        ->and($resolver->askedFor)->toBeEmpty();
});
