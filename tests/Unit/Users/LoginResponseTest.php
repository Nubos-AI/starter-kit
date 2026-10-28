<?php

declare(strict_types=1);

use App\Enums\Preferences\GlobalPreference;
use App\Enums\Preferences\PreferenceScope;
use App\Http\Responses\Auth\LoginResponse;
use App\Models\User;
use App\Support\Authorization\CurrentTeamResolver;
use App\Support\Preferences\UserPreferenceResolver;
use App\Support\Tenancy\TenantBinder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();

    $this->preferences = Mockery::mock(UserPreferenceResolver::class);
    $this->teams = Mockery::mock(CurrentTeamResolver::class);
    $this->binder = Mockery::mock(TenantBinder::class);

    $this->response = fn (): LoginResponse => new LoginResponse(
        $this->preferences,
        $this->teams,
        $this->binder,
    );

    /** @var callable(?User):Request */
    $this->requestFor = function (?User $user): Request {
        $request = Request::create('/login', 'POST');
        $request->setLaravelSession(new Store('test', new ArraySessionHandler(60)));
        $request->setUserResolver(static fn (): ?User => $user);

        return $request;
    };

    $this->teamKey = (string) ModelStub::ulid('sales-team');
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('sends a user without a tenant to the configured home', function (): void {
    $user = AccessContext::user($this->tenant, ['tenant_id' => null]);

    $response = ($this->response)()->toResponse(($this->requestFor)($user));

    expect($response)->toBeInstanceOf(RedirectResponse::class)
        ->and($response->getTargetUrl())->toContain((string) config('fortify.home'));
});

it('sends a user whose tenant is gone to the configured home instead of failing the login', function (): void {
    $user = AccessContext::user($this->tenant);

    $this->binder->shouldReceive('runIfKnown')->once()->andReturnNull();

    $response = ($this->response)()->toResponse(($this->requestFor)($user));

    expect($response->getTargetUrl())->toContain((string) config('fortify.home'));
});

it('sends a user without any team to their profile', function (): void {
    $user = AccessContext::user($this->tenant);

    $this->binder->shouldReceive('runIfKnown')->once()->andReturnUsing(static fn (string $id, callable $work): mixed => $work());
    $this->teams->shouldReceive('resolveKey')->once()->with($user)->andReturnNull();

    $response = ($this->response)()->toResponse(($this->requestFor)($user));

    expect($response->getTargetUrl())->toContain(route('profile.edit', [], false));
});

it('sends a user without a start object type to the dashboard of their team', function (): void {
    $user = AccessContext::user($this->tenant);

    $this->binder->shouldReceive('runIfKnown')->once()->andReturnUsing(static fn (string $id, callable $work): mixed => $work());
    $this->teams->shouldReceive('resolveKey')->once()->andReturn($this->teamKey);
    $this->preferences->shouldReceive('document')->once()->andReturn([]);

    $response = ($this->response)()->toResponse(($this->requestFor)($user));

    expect($response->getTargetUrl())->toContain($this->teamKey);
});

it('looks a stored start object type up inside the bound tenant', function (): void {
    $user = AccessContext::user($this->tenant);
    $startId = (string) ModelStub::ulid('companies');

    $this->binder->shouldReceive('runIfKnown')->andReturnUsing(static fn (string $id, callable $work): mixed => $work());
    $this->teams->shouldReceive('resolveKey')->andReturn($this->teamKey);
    $this->preferences->shouldReceive('document')->andReturn([
        PreferenceScope::Settings->value => [GlobalPreference::StartObjectTypeId->value => $startId],
    ]);

    $request = ($this->requestFor)($user);

    $shape = QueryShape::attemptedBy(fn (): Response => ($this->response)()->toResponse($request));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('object_types'))->toBeTrue()
        ->and($shape->isKeyedTo('object_types', $startId))->toBeTrue()
        ->and($shape->isScopedToTenant('object_types', (string) $this->tenant->getKey()))->toBeTrue();
});

it('answers an api login with an empty no content response', function (): void {
    $user = AccessContext::user($this->tenant, ['tenant_id' => null]);

    $request = ($this->requestFor)($user);
    $request->headers->set('Accept', 'application/json');

    expect(($this->response)()->toResponse($request)->getStatusCode())->toBe(204);
});

it('honours the page the user was heading for before the login', function (): void {
    $user = AccessContext::user($this->tenant, ['tenant_id' => null]);

    $request = ($this->requestFor)($user);
    $request->session()->put('url.intended', '/engine/users');

    expect(($this->response)()->toResponse($request)->getTargetUrl())->toContain('/engine/users');
});
