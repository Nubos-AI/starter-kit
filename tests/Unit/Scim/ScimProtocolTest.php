<?php

declare(strict_types=1);

use App\Enums\Audit\ActorType;
use App\Enums\Scim\ScimErrorType;
use App\Exceptions\Scim\ScimException;
use App\Http\Controllers\Scim\ScimDiscoveryController;
use App\Http\Middleware\Api\ThrottleApiRequests;
use App\Http\Middleware\ResolveTenantContext;
use App\Http\Middleware\Scim\AuthenticateScimToken;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Scim\ScimAccessGuard;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\MiddlewarePipeline;
use Tests\Support\ModelStub;
use Tests\Support\RouteShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    app()->setLocale('de');
    Carbon::setTestNow(Carbon::parse('2026-10-30 12:00:00', 'UTC'));

    $this->plainToken = 'scim_'.str_repeat('a', 48);
    $this->tenantId = ModelStub::ulid('acme');
    $this->tokenRow = ['id' => ModelStub::ulid('token'), 'tenant_id' => $this->tenantId, 'token_hash' => hash('sha256', $this->plainToken), 'last_used_at' => null];
    $this->connection = StaticQueryConnection::install(
        function (string $sql, array $bindings): array {
            if (str_contains($sql, 'from "scim_tokens"')) {
                return in_array($this->tokenRow['token_hash'], $bindings, true) ? [$this->tokenRow] : [];
            }

            if (str_contains($sql, 'from "tenants"')) {
                return [['id' => $this->tenantId, 'name' => 'Acme', 'slug' => 'acme']];
            }

            return [];
        },
        static fn (): int => 1,
    );

    $this->creator = ModelStub::make(User::class, ['id' => ModelStub::ulid('helpdesk'), 'tenant_id' => $this->tenantId], [
        'tenant' => ModelStub::make(Tenant::class, ['id' => $this->tenantId, 'name' => 'Acme', 'slug' => 'acme']),
    ]);
    $this->accessGuard = Mockery::mock(ScimAccessGuard::class);
    $this->accessGuard->shouldReceive('entitledCreator')->byDefault()->andReturn($this->creator);
    app()->instance(ScimAccessGuard::class, $this->accessGuard);

    $this->request = static function (?string $token, string $uri = '/scim/v2/Users'): Request {
        $request = Request::create($uri, 'GET');

        if ($token !== null) {
            $request->headers->set('Authorization', "Bearer {$token}");
        }

        return $request;
    };

    $this->rendered = static function (Throwable $throwable, string $uri = '/scim/v2/Users'): JsonResponse {
        $response = app(ExceptionHandler::class)->render(Request::create($uri, 'GET'), $throwable);

        expect($response)->toBeInstanceOf(JsonResponse::class);

        return $response;
    };
});

afterEach(function (): void {
    Carbon::setTestNow();
    StaticQueryConnection::uninstall();
    app()->forgetInstance('current_tenant');
    Mockery::close();
});

it('serves every scim endpoint under scim/v2 behind the scim token, a rate limit and without a session', function (string $name, string $method, string $uri): void {
    $route = RouteShape::named($name);

    expect($route->methods())->toContain($method)
        ->and($route->uri())->toBe($uri)
        ->and($route->resolvedMiddleware())->toContain(AuthenticateScimToken::class)
        ->and($route->runsBefore(AuthenticateScimToken::class, ThrottleApiRequests::class.':scim'))->toBeTrue()
        ->and($route->runsBefore(AuthenticateScimToken::class, ResolveTenantContext::class))->toBeTrue()
        ->and($route->resolvedMiddleware())->not->toContain('Illuminate\Session\Middleware\StartSession');
})->with([
    ['scim.service-provider-config', 'GET', 'scim/v2/ServiceProviderConfig'],
    ['scim.resource-types', 'GET', 'scim/v2/ResourceTypes'],
    ['scim.schemas', 'GET', 'scim/v2/Schemas'],
    ['scim.users.index', 'GET', 'scim/v2/Users'],
    ['scim.users.store', 'POST', 'scim/v2/Users'],
    ['scim.users.show', 'GET', 'scim/v2/Users/{user}'],
    ['scim.users.replace', 'PUT', 'scim/v2/Users/{user}'],
    ['scim.users.update', 'PATCH', 'scim/v2/Users/{user}'],
    ['scim.users.destroy', 'DELETE', 'scim/v2/Users/{user}'],
    ['scim.groups.index', 'GET', 'scim/v2/Groups'],
    ['scim.groups.store', 'POST', 'scim/v2/Groups'],
    ['scim.groups.show', 'GET', 'scim/v2/Groups/{group}'],
    ['scim.groups.replace', 'PUT', 'scim/v2/Groups/{group}'],
    ['scim.groups.update', 'PATCH', 'scim/v2/Groups/{group}'],
    ['scim.groups.destroy', 'DELETE', 'scim/v2/Groups/{group}'],
]);

