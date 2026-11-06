<?php

declare(strict_types=1);

use App\Http\Middleware\ResolveTeamContext;
use App\Models\User;
use App\Support\Teams\ActiveTeamUrlDefault;
use App\Support\Teams\TeamSegment;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();

    /** @var callable(?User, ?string):Request */
    $this->requestFor = function (?User $user, ?string $segment): Request {
        $request = Request::create('/'.($segment ?? '').'/engine/users', 'GET');
        $request->setUserResolver(static fn (): ?User => $user);

        $route = new Route('GET', '{'.TeamSegment::key().'}/engine/users', []);
        $route->bind($request);

        if ($segment !== null) {
            $route->setParameter(TeamSegment::key(), $segment);
        }

        $request->setRouteResolver(static fn (): Route => $route);

        return $request;
    };

    $this->reached = static fn (): Response => new Response('reached');

    $this->middleware = fn (): ResolveTeamContext => new ResolveTeamContext(app(ActiveTeamUrlDefault::class));
});

afterEach(function (): void {
    URL::defaults([TeamSegment::key() => null]);
    AccessContext::forgetTeam();
    AccessContext::forgetTenant();
});

it('asks nothing when the url carries no team segment', function (): void {
    $user = AccessContext::user($this->tenant, ['current_team_id' => null]);
    $request = ($this->requestFor)($user, null);

    $shape = QueryShape::attemptedBy(fn (): Response => ($this->middleware)()->handle($request, $this->reached));

    expect($shape)->toBeNull()
        ->and(app()->bound('current_team'))->toBeFalse();
});

it('asks nothing for a segment while nobody is authenticated', function (): void {
    $request = ($this->requestFor)(null, 'sales');

    $shape = QueryShape::attemptedBy(fn (): Response => ($this->middleware)()->handle($request, $this->reached));

    expect($shape)->toBeNull();
});

it('looks a team segment up inside the tenant of the acting user only', function (): void {
    $user = AccessContext::user($this->tenant, ['current_team_id' => null]);
    $request = ($this->requestFor)($user, 'sales');

    $shape = QueryShape::attemptedBy(fn (): Response => ($this->middleware)()->handle($request, $this->reached));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('teams'))->toBeTrue()
        ->and($shape->hasBinding((string) $this->tenant->getKey()))->toBeTrue()
        ->and($shape->hasBinding('sales'))->toBeTrue()
        ->and($shape->sql)->toContain('"deleted_at" is null')
        ->and($shape->sql)->toContain('limit 2');
});

it('accepts the ulid of a team as the segment just like its slug', function (): void {
    $user = AccessContext::user($this->tenant, ['current_team_id' => null]);
    $segment = ModelStub::ulid('sales-team');

    $request = ($this->requestFor)($user, $segment);

    $shape = QueryShape::attemptedBy(fn (): Response => ($this->middleware)()->handle($request, $this->reached));

    expect($shape)->not->toBeNull()
        ->and($shape->hasBinding($segment))->toBeTrue()
        ->and(array_count_values(array_map(strval(...), $shape->bindings))[$segment])->toBe(2);
});

it('drops the team segment from the route parameters so a controller never sees it', function (): void {
    $user = AccessContext::user($this->tenant, ['current_team_id' => null]);
    $request = ($this->requestFor)($user, 'sales');

    QueryShape::attemptedBy(fn (): Response => ($this->middleware)()->handle($request, $this->reached));

    expect($request->route()?->parameter(TeamSegment::key()))->toBeNull();
});
