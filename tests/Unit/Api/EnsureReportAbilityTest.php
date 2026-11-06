<?php

declare(strict_types=1);

use App\Http\Middleware\Api\EnsureReportAbility;
use App\Models\User;
use App\Support\Api\ApiAbilityMap;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Laravel\Sanctum\Exceptions\MissingAbilityException;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\TransientToken;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->middleware = new EnsureReportAbility(new ApiAbilityMap);
    $this->reportId = ModelStub::ulid('report');

    /** @var callable(?list<string>, string, string):Request */
    $this->requestWith = function (?array $abilities, string $uri = 'api/v1/reports/{report}', string $parameter = 'report'): Request {
        $identifier = $parameter === 'report' ? $this->reportId : ModelStub::ulid('goal');
        $path = str_replace('{'.$parameter.'}', $identifier, $uri);

        $request = Request::create('/'.$path, 'GET');

        $user = null;

        if ($abilities !== null) {
            $user = AccessContext::user($this->tenant);
            $token = ModelStub::make(PersonalAccessToken::class, ['id' => 7]);
            $token->abilities = $abilities;
            $user->withAccessToken($token);
        }

        $request->setUserResolver(static fn (): ?User => $user);

        $route = new Route('GET', $uri, []);
        $route->bind($request);
        $request->setRouteResolver(static fn (): Route => $route);

        return $request;
    };

    $this->reached = static fn (): Response => new Response('reached');
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    AccessContext::forgetTenant();
});

it('rejects a caller without a personal access token before it ever looks up the report', function (): void {
    $withoutUser = ($this->requestWith)(null);

    $withTransient = ($this->requestWith)([]);
    $withTransient->user()->withAccessToken(new TransientToken);

    $reachedTheDatabase = QueryShape::attemptedBy(function () use ($withoutUser): void {
        try {
            $this->middleware->handle($withoutUser, $this->reached);
        } catch (AuthenticationException) {
            return;
        }
    });

    expect(fn (): Response => $this->middleware->handle($withoutUser, $this->reached))
        ->toThrow(AuthenticationException::class)
        ->and(fn (): Response => $this->middleware->handle($withTransient, $this->reached))
        ->toThrow(AuthenticationException::class)
        ->and($reachedTheDatabase)->toBeNull();
});

it('refuses a route that names neither a report nor a goal', function (): void {
    $request = ($this->requestWith)(['records:read'], 'api/v1/dashboards', 'report');

    expect(fn (): Response => $this->middleware->handle($request, $this->reached))
        ->toThrow(LogicException::class);
});

it('looks the report up inside the tenant of the caller and never by id alone', function (): void {
    $request = ($this->requestWith)(['records:read']);

    $shape = QueryShape::attemptedBy(fn (): Response => $this->middleware->handle($request, $this->reached));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('reports'))->toBeTrue()
        ->and($shape->isScopedToTenant('reports', (string) $this->tenant->getKey()))->toBeTrue()
        ->and($shape->isKeyedTo('reports', $this->reportId))->toBeTrue();
});

it('looks a goal up inside the tenant of the caller as well', function (): void {
    $request = ($this->requestWith)(['records:read'], 'api/v1/goals/{goal}', 'goal');

    $shape = QueryShape::attemptedBy(fn (): Response => $this->middleware->handle($request, $this->reached));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('goals'))->toBeTrue()
        ->and($shape->isScopedToTenant('goals', (string) $this->tenant->getKey()))->toBeTrue();
});

it('refuses a token without read access on the object type of the report and never reaches the route', function (array $abilities, bool $isReached): void {
    StaticQueryConnection::install(fn (string $sql): array => match (true) {
        str_contains($sql, 'from "reports"') => [[
            'id' => $this->reportId,
            'tenant_id' => (string) $this->tenant->getKey(),
            'object_type_id' => ModelStub::ulid('report-object-type'),
        ]],
        str_contains($sql, 'from "object_types"') => [[
            'id' => ModelStub::ulid('report-object-type'),
            'tenant_id' => (string) $this->tenant->getKey(),
            'slug' => 'deals',
        ]],
        default => [],
    });

    $request = ($this->requestWith)($abilities);
    $reached = new ArrayObject;
    $next = static function () use ($reached): Response {
        $reached[] = true;

        return new Response('reached');
    };

    $outcome = $isReached
        ? $this->middleware->handle($request, $next)->getContent()
        : (fn (): Response => $this->middleware->handle($request, $next));

    if ($isReached) {
        expect($outcome)->toBe('reached');
    } else {
        expect($outcome)->toThrow(MissingAbilityException::class);
    }

    expect($reached->count())->toBe($isReached ? 1 : 0);
})->with([
    'read access on another object type only' => [['contacts:read'], false],
    'write access on the object type of the report' => [['deals:write'], false],
    'read access on the object type of the report' => [['deals:read'], true],
]);

it('answers a report of another tenant or no report at all as not found and never reaches the route', function (): void {
    StaticQueryConnection::install(static fn (): array => []);

    $request = ($this->requestWith)(['records:read']);
    $reached = new ArrayObject;

    expect(fn (): Response => $this->middleware->handle($request, static function () use ($reached): Response {
        $reached[] = true;

        return new Response('reached');
    }))->toThrow(ModelNotFoundException::class)
        ->and($reached->count())->toBe(0);
});