it('refuses a request without a token, with a token of another scheme or with an unknown token', function (?string $token): void {
    $reached = false;

    try {
        MiddlewarePipeline::through([AuthenticateScimToken::class])->send(($this->request)($token), function () use (&$reached): JsonResponse {
            $reached = true;

            return new JsonResponse;
        });
    } catch (ScimException $exception) {
        expect($exception->status)->toBe(401)
            ->and($reached)->toBeFalse();

        return;
    }

    $this->fail('The request passed.');
})->with([
    'no token' => [null],
    'api token' => ['12|sanctum-token'],
    'unknown scim token' => ['scim_'.str_repeat('b', 48)],
]);

it('looks a token up by its hash across tenants and never by its plain value', function (): void {
    try {
        MiddlewarePipeline::through([AuthenticateScimToken::class])->send(($this->request)('scim_'.str_repeat('b', 48)), static fn (): JsonResponse => new JsonResponse);
    } catch (ScimException) {
    }

    $lookup = collect($this->connection->queries)->first(static fn (array $query): bool => str_contains($query['sql'], 'from "scim_tokens"'));

    expect($lookup['bindings'])->toContain(hash('sha256', 'scim_'.str_repeat('b', 48)))
        ->and($lookup['bindings'])->not->toContain('scim_'.str_repeat('b', 48))
        ->and($lookup['sql'])->not->toContain('1 = 0');
});

it('runs a request with a valid token inside the tenant of the token as the system actor and leaves no tenant behind', function (): void {
    $seen = [];

    $response = MiddlewarePipeline::through([AuthenticateScimToken::class])->send(($this->request)($this->plainToken), function (Request $request) use (&$seen): JsonResponse {
        $seen = [
            'tenant' => (string) app('current_tenant')->getKey(),
            'actor' => app('current_automation_actor'),
            'token' => (string) $request->attributes->get('scim_token')->getKey(),
        ];

        return new JsonResponse(['ok' => true]);
    });

    expect($response->getStatusCode())->toBe(200)
        ->and($seen['tenant'])->toBe($this->tenantId)
        ->and($seen['actor'])->toBe(['id' => ModelStub::ulid('token'), 'type' => ActorType::System])
        ->and($seen['token'])->toBe(ModelStub::ulid('token'))
        ->and(app()->bound('current_tenant'))->toBeFalse()
        ->and(app()->bound('current_automation_actor'))->toBeFalse()
        ->and(collect($this->connection->writtenStatements)->pluck('sql')->implode(' '))->toContain('update "scim_tokens" set "last_used_at" = ?');
});

it('refuses every request of a token whose creator is no longer entitled before it reaches a scim endpoint', function (string $uri): void {
    $reached = false;
    $this->accessGuard->shouldReceive('entitledCreator')->once()->andReturnNull();

    try {
        MiddlewarePipeline::through([AuthenticateScimToken::class])->send(($this->request)($this->plainToken, $uri), function () use (&$reached): JsonResponse {
            $reached = true;

            return new JsonResponse;
        });
    } catch (ScimException $exception) {
        expect($exception->status)->toBe(403)
            ->and($exception->getMessage())->toBe(__('i18n.backend.exceptions.scim.scim_exception.token_without_creator'))
            ->and($reached)->toBeFalse()
            ->and(app()->bound('current_tenant'))->toBeFalse();

        return;
    }

    $this->fail('The request passed.');
})->with([
    'read users' => ['/scim/v2/Users'],
    'read groups' => ['/scim/v2/Groups'],
]);

