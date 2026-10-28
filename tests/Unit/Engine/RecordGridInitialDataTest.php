<?php

declare(strict_types=1);

use App\DTOs\Engine\RecordQueryScopeData;
use App\DTOs\Engine\RecordQueryScopeResult;
use App\DTOs\Engine\RecordTreeOrderResult;
use App\Http\Controllers\Engine\RecordGridsController;
use App\Models\ObjectType;
use App\Support\Aging\AgingEvaluator;
use App\Support\Engine\InitialRecordGridRequest;
use App\Support\Engine\ObjectTypePresenter;
use App\Support\Engine\RecordQueryScope;
use App\Support\Engine\SystemFilterFields;
use App\Support\Modules\RecordExtensions;
use App\Support\Preferences\PreferenceSchema;
use App\Support\Preferences\UserPreferenceResolver;
use App\Support\Segments\DefaultSegmentResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->viewer = AccessContext::actAs(AccessContext::user($this->tenant));
    $this->objectType = ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('initial-grid'),
        'tenant_id' => $this->tenant->getKey(),
        'key' => 'deals',
        'slug' => 'deals',
        'name' => 'Deals',
        'hierarchy_relationship_type_id' => null,
        'requires_deletion_reason' => false,
    ]);
    $this->initialRequest = new InitialRecordGridRequest;
    $this->controllerWithScope = function (RecordQueryScope $scope, ?string $defaultSegmentId = null): RecordGridsController {
        $aging = Mockery::mock(AgingEvaluator::class);
        $aging->shouldReceive('fields')->once()->andReturn(collect());
        $preferences = Mockery::mock(UserPreferenceResolver::class);
        $preferences->shouldReceive('document')->once()->with($this->viewer)->andReturn(['settings' => ['pageSize' => 25], 'objectTypes' => []]);
        $defaultSegments = Mockery::mock(DefaultSegmentResolver::class);
        $defaultSegments->shouldReceive('idFor')->once()->with($this->objectType)->andReturn($defaultSegmentId);

        return new RecordGridsController($scope, $aging, app(SystemFilterFields::class), $preferences, new ObjectTypePresenter, new RecordExtensions, $this->initialRequest, new PreferenceSchema, $defaultSegments);
    };
    $this->indexRequest = Request::create('/records/deals');
    $this->indexRequest->headers->set('X-Inertia', 'true');
    $this->indexRequest->setUserResolver(fn () => $this->viewer);
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    AccessContext::suspendRowAccess();
    AccessContext::forgetTenant();
});

it('builds the first block from effective preferences with stable multicolumn sorting', function (): void {
    $this->objectType->hierarchy_relationship_type_id = ModelStub::ulid('hierarchy');
    $payload = $this->initialRequest->resolve(Request::create('/records/deals'), $this->objectType, [
        'lastSegmentId' => 'saved-segment',
        'columnState' => [
            ['colId' => 'name', 'sort' => 'asc', 'sortIndex' => 1],
            ['colId' => 'ignored'],
            ['colId' => 'amount', 'sort' => 'desc', 'sortIndex' => 0],
        ],
    ], 50);

    expect($payload)->toMatchArray([
        'startRow' => 0,
        'endRow' => 50,
        'hierarchy' => true,
        'segment' => 'saved-segment',
        'sortModel' => [['colId' => 'amount', 'sort' => 'desc'], ['colId' => 'name', 'sort' => 'asc']],
    ]);
});

it('prefers the URL segment and respects an explicitly disabled hierarchy', function (): void {
    $this->objectType->hierarchy_relationship_type_id = ModelStub::ulid('hierarchy');
    $payload = $this->initialRequest->resolve(Request::create('/records/deals?segment=url-segment'), $this->objectType, [
        'lastSegmentId' => 'saved-segment',
        'hierarchyOrder' => false,
    ], 5000);

    expect($payload)->toMatchArray(['segment' => 'url-segment', 'hierarchy' => false, 'endRow' => 1000]);
});

it('falls back to the default segment when the viewer brings neither a link nor a saved selection', function (): void {
    $payload = $this->initialRequest->resolve(Request::create('/records/deals'), $this->objectType, [], 100, 'default-segment');

    expect($payload)->toMatchArray(['segment' => 'default-segment']);
});

it('lets a link or a saved segment win over the default segment', function (string $url, array $preference, string $expected): void {
    $payload = $this->initialRequest->resolve(Request::create($url), $this->objectType, $preference, 100, 'default-segment');

    expect($payload)->toMatchArray(['segment' => $expected]);
})->with([
    'link' => ['/records/deals?segment=url-segment', [], 'url-segment'],
    'saved segment' => ['/records/deals', ['lastSegmentId' => 'saved-segment'], 'saved-segment'],
]);

it('never puts the default segment over a saved filter or a drill-down link', function (array $query, array $preference): void {
    expect($this->initialRequest->resolve(Request::create('/records/deals', 'GET', $query), $this->objectType, $preference, 100, 'default-segment'))->toBeNull();
})->with([
    'saved filter' => [[], ['lastFilter' => ['children' => []]]],
    'filter link' => [['filter' => 'encoded'], []],
    'report' => [['report' => 'report', 'group' => 'group'], []],
]);

