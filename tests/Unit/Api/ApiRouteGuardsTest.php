<?php

declare(strict_types=1);

use App\Enums\Api\ApiAccessLevel;
use App\Http\Middleware\Api\EnforceIdempotencyKey;
use App\Http\Middleware\Api\EnsureRecordAbility;
use App\Http\Middleware\Api\EnsureReportAbility;
use App\Http\Middleware\Api\RejectTransientToken;
use App\Http\Middleware\Api\ResolveApiContext;
use App\Http\Middleware\Api\ThrottleApiRequests;
use App\Http\Middleware\Authorization\EnforceRecordAccessRules;
use App\Http\Middleware\ResolveTenantContext;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Router;
use Tests\Support\RouteShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    /** @var callable():list<string> */
    $this->apiRouteSignatures = static function (): array {
        $signatures = [];

        foreach (app(Router::class)->getRoutes()->getRoutes() as $route) {
            if (!str_starts_with((string) $route->getActionName(), 'App\\Http\\Controllers\\Api\\V1\\')) {
                continue;
            }

            foreach ($route->methods() as $method) {
                if ($method === 'HEAD') {
                    continue;
                }

                $signatures[] = $method.' '.$route->uri();
            }
        }

        sort($signatures);

        return $signatures;
    };
});

it('guards every record read verb with the read ability and every write verb with the write ability', function (string $method, string $uri, ApiAccessLevel $level): void {
    $route = RouteShape::matching($method, $uri);

    expect($route->hasDeclaredMiddleware(EnsureRecordAbility::class.':'.$level->value))->toBeTrue()
        ->and($route->handledBy())->toContain('RecordsController');
})->with([
    ['GET', 'api/v1/{typeSlug}', ApiAccessLevel::Read],
    ['GET', 'api/v1/{typeSlug}/{record}', ApiAccessLevel::Read],
    ['POST', 'api/v1/{typeSlug}', ApiAccessLevel::Write],
    ['PATCH', 'api/v1/{typeSlug}/{record}', ApiAccessLevel::Write],
    ['DELETE', 'api/v1/{typeSlug}/{record}', ApiAccessLevel::Write],
]);

it('narrows a report or goal detail beyond the slug less read gate with the report ability', function (string $uri): void {
    $route = RouteShape::matching('GET', $uri);

    expect($route->hasDeclaredMiddleware(EnsureReportAbility::class))->toBeTrue()
        ->and($route->hasDeclaredMiddleware(EnsureRecordAbility::class.':read'))->toBeTrue();
})->with([
    'api/v1/reports/{report}',
    'api/v1/reports/{report}/result',
    'api/v1/goals/{goal}',
]);

it('lets the search and the collection endpoints pass the slug less read gate and narrows them in the controller', function (string $uri): void {
    $route = RouteShape::matching('GET', $uri);

    expect($route->hasDeclaredMiddleware(EnsureRecordAbility::class.':read'))->toBeTrue()
        ->and($route->hasDeclaredMiddleware(EnsureReportAbility::class))->toBeFalse();
})->with([
    'api/v1/search',
    'api/v1/reports',
    'api/v1/goals',
]);

it('claims an idempotency key on the creating verb and on no other', function (): void {
    expect(RouteShape::matching('POST', 'api/v1/{typeSlug}')->resolvedMiddleware())
        ->toContain(EnforceIdempotencyKey::class)
        ->and(RouteShape::matching('PATCH', 'api/v1/{typeSlug}/{record}')->resolvedMiddleware())
        ->not->toContain(EnforceIdempotencyKey::class)
        ->and(RouteShape::matching('GET', 'api/v1/{typeSlug}')->resolvedMiddleware())
        ->not->toContain(EnforceIdempotencyKey::class);
});

it('authenticates, rejects a session bound token and resolves the api context before any route runs', function (): void {
    $route = RouteShape::matching('GET', 'api/v1/{typeSlug}');
    $authenticate = Authenticate::class.':sanctum';

    expect($route->resolvedMiddleware())->toContain($authenticate)
        ->and($route->runsBefore($authenticate, RejectTransientToken::class))->toBeTrue()
        ->and($route->runsBefore(RejectTransientToken::class, ResolveApiContext::class))->toBeTrue()
        ->and($route->runsBefore(ResolveApiContext::class, ResolveTenantContext::class))->toBeTrue()
        ->and($route->runsBefore($authenticate, EnsureRecordAbility::class.':read'))->toBeTrue();
});

it('throttles the api before the ability of a token is ever read', function (): void {
    $route = RouteShape::matching('GET', 'api/v1/{typeSlug}');

    expect($route->runsBefore(ThrottleApiRequests::class.':api', EnsureRecordAbility::class.':read'))->toBeTrue();
});

it('enforces the row access rules on every record route', function (string $method, string $uri): void {
    expect(RouteShape::matching($method, $uri)->resolvedMiddleware())
        ->toContain(EnforceRecordAccessRules::class);
})->with([
    ['GET', 'api/v1/{typeSlug}'],
    ['GET', 'api/v1/{typeSlug}/{record}'],
    ['PATCH', 'api/v1/{typeSlug}/{record}'],
    ['DELETE', 'api/v1/{typeSlug}/{record}'],
]);

it('never resolves an api route parameter through implicit route model binding', function (string $method, string $uri): void {
    expect(RouteShape::matching($method, $uri)->resolvedMiddleware())
        ->not->toContain(SubstituteBindings::class);
})->with([
    ['GET', 'api/v1/{typeSlug}/{record}'],
    ['PATCH', 'api/v1/{typeSlug}/{record}'],
    ['DELETE', 'api/v1/{typeSlug}/{record}'],
    ['GET', 'api/v1/reports/{report}'],
    ['GET', 'api/v1/goals/{goal}'],
]);

it('exposes the caller identity endpoint without any object type ability', function (): void {
    $route = RouteShape::matching('GET', 'api/v1/whoami');

    expect($route->declaredMiddleware())->not->toContain(EnsureRecordAbility::class.':read')
        ->and($route->resolvedMiddleware())->toContain(RejectTransientToken::class);
});

it('registers no writing verb on the report and goal endpoints', function (): void {
    $writing = array_values(array_filter(
        ($this->apiRouteSignatures)(),
        static fn (string $signature): bool => !str_starts_with($signature, 'GET ')
            && (str_contains($signature, '/reports') || str_contains($signature, '/goals')),
    ));

    expect($writing)->toBe([]);
});

it('registers exactly the agreed api surface for the application controllers and nothing beyond it', function (): void {
    expect(($this->apiRouteSignatures)())->toBe([
        'DELETE api/v1/{typeSlug}/{record}',
        'GET api/v1/goals',
        'GET api/v1/goals/{goal}',
        'GET api/v1/reports',
        'GET api/v1/reports/{report}',
        'GET api/v1/reports/{report}/result',
        'GET api/v1/search',
        'GET api/v1/whoami',
        'GET api/v1/{typeSlug}',
        'GET api/v1/{typeSlug}/{record}',
        'PATCH api/v1/{typeSlug}/{record}',
        'POST api/v1/{typeSlug}',
    ]);
});
