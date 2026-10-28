<?php

declare(strict_types=1);

use App\Actions\Segments\PersistFilterStateAction;
use App\Exceptions\Engine\InvalidFilterTreeException;
use App\Http\Controllers\Segments\SegmentDeepLinksController;
use App\Models\ObjectType;
use App\Models\User;
use App\Support\Engine\RecordFilterCompiler;
use App\Support\Engine\RecordSelectionResolver;
use App\Support\Segments\SegmentResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\RouteShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->viewer = AccessContext::user($this->tenant, [], 'deep-link-viewer');

    $this->compiler = Mockery::mock(RecordFilterCompiler::class);
    $this->selection = Mockery::mock(RecordSelectionResolver::class);
    $this->segments = Mockery::mock(SegmentResolver::class);
    $this->persist = Mockery::mock(PersistFilterStateAction::class);

    $this->controller = fn (): SegmentDeepLinksController => new SegmentDeepLinksController(
        $this->compiler,
        $this->selection,
        $this->segments,
        $this->persist,
    );

    $this->objectType = ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('deep-link-type'),
        'tenant_id' => $this->tenant->getKey(),
        'slug' => 'companies',
    ]);

    /** @var callable(array<string, mixed>, bool):Request */
    $this->request = function (array $query, bool $signedIn = true): Request {
        $request = Request::create('/engine/segments/companies/deep-link', 'GET', $query);
        $resolved = $signedIn ? $this->viewer : null;
        $request->setUserResolver(static fn (): ?User => $resolved);

        return $request;
    };

    /** @var callable(array<string, mixed>):JsonResponse */
    $this->call = fn (array $query): JsonResponse => ($this->controller)()(
        ($this->request)($query),
        $this->objectType,
    );
});

afterEach(function (): void {
    AccessContext::forgetTenant();
    Mockery::close();
});

it('turns away a caller who is not signed in', function (): void {
    expect(fn (): JsonResponse => ($this->controller)()(($this->request)([], false), $this->objectType))
        ->toThrow(AuthorizationException::class);
});

it('answers an empty result without a query when neither a view nor a filter was asked for', function (): void {
    $shape = QueryShape::attemptedBy(fn (): JsonResponse => ($this->call)([]));

    $response = ($this->call)([]);

    expect($shape)->toBeNull()
        ->and($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toBe(['sections' => [], 'filter' => null]);
});

it('treats an empty view identifier and an empty filter as no request at all', function (): void {
    $response = ($this->call)(['segment' => '', 'filter' => '']);

    expect($response->getData(true))->toBe(['sections' => [], 'filter' => null]);
});

it('refuses a filter payload that is not decodable at all', function (): void {
    $response = ($this->call)(['filter' => '!!!not-base64!!!']);

    expect($response->getStatusCode())->toBe(422);
});

it('refuses a decodable filter payload that carries no json', function (): void {
    $response = ($this->call)(['filter' => rtrim(strtr(base64_encode('not json at all'), '+/', '-_'), '=')]);

    expect($response->getStatusCode())->toBe(422);
});

it('refuses a filter payload whose json is a scalar rather than a tree', function (): void {
    $response = ($this->call)(['filter' => rtrim(strtr(base64_encode('42'), '+/', '-_'), '=')]);

    expect($response->getStatusCode())->toBe(422);
});

it('accepts the base64url alphabet without padding as the filter transport', function (): void {
    $tree = ['combinator' => 'and', 'conditions' => [['field' => 'stage', 'operator' => 'equals', 'value' => 'won']]];
    $payload = rtrim(strtr(base64_encode((string) json_encode($tree)), '+/', '-_'), '=');

    $seen = null;

    $this->selection->shouldReceive('filterScope')
        ->andReturnUsing(function () use (&$seen): array {
            $seen = 'asked';

            return ['fields' => new Collection, 'expressions' => []];
        });

    $this->compiler->shouldReceive('applyTree');

    QueryShape::attemptedBy(fn (): JsonResponse => ($this->call)(['filter' => $payload]));

    expect($seen)->toBe('asked');
});

it('reads a persisted filter only within the object type the deep link names', function (): void {
    $shape = QueryShape::attemptedBy(fn (): JsonResponse => ($this->call)(['filter' => 'fs_01HSHORTID']));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('filter_states'))->toBeTrue()
        ->and($shape->isKeyedTo('filter_states', '01HSHORTID'))->toBeTrue()
        ->and($shape->sql)->toContain('"object_type_id" = ?')
        ->and($shape->hasBinding((string) $this->objectType->getKey()))->toBeTrue()
        ->and($shape->isScopedToTenant('filter_states', (string) $this->tenant->getKey()))->toBeTrue();
});

it('reads a requested view inside the tenant before it hands it to the resolver', function (): void {
    $segmentId = ModelStub::ulid('deep-link-segment');

    $shape = QueryShape::attemptedBy(fn (): JsonResponse => ($this->call)(['segment' => $segmentId]));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('segments'))->toBeTrue()
        ->and($shape->isKeyedTo('segments', $segmentId))->toBeTrue()
        ->and($shape->isScopedToTenant('segments', (string) $this->tenant->getKey()))->toBeTrue();
});

it('answers a refusal raised while scoping the filter as forbidden and names the reason', function (): void {
    $payload = rtrim(strtr(base64_encode('{"combinator":"and","conditions":[]}'), '+/', '-_'), '=');

    $this->selection->shouldReceive('filterScope')
        ->andThrow(new AuthorizationException('Sie dürfen dieses Feld nicht filtern.'));

    $response = ($this->call)(['filter' => $payload]);

    expect($response->getStatusCode())->toBe(403)
        ->and($response->getData(true)['message'])->toBe('Sie dürfen dieses Feld nicht filtern.');
});

it('answers a rejected filter tree as unprocessable rather than as a server fault', function (): void {
    $payload = rtrim(strtr(base64_encode('{"combinator":"xor","conditions":[]}'), '+/', '-_'), '=');

    $this->selection->shouldReceive('filterScope')
        ->andReturn(['fields' => new Collection, 'expressions' => []]);

    $this->compiler->shouldReceive('applyTree')->andThrow(new InvalidFilterTreeException);

    $response = ($this->call)(['filter' => $payload]);

    expect($response->getStatusCode())->toBe(422);
});

it('guards the deep link route with the read permission of the object type', function (): void {
    $route = RouteShape::named('engine.segments.deep-link');

    expect($route->hasDeclaredMiddleware('permission:{objectType}.view'))->toBeTrue()
        ->and($route->hasDeclaredMiddleware('capability:records'))->toBeTrue()
        ->and($route->handledBy())->toContain('SegmentDeepLinksController');
});
