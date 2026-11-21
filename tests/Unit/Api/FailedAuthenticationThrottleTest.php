<?php

declare(strict_types=1);

use App\Http\Middleware\Api\ThrottleApiRequests;
use App\Http\Middleware\Api\ThrottleFailedAuthentication;
use App\Http\Middleware\Scim\AuthenticateScimToken;
use App\Models\ScimToken;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter as RateLimiterFacade;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\ModelStub;
use Tests\Support\RouteShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    config()->set('api.rate_limit.failedAuthenticationsPerMinute', 3);

    $this->middleware = new ThrottleFailedAuthentication(new RateLimiter(Cache::store('array')));

    /** @var callable(string, string):Request */
    $this->requestFrom = static fn (string $ip, string $bearer = '17|made-up'): Request => Request::create('/api/v1/companies', 'GET', [], [], [], [
        'REMOTE_ADDR' => $ip,
        'HTTP_AUTHORIZATION' => "Bearer {$bearer}",
    ]);

    /** @var callable(Request, int):Response */
    $this->send = fn (Request $request, int $status): Response => $this->middleware->handle(
        $request,
        static fn (): JsonResponse => new JsonResponse([], $status),
        'api',
    );
});

it('runs the failed authentication throttle of the api before the token is authenticated', function (): void {
    $route = RouteShape::matching('GET', 'api/v1/{typeSlug}');

    expect($route->runsBefore(ThrottleFailedAuthentication::class.':api', Authenticate::class.':sanctum'))->toBeTrue()
        ->and($route->runsBefore(Authenticate::class.':sanctum', ThrottleApiRequests::class.':api'))->toBeTrue();
});

it('runs the failed authentication throttle of scim before the token and the token bucket after it', function (): void {
    $route = RouteShape::named('scim.users.index');

    expect($route->runsBefore(ThrottleFailedAuthentication::class.':scim', AuthenticateScimToken::class))->toBeTrue()
        ->and($route->runsBefore(AuthenticateScimToken::class, ThrottleApiRequests::class.':scim'))->toBeTrue();
});

it('answers every further rejected token of an address with too many requests once it failed too often', function (): void {
    foreach (['17|first', '18|second', '19|third'] as $bearer) {
        expect(($this->send)(($this->requestFrom)('203.0.113.9', $bearer), 401)->getStatusCode())->toBe(401);
    }

    expect(fn (): Response => ($this->send)(($this->requestFrom)('203.0.113.9', '20|fourth'), 401))
        ->toThrow(ThrottleRequestsException::class);
});

it('never throttles a valid token although its address shares the bucket of a failing neighbour', function (): void {
    foreach (['17|first', '18|second', '19|third'] as $bearer) {
        ($this->send)(($this->requestFrom)('203.0.113.9', $bearer), 401);
    }

    $reached = false;

    $response = $this->middleware->handle(($this->requestFrom)('203.0.113.9', '42|valid'), function () use (&$reached): JsonResponse {
        $reached = true;

        return new JsonResponse([], 200);
    }, 'api');

    expect($reached)->toBeTrue()
        ->and($response->getStatusCode())->toBe(200);
});

it('counts only rejected authentications and keeps other addresses apart', function (): void {
    foreach (range(1, 5) as $attempt) {
        expect(($this->send)(($this->requestFrom)('203.0.113.9'), 200)->getStatusCode())->toBe(200);
    }

    foreach (range(1, 3) as $attempt) {
        ($this->send)(($this->requestFrom)('198.51.100.7'), 401);
    }

    expect(($this->send)(($this->requestFrom)('203.0.113.9'), 200)->getStatusCode())->toBe(200);
});

it('keys the scim bucket by the authenticated token and an unauthenticated request by its address', function (): void {
    config()->set('sso.scim.requests_per_minute', 120);

    $authenticated = Request::create('/scim/v2/Users', 'GET', [], [], [], ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_AUTHORIZATION' => 'Bearer scim_made_up']);
    $authenticated->attributes->set('scim_token', ModelStub::make(ScimToken::class, ['id' => ModelStub::ulid('scim-token')]));

    $anonymous = Request::create('/scim/v2/Users', 'GET', [], [], [], ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_AUTHORIZATION' => 'Bearer scim_another_made_up']);

    /** @var Limit $tokenLimit */
    $tokenLimit = RateLimiterFacade::limiter('scim')($authenticated);
    /** @var Limit $addressLimit */
    $addressLimit = RateLimiterFacade::limiter('scim')($anonymous);

    expect($tokenLimit->key)->toBe('scim-token:'.ModelStub::ulid('scim-token'))
        ->and($addressLimit->key)->toBe('scim-ip:203.0.113.9');
});
