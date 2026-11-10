<?php

declare(strict_types=1);

use App\Enums\Audit\ActorType;
use App\Http\Controllers\Engine\RecordTimelineController;
use App\Models\CustomRecord;
use App\Models\TimelineEntry;
use App\Support\Engine\RecordRouteResolver;
use App\Support\Teams\TeamSegment;
use App\Support\Timeline\TimelineQuery;
use App\Support\Timeline\TimelineSourceRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;
use Inertia\Response as InertiaResponse;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\GateSpy;
use Tests\Support\ModelStub;
use Tests\Support\RouteShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->viewer = AccessContext::user($this->tenant, [], 'timeline-viewer');

    $this->record = ModelStub::make(CustomRecord::class, [
        'id' => ModelStub::ulid('timeline-endpoint-record'),
        'tenant_id' => $this->tenant->getKey(),
    ]);

    URL::defaults([TeamSegment::key() => 'timeline-team']);

    $this->registry = app(TimelineSourceRegistry::class);
    $this->asked = [];

    $this->paginator = new CursorPaginator(
        new Collection([ModelStub::make(TimelineEntry::class, [
            'id' => ModelStub::ulid('timeline-endpoint-entry'),
            'tenant_id' => $this->tenant->getKey(),
            'record_id' => $this->record->getKey(),
            'source_key' => 'note',
            'payload' => [],
        ])]),
        20,
        null,
        ['cursorName' => 'timelineCursor'],
    );

    /** @var callable():TimelineQuery */
    $this->recordingQuery = function (): TimelineQuery {
        $double = Mockery::mock(TimelineQuery::class);

        $double->shouldReceive('paginate')->andReturnUsing(function (
            CustomRecord $record,
            array $sourceKeys = [],
            ?CarbonImmutable $occurredFrom = null,
            ?CarbonImmutable $occurredTo = null,
            ?ActorType $actorType = null,
            ?string $actorId = null,
            ?string $cursor = null,
            int $perPage = 20,
            string $cursorName = 'timelineCursor',
        ): CursorPaginator {
            $this->asked[] = [
                'record' => $record,
                'sourceKeys' => $sourceKeys,
                'occurredFrom' => $occurredFrom,
                'occurredTo' => $occurredTo,
                'actorType' => $actorType,
                'actorId' => $actorId,
                'cursor' => $cursor,
                'perPage' => $perPage,
                'cursorName' => $cursorName,
            ];

            return $this->paginator;
        });

        return $double;
    };

    /** @var callable(?TimelineQuery):RecordTimelineController */
    $this->controller = function (?TimelineQuery $timelineQuery = null): RecordTimelineController {
        $routeResolver = Mockery::mock(RecordRouteResolver::class);
        $routeResolver->shouldReceive('resolve')->andReturn($this->record);

        return new RecordTimelineController(
            $routeResolver,
            $this->registry,
            $timelineQuery ?? ($this->recordingQuery)(),
        );
    };

    /** @var callable(array<string, mixed>, bool):Request */
    $this->request = function (array $query = [], bool $partial = true): Request {
        $request = Request::create('/records/'.$this->record->getKey().'/timeline', 'GET', $query);

        if ($partial) {
            $request->headers->set('X-Inertia-Partial-Component', 'records/Form');
        }

        $request->setUserResolver(fn (): mixed => $this->viewer);

        return $request;
    };
});

afterEach(function (): void {
    URL::defaults([TeamSegment::key() => null]);
    AccessContext::forgetTenant();
});

it('refuses the strand to a user who may not view the record and never runs a query', function (): void {
    $gate = GateSpy::allowing();

    $timelineQuery = Mockery::mock(TimelineQuery::class);
    $timelineQuery->shouldNotReceive('paginate');

    expect(fn () => (($this->controller)($timelineQuery))(($this->request)(), (string) $this->record->getKey()))
        ->toThrow(AuthorizationException::class)
        ->and($gate->abilities())->toBe(['view'])
        ->and($gate->calls[0]['arguments'][0])->toBe($this->record);
});

