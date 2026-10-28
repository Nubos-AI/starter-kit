<?php

declare(strict_types=1);

use App\Actions\Segments\CreateSegmentAction;
use App\Actions\Segments\DeleteSegmentAction;
use App\Actions\Segments\UpdateSegmentAction;
use App\Http\Controllers\Segments\SegmentsController;
use App\Models\Segment;
use App\Models\User;
use App\Support\Segments\SegmentResolver;
use App\Support\Segments\SystemSegmentDescriptor;
use App\Support\Segments\SystemSegmentRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->viewer = AccessContext::user($this->tenant, [], 'segments-controller-viewer');

    $this->segmentResolver = Mockery::mock(SegmentResolver::class);
    $this->createSegment = Mockery::mock(CreateSegmentAction::class);
    $this->updateSegment = Mockery::mock(UpdateSegmentAction::class);
    $this->deleteSegment = Mockery::mock(DeleteSegmentAction::class);

    $this->controller = fn (): SegmentsController => new SegmentsController(
        $this->segmentResolver,
        new SystemSegmentRegistry,
        $this->createSegment,
        $this->updateSegment,
        $this->deleteSegment,
    );

    /** @var callable(array<string, mixed>, bool, string):Request */
    $this->request = function (array $query = [], bool $signedIn = true, string $method = 'GET'): Request {
        $request = Request::create('/engine/segments', $method, $query);
        $resolved = $signedIn ? $this->viewer : null;
        $request->setUserResolver(static fn (): ?User => $resolved);

        return $request;
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();
    Mockery::close();
});

it('turns away an unauthenticated caller on every segment endpoint', function (): void {
    $controller = ($this->controller)();
    $request = ($this->request)([], false);

    $this->createSegment->shouldNotReceive('execute');
    $this->deleteSegment->shouldNotReceive('execute');
    $this->updateSegment->shouldNotReceive('execute');

    expect(fn (): JsonResponse => $controller->index($request))->toThrow(AuthorizationException::class)
        ->and(fn (): JsonResponse => $controller->store($request))->toThrow(AuthorizationException::class)
        ->and(fn (): JsonResponse => $controller->show($request, 'x'))->toThrow(AuthorizationException::class)
        ->and(fn (): JsonResponse => $controller->update($request, 'x'))->toThrow(AuthorizationException::class)
        ->and(fn (): JsonResponse => $controller->destroy($request, 'x'))->toThrow(AuthorizationException::class);
});

it('keeps the segment listing inside the tenant of the caller', function (): void {
    $shape = QueryShape::attemptedBy(fn (): JsonResponse => ($this->controller)()->index(($this->request)()));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('segments'))->toBeTrue()
        ->and($shape->isScopedToTenant('segments', (string) $this->tenant->getKey()))->toBeTrue()
        ->and($shape->hidesSoftDeleted('segments'))->toBeTrue();
});

it('resolves the named object type by its slug before it narrows the listing', function (): void {
    $shape = QueryShape::attemptedBy(fn (): JsonResponse => ($this->controller)()->index(
        ($this->request)(['object_type' => 'companies']),
    ));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('object_types'))->toBeTrue()
        ->and($shape->sql)->toContain('"slug" = ?')
        ->and($shape->hasBinding('companies'))->toBeTrue()
        ->and($shape->isScopedToTenant('object_types', (string) $this->tenant->getKey()))->toBeTrue();
});

it('ignores a blank object type filter instead of narrowing the listing to nothing', function (): void {
    $shape = QueryShape::attemptedBy(fn (): JsonResponse => ($this->controller)()->index(
        ($this->request)(['object_type' => '']),
    ));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('segments'))->toBeTrue()
        ->and($shape->sql)->not->toContain('"object_type_id" is null');
});

it('reads a stored view inside the tenant of the caller and never by identifier alone', function (): void {
    $segmentId = ModelStub::ulid('shown-segment');

    $shape = QueryShape::attemptedBy(fn (): JsonResponse => ($this->controller)()->show(($this->request)(), $segmentId));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('segments'))->toBeTrue()
        ->and($shape->isKeyedTo('segments', $segmentId))->toBeTrue()
        ->and($shape->isScopedToTenant('segments', (string) $this->tenant->getKey()))->toBeTrue();
});

it('hands a system view to the resolver together with its descriptor so it stays viewer relative', function (): void {
    $seen = null;

    $this->segmentResolver->shouldReceive('resolve')
        ->andReturnUsing(function (Segment $segment, User $viewer, ?SystemSegmentDescriptor $descriptor) use (&$seen): array {
            $seen = [$segment, $viewer, $descriptor];

            return [];
        });

    $response = ($this->controller)()->show(($this->request)(), 'system:mine');

    expect($seen[0]->getKey())->toBe('system:mine')
        ->and($seen[0]->is_system)->toBeTrue()
        ->and($seen[1])->toBe($this->viewer)
        ->and($seen[2]?->ownerScoped)->toBeTrue()
        ->and($response->getData(true))->toBe(['sections' => []]);
});

it('never confuses a system view identifier with a stored one and asks the database nothing', function (): void {
    $this->segmentResolver->shouldReceive('resolve')->andReturn([]);

    $shape = QueryShape::attemptedBy(fn (): JsonResponse => ($this->controller)()->show(($this->request)(), 'system:recent'));

    expect($shape)->toBeNull();
});

it('hands the whole request payload to the create action and answers with the created row', function (): void {
    $seen = null;

    $segment = ModelStub::make(Segment::class, [
        'id' => ModelStub::ulid('created-segment'),
        'tenant_id' => $this->tenant->getKey(),
        'owner_id' => $this->viewer->getKey(),
        'name' => 'Offene Deals',
        'object_type_id' => ModelStub::ulid('companies'),
        'is_system' => false,
        'is_default' => false,
    ]);

    $this->createSegment->shouldReceive('execute')
        ->andReturnUsing(function (User $user, array $input) use (&$seen, $segment): Segment {
            $seen = [$user, $input];

            return $segment;
        });

    $response = ($this->controller)()->store(($this->request)(['name' => 'Offene Deals'], true, 'POST'));

    expect($response->getStatusCode())->toBe(201)
        ->and($seen[0])->toBe($this->viewer)
        ->and($seen[1])->toBe(['name' => 'Offene Deals'])
        ->and($response->getData(true)['data'])->toBe([
            'id' => (string) $segment->getKey(),
            'name' => 'Offene Deals',
            'object_type_id' => $segment->object_type_id,
            'is_system' => false,
            'is_default' => false,
            'is_owner' => true,
        ]);
});

it('never reveals the tenant or the owner identifier of a view to the client', function (): void {
    $segment = ModelStub::make(Segment::class, [
        'id' => ModelStub::ulid('presented-segment'),
        'tenant_id' => $this->tenant->getKey(),
        'owner_id' => ModelStub::ulid('somebody-else'),
        'name' => 'Fremde Sicht',
        'object_type_id' => null,
        'is_system' => false,
        'is_default' => true,
    ]);

    $this->createSegment->shouldReceive('execute')->andReturn($segment);

    $row = ($this->controller)()->store(($this->request)([], true, 'POST'))->getData(true)['data'];

    expect($row)->not->toHaveKey('tenant_id')
        ->and($row)->not->toHaveKey('owner_id')
        ->and($row['is_owner'])->toBeFalse()
        ->and($row['is_default'])->toBeTrue();
});
