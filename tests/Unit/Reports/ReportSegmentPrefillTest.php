<?php

declare(strict_types=1);

use App\Http\Controllers\Reports\ReportSegmentPrefillController;
use App\Models\Segment;
use App\Models\User;
use App\Support\Engine\FilterFieldKeyCollector;
use App\Support\Engine\SystemFilterFields;
use App\Support\Segments\SystemSegmentDescriptor;
use App\Support\Segments\SystemSegmentRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\FakeFieldVisibilityResolver;
use Tests\Support\Doubles\GateSpy;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->objectTypeId = ModelStub::ulid('deals');
    $this->segmentId = ModelStub::ulid('segment');

    /** @var callable(?SystemSegmentDescriptor):SystemSegmentRegistry */
    $this->registryOf = static function (?SystemSegmentDescriptor $descriptor): SystemSegmentRegistry {
        $registry = Mockery::mock(SystemSegmentRegistry::class);
        $registry->shouldReceive('find')->andReturn($descriptor);

        return $registry;
    };

    /** @var callable(?SystemSegmentDescriptor, list<string>):ReportSegmentPrefillController */
    $this->controllerFor = fn (
        ?SystemSegmentDescriptor $descriptor = null,
        array $forbiddenRead = [],
    ): ReportSegmentPrefillController => new ReportSegmentPrefillController(
        ($this->registryOf)($descriptor),
        app(FilterFieldKeyCollector::class),
        app(SystemFilterFields::class),
        new FakeFieldVisibilityResolver($forbiddenRead),
    );

    /** @var callable():User */
    $this->viewer = fn (): User => AccessContext::actAs(AccessContext::user($this->tenant));

    /** @var callable(?User):Request */
    $this->requestOf = static function (?User $user): Request {
        $request = Request::create('/reports/segment-prefill', 'GET');
        $request->setUserResolver(static fn (): ?User => $user);

        return $request;
    };

    /** @var callable(array<string, mixed>):Segment */
    $this->segment = fn (array $overrides = []): Segment => ModelStub::make(Segment::class, [
        'id' => $this->segmentId,
        'tenant_id' => $this->tenant->getKey(),
        'object_type_id' => $this->objectTypeId,
        'name' => 'North',
        'is_system' => false,
        'filter_definition' => [
            'combinator' => 'and',
            'conditions' => [['field' => 'sales_region', 'operator' => 'equals', 'value' => 'north']],
        ],
        ...$overrides,
    ]);
});

afterEach(function (): void {
    AccessContext::forgetTenant();
    Mockery::close();
});

it('refuses the prefill to a caller the report gate turns away before any segment is read', function (): void {
    GateSpy::allowing();

    $controller = ($this->controllerFor)();

    $reached = QueryShape::attemptedBy(function () use ($controller): void {
        try {
            $controller(($this->requestOf)(($this->viewer)()), $this->segmentId);
        } catch (AuthorizationException) {
            return;
        }
    });

    expect(fn (): JsonResponse => $controller(($this->requestOf)(($this->viewer)()), $this->segmentId))
        ->toThrow(AuthorizationException::class)
        ->and($reached)->toBeNull();
});

it('rejects a system segment with a field bound error instead of a server error', function (): void {
    GateSpy::allowing('viewAny');

    $descriptor = new SystemSegmentDescriptor('system:mine', 'Mine', null, true);
    $controller = ($this->controllerFor)($descriptor);

    try {
        $controller(($this->requestOf)(($this->viewer)()), $this->segmentId);
    } catch (ValidationException $exception) {
        expect(array_keys($exception->errors()))->toBe(['segment']);

        return;
    }

    throw new RuntimeException('The system segment was accepted as a report template.');
});

it('looks a segment up inside the tenant of the caller and never by id alone', function (): void {
    GateSpy::allowing('viewAny');

    $controller = ($this->controllerFor)();

    $shape = QueryShape::attemptedBy(
        fn (): JsonResponse => $controller(($this->requestOf)(($this->viewer)()), $this->segmentId),
    );

    expect($shape)->not->toBeNull()
        ->and($shape->targets('segments'))->toBeTrue()
        ->and($shape->isScopedToTenant('segments', (string) $this->tenant->getKey()))->toBeTrue()
        ->and($shape->isKeyedTo('segments', $this->segmentId))->toBeTrue();
});

it('names a filter field the viewer may not read as the reason a segment is unusable', function (): void {
    GateSpy::allowing('viewAny', 'view');

    $controller = ($this->controllerFor)(null, ['sales_region']);

    $reflection = new ReflectionMethod($controller, 'namesAForbiddenField');

    expect($reflection->invoke($controller, ($this->viewer)(), ($this->segment)(), ($this->segment)()->filter_definition))
        ->toBeTrue();
});

it('accepts a segment whose filter fields the viewer may read', function (): void {
    GateSpy::allowing('viewAny', 'view');

    $controller = ($this->controllerFor)();

    $reflection = new ReflectionMethod($controller, 'namesAForbiddenField');

    expect($reflection->invoke($controller, ($this->viewer)(), ($this->segment)(), ($this->segment)()->filter_definition))
        ->toBeFalse();
});

it('treats a segment without an object type as unrestricted because it names no field of one', function (): void {
    GateSpy::allowing('viewAny', 'view');

    $controller = ($this->controllerFor)(null, ['sales_region']);

    $reflection = new ReflectionMethod($controller, 'namesAForbiddenField');
    $segment = ($this->segment)(['object_type_id' => null]);

    expect($reflection->invoke($controller, ($this->viewer)(), $segment, $segment->filter_definition))
        ->toBeFalse();
});

it('refuses a segment that filters by an aging value as a report template', function (): void {
    GateSpy::allowing('viewAny', 'view');

    $controller = ($this->controllerFor)();

    $reflection = new ReflectionMethod($controller, 'namesAnAgingField');

    expect($reflection->invoke($controller, [
        'combinator' => 'and',
        'conditions' => [['field' => 'aging_age', 'operator' => 'greaterThan', 'value' => 5]],
    ]))->toBeTrue()
        ->and($reflection->invoke($controller, ($this->segment)()->filter_definition))->toBeFalse();
});