it('refuses the strand to a caller without any signed in user', function (): void {
    $timelineQuery = Mockery::mock(TimelineQuery::class);
    $timelineQuery->shouldNotReceive('paginate');

    $request = Request::create('/records/'.$this->record->getKey().'/timeline');

    expect(fn () => (($this->controller)($timelineQuery))($request, (string) $this->record->getKey()))
        ->toThrow(AuthorizationException::class);
});

it('serves the strand once the user may view the record', function (): void {
    GateSpy::allowing('view');

    $response = (($this->controller)())(($this->request)(), (string) $this->record->getKey());

    expect($response)->toBeInstanceOf(InertiaResponse::class)
        ->and($this->asked)->toHaveCount(1)
        ->and($this->asked[0]['record'])->toBe($this->record)
        ->and($this->asked[0]['sourceKeys'])->toBe([])
        ->and($this->asked[0]['cursor'])->toBeNull();
});

it('sends a call without the partial reload header back to the record page', function (): void {
    GateSpy::allowing('view');

    $timelineQuery = Mockery::mock(TimelineQuery::class);
    $timelineQuery->shouldNotReceive('paginate');

    $response = (($this->controller)($timelineQuery))(($this->request)(partial: false), (string) $this->record->getKey());

    expect($response)->toBeInstanceOf(RedirectResponse::class)
        ->and($response->getTargetUrl())->toContain((string) $this->record->getKey());
});

it('sends a partial reload that names a foreign component back to the record page', function (): void {
    GateSpy::allowing('view');

    $timelineQuery = Mockery::mock(TimelineQuery::class);
    $timelineQuery->shouldNotReceive('paginate');

    $request = ($this->request)();
    $request->headers->set('X-Inertia-Partial-Component', 'records/Index');

    expect((($this->controller)($timelineQuery))($request, (string) $this->record->getKey()))
        ->toBeInstanceOf(RedirectResponse::class);
});

it('answers an unknown, repeated or scalar source list with 422', function (): void {
    GateSpy::allowing('view');

    $registered = $this->registry->keys();

    $rejected = [
        'sources.0' => ['sources' => ['definitely_not_a_source']],
        'sources.1' => ['sources' => [$registered[0], $registered[0]]],
        'sources' => ['sources' => 'note'],
    ];

    foreach ($rejected as $field => $query) {
        $response = (($this->controller)())(($this->request)($query), (string) $this->record->getKey());

        expect($response)->toBeInstanceOf(JsonResponse::class)
            ->and($response->getStatusCode())->toBe(422)
            ->and(array_keys($response->getData(true)['errors']))->toContain($field);
    }

    expect($this->asked)->toBe([]);
});

it('answers more source keys than the registry knows with 422', function (): void {
    GateSpy::allowing('view');

    $registered = $this->registry->keys();

    $response = (($this->controller)())(
        ($this->request)(['sources' => [...$registered, $registered[0]]]),
        (string) $this->record->getKey(),
    );

    expect($registered)->not->toBeEmpty()
        ->and($response->getStatusCode())->toBe(422)
        ->and(array_keys($response->getData(true)['errors']))->toContain('sources');
});

it('answers an inverted range and an actor id without an actor kind with 422', function (): void {
    GateSpy::allowing('view');

    $inverted = (($this->controller)())(($this->request)([
        'occurredFrom' => '2026-06-01T00:00:00+00:00',
        'occurredTo' => '2026-01-01T00:00:00+00:00',
    ]), (string) $this->record->getKey());

    $orphanActor = (($this->controller)())(
        ($this->request)(['actorId' => ModelStub::ulid('actor')]),
        (string) $this->record->getKey(),
    );

    expect($inverted->getStatusCode())->toBe(422)
        ->and(array_keys($inverted->getData(true)['errors']))->toContain('occurredTo')
        ->and($orphanActor->getStatusCode())->toBe(422)
        ->and(array_keys($orphanActor->getData(true)['errors']))->toContain('actorType');
});

it('still answers 422 when the partial reload headers are absent', function (): void {
    GateSpy::allowing('view');

    $response = (($this->controller)())(
        ($this->request)(['sources' => ['definitely_not_a_source']], partial: false),
        (string) $this->record->getKey(),
    );

    expect($response)->toBeInstanceOf(JsonResponse::class)
        ->and($response->getStatusCode())->toBe(422);
});

