<?php

declare(strict_types=1);

use App\Actions\Segments\PersistFilterStateAction;
use App\Exceptions\Engine\InvalidFilterTreeException;
use App\Http\Controllers\Segments\SegmentDeepLinksController;
use App\Models\ObjectType;
use App\Models\User;
use App\Support\Aging\AgingEvaluator;
use App\Support\Authorization\FieldVisibilityResolver;
use App\Support\Engine\RecordFilterCompiler;
use App\Support\Engine\RecordSelectionResolver;
use App\Support\Segments\SegmentFilterFieldSource;
use App\Support\Segments\SegmentResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticQueryConnection;
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

it('answers an empty result without a query when neither a view nor a filter was asked for', function (array $query): void {
    $shape = QueryShape::attemptedBy(fn (): JsonResponse => ($this->call)($query));

    $response = ($this->call)($query);

    expect($shape)->toBeNull()
        ->and($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toBe(['sections' => [], 'filter' => null]);
})->with([
    'no parameters' => [[]],
    'blank view identifier and blank filter' => [['segment' => '', 'filter' => '']],
]);

it('refuses a filter payload that does not carry a json filter tree', function (string $payload): void {
    $response = ($this->call)(['filter' => $payload]);

    expect($response->getStatusCode())->toBe(422);
})->with([
    'not decodable at all' => ['!!!not-base64!!!'],
    'decodable but no json' => [rtrim(strtr(base64_encode('not json at all'), '+/', '-_'), '=')],
    'json scalar rather than a tree' => [rtrim(strtr(base64_encode('42'), '+/', '-_'), '=')],
]);

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
    $this->segments->shouldReceive('boundedSection')->andReturn([
        'objectTypeId' => (string) $this->objectType->getKey(),
        'slug' => 'companies',
        'records' => [],
        'hasMore' => false,
    ]);

    ($this->call)(['filter' => $payload]);

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

it('caps the records a filter deep link answers with and reports that more records match', function (): void {
    AccessContext::actAs($this->viewer);
    config()->set('engine.segments.max_result_rows', 2);

    $visibility = Mockery::mock(FieldVisibilityResolver::class);
    $visibility->shouldReceive('forbiddenReadFieldKeys')->andReturn([]);
    app()->instance(FieldVisibilityResolver::class, $visibility);

    $this->selection->shouldReceive('filterScope')->andReturn(['fields' => new Collection, 'expressions' => []]);
    $this->compiler->shouldReceive('applyTree');

    $tenantId = (string) $this->tenant->getKey();
    $typeId = (string) $this->objectType->getKey();

    $connection = StaticQueryConnection::install(static fn (string $sql): array => str_contains($sql, 'from "custom_records"')
        ? array_map(
            static fn (string $seed): array => ['id' => ModelStub::ulid($seed), 'tenant_id' => $tenantId, 'object_type_id' => $typeId],
            ['company-a', 'company-b', 'company-c'],
        )
        : []);

    $controller = new SegmentDeepLinksController(
        $this->compiler,
        $this->selection,
        new SegmentResolver($this->compiler, Mockery::mock(AgingEvaluator::class), Mockery::mock(SegmentFilterFieldSource::class)),
        $this->persist,
    );

    $payload = rtrim(strtr(base64_encode('{"combinator":"and","conditions":[]}'), '+/', '-_'), '=');

    try {
        $response = $controller(($this->request)(['filter' => $payload]), $this->objectType);
    } finally {
        StaticQueryConnection::uninstall();
        app()->forgetInstance(FieldVisibilityResolver::class);
    }

    $section = $response->getData(true)['sections'][0];

    expect($connection->sqlOf('custom_records')[0])->toContain('limit 3')
        ->and($section['records'])->toHaveCount(2)
        ->and($section['hasMore'])->toBeTrue();
});