it('hands the default segment to the page and loads its first block', function (): void {
    StaticQueryConnection::install(static fn (): array => []);
    $scope = Mockery::mock(RecordQueryScope::class);
    $scope->shouldReceive('apply')->once()->withArgs(fn (Builder $query, RecordQueryScopeData $data): bool => $data->segmentId === 'default-segment')
        ->andReturn(new RecordQueryScopeResult(collect(), collect(), [], null, new RecordTreeOrderResult(false)));
    $scope->shouldReceive('attachRuleNames')->once();
    $controller = ($this->controllerWithScope)($scope, 'default-segment');
    $props = $controller->index($this->indexRequest, $this->objectType)->toResponse($this->indexRequest)->getData(true)['props'];

    expect($props['defaultSegmentId'])->toBe('default-segment')
        ->and($props['initialGrid']['request']['segment'])->toBe('default-segment');
});

it('leaves complex links and other view modes to their normal data source', function (array $query, array $preference): void {
    expect($this->initialRequest->resolve(Request::create('/records/deals', 'GET', $query), $this->objectType, $preference, 100))->toBeNull();
})->with([
    'kanban' => [[], ['viewMode' => 'kanban']],
    'forecast' => [[], ['viewMode' => 'forecast']],
    'filter link' => [['filter' => 'encoded'], []],
    'report' => [['report' => 'report', 'group' => 'group'], []],
    'dashboard' => [['dashboard' => 'dashboard', 'widget' => 'widget', 'group' => 'group'], []],
    'saved filter' => [[], ['lastFilter' => ['children' => []]]],
    'invalid segment' => [['segment' => ['invalid']], []],
]);

it('uses the same scoped read and response for initial and subsequent blocks', function (): void {
    $connection = StaticQueryConnection::install(static fn (): array => []);
    $compiler = AccessContext::recordRuleCompiler();
    AccessContext::rowAccessRules([(string) $this->objectType->getKey() => []]);
    AccessContext::enforceRowAccess();
    $scope = Mockery::mock(RecordQueryScope::class);
    $scope->shouldReceive('apply')->twice()->withArgs(function (Builder $query, RecordQueryScopeData $data): bool {
        expect($data->objectType)->toBe($this->objectType)
            ->and($data->viewer)->toBe($this->viewer)
            ->and($data->segmentId)->toBeNull()
            ->and($data->filterModel)->toBe([])
            ->and($data->sortModel)->toBe([]);

        return true;
    })->andReturn(new RecordQueryScopeResult(collect(), collect(), [], null, new RecordTreeOrderResult(false)));
    $scope->shouldReceive('attachRuleNames')->twice();
    $controller = ($this->controllerWithScope)($scope);
    $request = $this->indexRequest;
    $response = $controller->index($request, $this->objectType)->toResponse($request);
    $initial = $response->getData(true)['props']['initialGrid'];
    $gridRequest = Request::create('/records/deals/grid', 'POST', [...$initial['request'], 'filterModel' => []]);
    $gridRequest->setUserResolver(fn () => $this->viewer);
    $subsequent = $controller->grid($gridRequest, $this->objectType)->getData(true);

    expect($initial['request']['endRow'])->toBe(25)
        ->and($initial['response'])->toBe($subsequent)
        ->and($subsequent['lastRow'])->toBe(0);

    $recordQueries = $connection->sqlOf('custom_records');
    expect($recordQueries)->toHaveCount(3)
        ->and($recordQueries[1])->toContain('limit 25', '"custom_records"."tenant_id" = ?', '"object_type_id" = ?')
        ->and($recordQueries[2])->toBe($recordQueries[1])
        ->and($compiler->calls)->toHaveCount(3);
    foreach ($compiler->calls as $call) {
        expect($call['shape']->isScopedToTenant('custom_records', (string) $this->tenant->getKey()))->toBeTrue();
    }
});

it('keeps the page usable when its initial segment cannot be read', function (Closure $exception): void {
    $connection = StaticQueryConnection::install(static fn (): array => []);
    $scope = Mockery::mock(RecordQueryScope::class);
    $scope->shouldReceive('apply')->once()->andThrow($exception());
    $scope->shouldNotReceive('attachRuleNames');
    $controller = ($this->controllerWithScope)($scope);
    $response = $controller->index($this->indexRequest, $this->objectType)->toResponse($this->indexRequest);

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true)['props']['initialGrid'])->toBeNull()
        ->and($connection->sqlOf('custom_records'))->toHaveCount(1);
})->with([
    'forbidden segment' => [fn (): Throwable => new AuthorizationException],
    'deleted segment' => [fn (): Throwable => new ModelNotFoundException],
    'invalid scope' => [fn (): Throwable => ValidationException::withMessages(['segment' => 'Invalid segment'])],
]);