it('passes the validated filter set through to the timeline query', function (): void {
    GateSpy::allowing('view');

    $actorId = ModelStub::ulid('wanted-actor');

    (($this->controller)())(($this->request)([
        'sources' => ['note'],
        'occurredFrom' => '2026-03-05T08:00:00+00:00',
        'actorType' => ActorType::User->value,
        'actorId' => $actorId,
    ]), (string) $this->record->getKey());

    expect($this->asked[0]['sourceKeys'])->toBe(['note'])
        ->and($this->asked[0]['actorType'])->toBe(ActorType::User)
        ->and($this->asked[0]['actorId'])->toBe($actorId)
        ->and($this->asked[0]['occurredFrom']?->toIso8601String())->toBe('2026-03-05T08:00:00+00:00')
        ->and($this->asked[0]['occurredTo'])->toBeNull()
        ->and($this->asked[0]['cursorName'])->toBe('timelineCursor');
});

it('stretches a bare date in the upper bound to the end of that day', function (): void {
    GateSpy::allowing('view');

    (($this->controller)())(($this->request)([
        'occurredFrom' => '2026-03-05',
        'occurredTo' => '2026-03-05',
    ]), (string) $this->record->getKey());

    expect($this->asked[0]['occurredFrom']?->toDateTimeString())->toBe('2026-03-05 00:00:00')
        ->and($this->asked[0]['occurredTo']?->toDateTimeString())->toBe('2026-03-05 23:59:59');
});

it('drops a cursor that was minted under another filter set instead of skipping rows', function (): void {
    GateSpy::allowing('view');

    $encoded = (new Cursor(['occurred_at' => '2026-03-05 09:00:00', 'id' => ModelStub::ulid('cursor')]))->encode();

    (($this->controller)())(
        ($this->request)(['timelineCursor' => $encoded]),
        (string) $this->record->getKey(),
    );

    (($this->controller)())(
        ($this->request)(['sources' => ['note'], 'timelineCursor' => 'deadbeefcafe.'.$encoded]),
        (string) $this->record->getKey(),
    );

    expect($this->asked[0]['cursor'])->toBeNull()
        ->and($this->asked[1]['cursor'])->toBeNull();
});

it('hands the next page cursor back unchanged while the filter set stays the same', function (): void {
    GateSpy::allowing('view');

    $entry = fn (string $seed): TimelineEntry => ModelStub::make(TimelineEntry::class, [
        'id' => ModelStub::ulid($seed),
        'tenant_id' => $this->tenant->getKey(),
        'record_id' => $this->record->getKey(),
        'source_key' => 'note',
        'payload' => [],
    ]);

    $this->paginator = new CursorPaginator(
        new Collection([$entry('first-entry'), $entry('second-entry')]),
        1,
        null,
        ['cursorName' => 'timelineCursor', 'parameters' => ['id']],
    );

    $firstRequest = ($this->request)(['sources' => ['note']]);
    $firstRequest->headers->set('X-Inertia', 'true');

    $page = (($this->controller)())($firstRequest, (string) $this->record->getKey())
        ->toResponse($firstRequest)
        ->getData(true);

    $stampedNext = $page['scrollProps']['timelineEntries']['nextPage'] ?? null;

    (($this->controller)())(
        ($this->request)(['sources' => ['note'], 'timelineCursor' => $stampedNext]),
        (string) $this->record->getKey(),
    );

    expect($stampedNext)->toBeString()
        ->and($stampedNext)->toEndWith('.'.$this->paginator->nextCursor()?->encode())
        ->and($this->asked[1]['cursor'])->toBe($this->paginator->nextCursor()?->encode());
});

it('registers the timeline strand behind the timeline capability', function (): void {
    $route = RouteShape::named('engine.records.timeline');

    expect($route->methods())->toContain('GET')
        ->and($route->handledBy())->toContain(RecordTimelineController::class)
        ->and($route->hasDeclaredMiddleware('capability:timeline'))->toBeTrue()
        ->and($route->resolvedMiddleware())->not->toBeEmpty();
});