it('runs the module tenant middleware of a scim request as the token creator so a module can refuse it', function (): void {
    $seen = null;
    $reached = false;
    Config::set('modules.middleware.tenant', [function (Request $request, Closure $next) use (&$seen): never {
        $seen = (string) $request->user()?->getKey();

        throw new AccessDeniedHttpException('Customer suspended.');
    }]);

    expect(fn () => MiddlewarePipeline::forRoute('scim.users.store', [AuthenticateScimToken::class, ResolveTenantContext::class])
        ->send(($this->request)($this->plainToken), function () use (&$reached): JsonResponse {
            $reached = true;

            return new JsonResponse;
        }))->toThrow(AccessDeniedHttpException::class);

    expect($seen)->toBe(ModelStub::ulid('helpdesk'))
        ->and($reached)->toBeFalse()
        ->and(app()->bound('current_tenant'))->toBeFalse();
});

it('renders a scim failure in the error format of RFC 7644 with the scim media type', function (): void {
    $response = ($this->rendered)(ScimException::invalidFilter());

    expect($response->getStatusCode())->toBe(400)
        ->and($response->headers->get('Content-Type'))->toContain('application/scim+json')
        ->and($response->getData(true))->toBe([
            'schemas' => ['urn:ietf:params:scim:api:messages:2.0:Error'],
            'status' => '400',
            'scimType' => 'invalidFilter',
            'detail' => __('i18n.backend.exceptions.scim.scim_exception.invalid_filter'),
        ]);
});

it('maps framework failures on scim routes onto scim errors without leaking internals', function (Throwable $throwable, int $status, ?string $scimType): void {
    $body = ($this->rendered)($throwable)->getData(true);

    expect($body['status'])->toBe((string) $status)
        ->and($body['schemas'])->toBe(['urn:ietf:params:scim:api:messages:2.0:Error'])
        ->and($body['scimType'] ?? null)->toBe($scimType)
        ->and($body['detail'])->not->toContain('SQLSTATE')
        ->and($body)->not->toHaveKey('trace');
})->with([
    'validation' => [fn (): ValidationException => ValidationException::withMessages(['email' => 'Ungültig.']), 400, ScimErrorType::InvalidValue->value],
    'missing model' => [fn (): ModelNotFoundException => new ModelNotFoundException, 404, null],
    'unauthenticated' => [fn (): AuthenticationException => new AuthenticationException, 401, null],
    'throttled' => [fn (): ThrottleRequestsException => new ThrottleRequestsException, 429, null],
    'unexpected' => [fn (): RuntimeException => new RuntimeException('SQLSTATE[42P01] secret internals'), 500, null],
]);

it('describes the supported protocol features in the service provider configuration', function (): void {
    $response = app(ScimDiscoveryController::class)->serviceProviderConfig();
    $body = $response->getData(true);

    expect($response->headers->get('Content-Type'))->toContain('application/scim+json')
        ->and($body['schemas'])->toBe(['urn:ietf:params:scim:schemas:core:2.0:ServiceProviderConfig'])
        ->and($body['patch']['supported'])->toBeTrue()
        ->and($body['filter'])->toBe(['supported' => true, 'maxResults' => 200])
        ->and($body['bulk']['supported'])->toBeFalse()
        ->and($body['etag']['supported'])->toBeTrue()
        ->and($body['changePassword']['supported'])->toBeFalse()
        ->and($body['authenticationSchemes'][0]['type'])->toBe('oauthbearertoken');
});

it('lists the user and group resource types and their schemas', function (): void {
    $types = app(ScimDiscoveryController::class)->resourceTypes()->getData(true);
    $schemas = app(ScimDiscoveryController::class)->schemas()->getData(true);

    expect($types['totalResults'])->toBe(2)
        ->and(array_column($types['Resources'], 'endpoint'))->toBe(['/Users', '/Groups'])
        ->and(array_column($schemas['Resources'], 'id'))->toBe(['urn:ietf:params:scim:schemas:core:2.0:User', 'urn:ietf:params:scim:schemas:core:2.0:Group']);
});
