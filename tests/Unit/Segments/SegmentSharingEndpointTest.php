<?php

declare(strict_types=1);

use App\Actions\Segments\RevokeSegmentShareAction;
use App\Actions\Segments\SetAdminDefaultSegmentAction;
use App\Actions\Segments\SetSegmentDefaultAction;
use App\Actions\Segments\ShareSegmentAction;
use App\Http\Controllers\Segments\AdminDefaultSegmentsController;
use App\Http\Controllers\Segments\SegmentShareOptionsController;
use App\Http\Controllers\Segments\SegmentSharesController;
use App\Models\Segment;
use App\Models\SegmentShare;
use App\Models\User;
use App\Support\Segments\ManageableSegmentResolver;
use App\Support\Sharing\ShareGranteeOptions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\GateSpy;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->actor = AccessContext::user($this->tenant, [], 'sharing-actor');

    $this->segment = ModelStub::make(Segment::class, [
        'id' => ModelStub::ulid('shared-segment'),
        'tenant_id' => $this->tenant->getKey(),
        'owner_id' => $this->actor->getKey(),
        'name' => 'Geteilte Sicht',
        'object_type_id' => ModelStub::ulid('sharing-type'),
        'is_system' => false,
        'is_default' => false,
    ]);

    $this->shareSegment = Mockery::mock(ShareSegmentAction::class);
    $this->revokeShare = Mockery::mock(RevokeSegmentShareAction::class);
    $this->manageable = Mockery::mock(ManageableSegmentResolver::class);

    $this->shares = fn (): SegmentSharesController => new SegmentSharesController(
        $this->shareSegment,
        $this->revokeShare,
        $this->manageable,
    );

    /** @var callable(array<string, mixed>, bool, string):Request */
    $this->request = function (array $payload = [], bool $signedIn = true, string $method = 'GET'): Request {
        $request = Request::create('/engine/segments/x/shares', $method, $payload);
        $resolved = $signedIn ? $this->actor : null;
        $request->setUserResolver(static fn (): ?User => $resolved);

        return $request;
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();
    Mockery::close();
});

it('turns away an unauthenticated caller on every sharing endpoint', function (): void {
    $controller = ($this->shares)();
    $request = ($this->request)([], false);

    $this->manageable->shouldNotReceive('resolveAuthorized');
    $this->shareSegment->shouldNotReceive('execute');
    $this->revokeShare->shouldNotReceive('execute');

    expect(fn (): JsonResponse => $controller->index($request, 'x'))->toThrow(AuthorizationException::class)
        ->and(fn (): JsonResponse => $controller->store($request, 'x'))->toThrow(AuthorizationException::class)
        ->and(fn (): JsonResponse => $controller->destroy($request, 'x', 'y'))->toThrow(AuthorizationException::class);
});

it('demands the share ability before it lists the grants of a view', function (): void {
    $seen = null;

    $this->manageable->shouldReceive('resolveAuthorized')
        ->andReturnUsing(function (User $actor, string $segmentId, string $ability) use (&$seen): Segment {
            $seen = [$actor, $segmentId, $ability];

            throw new AuthorizationException('nope');
        });

    expect(fn (): JsonResponse => ($this->shares)()->index(($this->request)(), 'wanted'))
        ->toThrow(AuthorizationException::class)
        ->and($seen)->toBe([$this->actor, 'wanted', 'share']);
});

it('lists only the grants that belong to the view the caller asked about', function (): void {
    $this->manageable->shouldReceive('resolveAuthorized')->andReturn($this->segment);

    $shape = QueryShape::attemptedBy(fn (): JsonResponse => ($this->shares)()->index(($this->request)(), 'wanted'));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('segment_shares'))->toBeTrue()
        ->and($shape->sql)->toContain('"segment_id" = ?')
        ->and($shape->hasBinding((string) $this->segment->getKey()))->toBeTrue()
        ->and($shape->isScopedToTenant('segment_shares', (string) $this->tenant->getKey()))->toBeTrue();
});

it('reads the view a new grant names inside the tenant before it shares anything', function (): void {
    $segmentId = ModelStub::ulid('target-segment');
    $this->shareSegment->shouldNotReceive('execute');

    $shape = QueryShape::attemptedBy(fn (): JsonResponse => ($this->shares)()->store(
        ($this->request)(['grantee_type' => 'user'], true, 'POST'),
        $segmentId,
    ));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('segments'))->toBeTrue()
        ->and($shape->isKeyedTo('segments', $segmentId))->toBeTrue()
        ->and($shape->isScopedToTenant('segments', (string) $this->tenant->getKey()))->toBeTrue();
});

it('never reveals which tenant a grant belongs to when it presents one', function (): void {
    $this->manageable->shouldReceive('resolveAuthorized')->andReturn($this->segment);

    $share = ModelStub::make(SegmentShare::class, [
        'id' => ModelStub::ulid('presented-share'),
        'tenant_id' => $this->tenant->getKey(),
        'segment_id' => $this->segment->getKey(),
        'grantee_type' => User::class,
        'grantee_id' => ModelStub::ulid('grantee'),
        'can_edit' => true,
    ]);

    $this->shareSegment->shouldReceive('execute')->andReturn($share);

    $controller = ($this->shares)();
    $present = (new ReflectionClass($controller))->getMethod('present');

    /** @var array<string, mixed> $row */
    $row = $present->invoke($controller, $share);

    expect(array_keys($row))->toBe(['id', 'grantee_type', 'grantee_id', 'can_edit'])
        ->and($row['can_edit'])->toBeTrue();
});

it('refuses the recipient lists of a view to a caller who may not share it', function (): void {
    $seen = null;
    $options = Mockery::mock(ShareGranteeOptions::class);
    $options->shouldNotReceive('forUser');

    $this->manageable->shouldReceive('resolveAuthorized')
        ->andReturnUsing(function (User $actor, string $segmentId, string $ability) use (&$seen): Segment {
            $seen = $ability;

            throw new AuthorizationException('nope');
        });

    $controller = new SegmentShareOptionsController($this->manageable, $options);

    expect(fn (): JsonResponse => $controller(($this->request)(), 'wanted'))
        ->toThrow(AuthorizationException::class)
        ->and($seen)->toBe('share');
});

it('turns away an unauthenticated caller from the administrative default endpoints', function (): void {
    $setAdminDefault = Mockery::mock(SetAdminDefaultSegmentAction::class);
    $setAdminDefault->shouldNotReceive('execute');

    $controller = new AdminDefaultSegmentsController($setAdminDefault, new SetSegmentDefaultAction);
    $request = ($this->request)([], false, 'POST');

    expect(fn (): JsonResponse => $controller->store($request))->toThrow(AuthorizationException::class)
        ->and(fn (): JsonResponse => $controller->destroy($request, 'x'))->toThrow(AuthorizationException::class);
});

it('reads the view inside the tenant before it unsets an administrative default', function (): void {
    GateSpy::allowing();

    $segmentId = ModelStub::ulid('defaulted-segment');
    $controller = new AdminDefaultSegmentsController(
        Mockery::mock(SetAdminDefaultSegmentAction::class),
        new SetSegmentDefaultAction,
    );

    $shape = QueryShape::attemptedBy(fn (): JsonResponse => $controller->destroy(
        ($this->request)([], true, 'DELETE'),
        $segmentId,
    ));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('segments'))->toBeTrue()
        ->and($shape->isKeyedTo('segments', $segmentId))->toBeTrue()
        ->and($shape->isScopedToTenant('segments', (string) $this->tenant->getKey()))->toBeTrue();
});
