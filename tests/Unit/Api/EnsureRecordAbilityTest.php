<?php

declare(strict_types=1);

use App\Enums\Api\ApiAccessLevel;
use App\Http\Middleware\Api\EnsureRecordAbility;
use App\Models\User;
use App\Support\Api\ApiAbilityMap;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Laravel\Sanctum\Exceptions\MissingAbilityException;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\TransientToken;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->middleware = new EnsureRecordAbility(new ApiAbilityMap);

    /** @var callable(?list<string>, ?string):Request */
    $this->requestWith = function (?array $abilities, ?string $typeSlug = null): Request {
        $request = Request::create($typeSlug === null ? '/api/v1/search' : "/api/v1/{$typeSlug}", 'GET');

        $user = null;

        if ($abilities !== null) {
            $user = AccessContext::user($this->tenant);
            $token = ModelStub::make(PersonalAccessToken::class, ['id' => 7]);
            $token->abilities = $abilities;
            $user->withAccessToken($token);
        }

        $request->setUserResolver(static fn (): ?User => $user);

        $route = new Route('GET', $typeSlug === null ? 'api/v1/search' : 'api/v1/{typeSlug}', []);
        $route->bind($request);

        $request->setRouteResolver(static fn (): Route => $route);

        return $request;
    };

    $this->reached = static fn (): Response => new Response('reached');
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('lets the ability for the bound object type slug through', function (): void {
    $request = ($this->requestWith)(['companies:read'], 'companies');

    $response = $this->middleware->handle($request, $this->reached, ApiAccessLevel::Read->value);

    expect($response->getContent())->toBe('reached');
});

it('refuses a token scoped to another object type on a slug bound route', function (): void {
    $request = ($this->requestWith)(['contacts:read'], 'companies');

    expect(fn (): Response => $this->middleware->handle($request, $this->reached, ApiAccessLevel::Read->value))
        ->toThrow(MissingAbilityException::class);
});

it('names both the global and the type specific ability when it refuses a slug bound route', function (): void {
    $request = ($this->requestWith)([], 'companies');

    try {
        $this->middleware->handle($request, $this->reached, ApiAccessLevel::Write->value);
    } catch (MissingAbilityException $exception) {
        expect($exception->abilities())->toBe(['records:write', 'companies:write']);

        return;
    }

    $this->fail('the middleware admitted a token without any ability');
});

it('never lets a read ability open a write verb', function (): void {
    $request = ($this->requestWith)(['companies:read'], 'companies');

    expect(fn (): Response => $this->middleware->handle($request, $this->reached, ApiAccessLevel::Write->value))
        ->toThrow(MissingAbilityException::class);
});

it('refuses a slug less route to a token that carries no read ability at all', function (): void {
    $request = ($this->requestWith)(['companies:write']);

    expect(fn (): Response => $this->middleware->handle($request, $this->reached, ApiAccessLevel::Read->value))
        ->toThrow(MissingAbilityException::class);
});

it('names only the global ability when a slug less route is refused', function (): void {
    $request = ($this->requestWith)([]);

    try {
        $this->middleware->handle($request, $this->reached, ApiAccessLevel::Read->value);
    } catch (MissingAbilityException $exception) {
        expect($exception->abilities())->toBe(['records:read']);

        return;
    }

    $this->fail('the middleware admitted a token without any ability');
});

it('ignores a route parameter that is not a slug string and falls back to the slug less check', function (): void {
    $request = ($this->requestWith)(['contacts:read'], 'companies');
    $request->route()->setParameter('typeSlug', ['companies']);

    $response = $this->middleware->handle($request, $this->reached, ApiAccessLevel::Read->value);

    expect($response->getContent())->toBe('reached');
});

it('rejects a caller without a personal access token before any ability is read', function (): void {
    $withoutUser = ($this->requestWith)(null);

    $withTransient = ($this->requestWith)([]);
    $user = $withTransient->user();
    $user->withAccessToken(new TransientToken);

    expect(fn (): Response => $this->middleware->handle($withoutUser, $this->reached, ApiAccessLevel::Read->value))
        ->toThrow(AuthenticationException::class)
        ->and(fn (): Response => $this->middleware->handle($withTransient, $this->reached, ApiAccessLevel::Read->value))
        ->toThrow(AuthenticationException::class);
});
