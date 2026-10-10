<?php

declare(strict_types=1);

use App\Actions\Segments\CreateSegmentAction;
use App\Actions\Segments\DeleteSegmentAction;
use App\Actions\Segments\RevokeSegmentShareAction;
use App\Actions\Segments\SetAdminDefaultSegmentAction;
use App\Actions\Segments\SetSegmentDefaultAction;
use App\Actions\Segments\ShareSegmentAction;
use App\Actions\Segments\UpdateSegmentAction;
use App\Models\ObjectType;
use App\Models\Segment;
use App\Models\SegmentShare;
use App\Support\Engine\ObjectTypeRegistry;
use App\Support\Segments\SegmentFilterGuard;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\GateSpy;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->actor = AccessContext::user($this->tenant, [], 'segment-actor');

    $this->filterGuard = Mockery::mock(SegmentFilterGuard::class);

    $this->objectType = ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('segment-action-type'),
        'tenant_id' => $this->tenant->getKey(),
        'slug' => 'companies',
    ]);

    $this->segment = ModelStub::make(Segment::class, [
        'id' => ModelStub::ulid('segment-action'),
        'tenant_id' => $this->tenant->getKey(),
        'owner_id' => $this->actor->getKey(),
        'name' => 'Meine Sicht',
        'object_type_id' => $this->objectType->getKey(),
        'is_system' => false,
        'is_default' => false,
    ]);

    $this->registry = new class($this->objectType) extends ObjectTypeRegistry
    {
        public function __construct(private readonly ObjectType $objectType) {}

        public function byId(string $objectTypeId): ObjectType
        {
            if ($objectTypeId !== (string) $this->objectType->getKey()) {
                throw (new ModelNotFoundException)->setModel(ObjectType::class, [$objectTypeId]);
            }

            return $this->objectType;
        }
    };

    $this->createSegment = fn (): CreateSegmentAction => new CreateSegmentAction($this->filterGuard, $this->registry);
    $this->updateSegment = fn (): UpdateSegmentAction => new UpdateSegmentAction($this->filterGuard, $this->registry);

    /** @var callable(array<string, mixed>):void */
    $this->passValidation = function (array $validated): void {
        $validator = Mockery::mock(ValidatorContract::class);
        $validator->shouldReceive('validate')->andReturn($validated);

        Validator::shouldReceive('make')->andReturn($validator);
    };

    /** @var callable(array<string, mixed>):ValidationException|null */
    $this->refusalOfCreate = function (array $input): ?ValidationException {
        try {
            ($this->createSegment)()->execute($this->actor, $input);
        } catch (ValidationException $exception) {
            return $exception;
        }

        return null;
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();
    Mockery::close();
});

it('demands a name and an object type before it creates a view', function (): void {
    $refusal = ($this->refusalOfCreate)([]);

    expect($refusal)->not->toBeNull()
        ->and(array_keys($refusal->errors()))->toBe(['name', 'object_type_id']);
});

it('binds the object type of a new view to the tenant that is bound to the run', function (): void {
    $shape = QueryShape::attemptedBy(fn (): ?ValidationException => ($this->refusalOfCreate)([
        'name' => 'Offene Deals',
        'object_type_id' => (string) $this->objectType->getKey(),
    ]));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('object_types'))->toBeTrue()
        ->and($shape->hasBinding((string) $this->tenant->getKey()))->toBeTrue()
        ->and($shape->hasBinding((string) $this->objectType->getKey()))->toBeTrue();
});

it('refuses to create a view on an object type whose records the owner may not read', function (): void {
    $resolver = AccessContext::grant('contacts.view');
    $this->filterGuard->shouldNotReceive('assertValid');

    ($this->passValidation)([
        'name' => 'Offene Deals',
        'object_type_id' => (string) $this->objectType->getKey(),
        'filter_definition' => [],
    ]);

    expect(fn (): Segment => ($this->createSegment)()->execute($this->actor, []))
        ->toThrow(AuthorizationException::class)
        ->and($resolver->askedFor)->toContain('companies.view');
});

it('lets the owner through to the filter check once the read permission on the object type is there', function (): void {
    AccessContext::grant('companies.view');

    $seen = null;

    $this->filterGuard->shouldReceive('assertValid')
        ->andReturnUsing(function (ObjectType $objectType, array $tree) use (&$seen): void {
            $seen = [$objectType, $tree];
        });

    $tree = ['combinator' => 'and', 'conditions' => []];

    ($this->passValidation)([
        'name' => 'Offene Deals',
        'object_type_id' => (string) $this->objectType->getKey(),
        'filter_definition' => $tree,
    ]);

    QueryShape::attemptedBy(fn (): Segment => ($this->createSegment)()->execute($this->actor, []));

    expect($seen[0])->toBe($this->objectType)
        ->and($seen[1])->toBe($tree);
});

it('treats a new view without a filter as an empty tree rather than as no check at all', function (): void {
    AccessContext::grant('companies.view');

    $seen = null;

    $this->filterGuard->shouldReceive('assertValid')
        ->andReturnUsing(function (ObjectType $objectType, array $tree) use (&$seen): void {
            $seen = $tree;
        });

    ($this->passValidation)([
        'name' => 'Offene Deals',
        'object_type_id' => (string) $this->objectType->getKey(),
    ]);

    QueryShape::attemptedBy(fn (): Segment => ($this->createSegment)()->execute($this->actor, []));

    expect($seen)->toBe([]);
});

it('never lets a new view carry a smuggled owner, system flag or default flag', function (): void {
    $refusal = ($this->refusalOfCreate)([
        'name' => 'Offene Deals',
        'owner_id' => ModelStub::ulid('victim'),
        'is_system' => true,
        'is_default' => true,
    ]);

    expect($refusal)->not->toBeNull()
        ->and(array_keys($refusal->errors()))->toBe(['object_type_id']);
});

it('asks the gate for the update ability before it validates a change to a view', function (): void {
    $spy = GateSpy::allowing();

    expect(fn (): Segment => ($this->updateSegment)()->execute($this->actor, $this->segment, []))
        ->toThrow(AuthorizationException::class)
        ->and($spy->wasAskedFor('update'))->toBeTrue()
        ->and($spy->calls[0]['arguments'][0])->toBe($this->segment);
});

it('accepts only the name and the filter tree when a view is changed', function (): void {
    GateSpy::allowing('update');

    $shape = QueryShape::attemptedBy(fn (): Segment => ($this->updateSegment)()->execute(
        $this->actor,
        $this->segment,
        ['name' => 'Neu', 'is_system' => true, 'owner_id' => ModelStub::ulid('victim')],
    ));

    expect($shape)->not->toBeNull()
        ->and($this->segment->is_system)->toBeFalse()
        ->and($this->segment->owner_id)->toBe($this->actor->getKey())
        ->and($this->segment->name)->toBe('Neu');
});

it('re-checks a changed filter tree against the object type of the view', function (): void {
    GateSpy::allowing('update');

    $seen = null;

    $this->filterGuard->shouldReceive('assertValid')
        ->andReturnUsing(function (ObjectType $objectType, array $tree) use (&$seen): void {
            $seen = [$objectType, $tree];
        });

    $tree = ['combinator' => 'and', 'conditions' => []];

    QueryShape::attemptedBy(fn (): Segment => ($this->updateSegment)()->execute(
        $this->actor,
        $this->segment,
        ['filter_definition' => $tree],
    ));

    expect($seen[0])->toBe($this->objectType)
        ->and($seen[1])->toBe($tree);
});

it('leaves the stored filter of a cross object view alone because there is no type to check it against', function (): void {
    GateSpy::allowing('update');
    $this->filterGuard->shouldNotReceive('assertValid');

    $crossObject = ModelStub::make(Segment::class, [
        'id' => ModelStub::ulid('cross-object'),
        'tenant_id' => $this->tenant->getKey(),
        'owner_id' => $this->actor->getKey(),
        'object_type_id' => null,
        'is_system' => false,
    ]);

    QueryShape::attemptedBy(fn (): Segment => ($this->updateSegment)()->execute(
        $this->actor,
        $crossObject,
        ['filter_definition' => ['combinator' => 'and', 'conditions' => []]],
    ));

    expect($crossObject->filter_definition)->toBeNull();
});

it('asks the gate for the delete ability before it removes a view', function (): void {
    $spy = GateSpy::allowing();

    expect(fn () => (new DeleteSegmentAction)->execute($this->actor, $this->segment))
        ->toThrow(AuthorizationException::class)
        ->and($spy->wasAskedFor('delete'))->toBeTrue();
});

it('asks the gate for the share ability before it reads a single grantee', function (): void {
    $spy = GateSpy::allowing();

    expect(fn (): SegmentShare => (new ShareSegmentAction)->execute(
        $this->segment,
        ['grantee_type' => 'user', 'grantee_id' => ModelStub::ulid('grantee')],
        $this->actor,
    ))->toThrow(AuthorizationException::class)
        ->and($spy->wasAskedFor('share'))->toBeTrue();
});

it('refuses a grantee type that names no known holder of a view', function (): void {
    GateSpy::allowing('share');

    $refusal = null;

    try {
        (new ShareSegmentAction)->execute(
            $this->segment,
            ['grantee_type' => 'segment', 'grantee_id' => ModelStub::ulid('grantee')],
            $this->actor,
        );
    } catch (ValidationException $exception) {
        $refusal = $exception;
    }

    expect($refusal)->not->toBeNull()
        ->and(array_keys($refusal->errors()))->toBe(['grantee_type']);
});

it('looks a user grantee of a view up inside the bound tenant and never by identifier alone', function (): void {
    GateSpy::allowing('share');

    $granteeId = ModelStub::ulid('grantee');

    $shape = QueryShape::attemptedBy(fn (): SegmentShare => (new ShareSegmentAction)->execute(
        $this->segment,
        ['grantee_type' => 'user', 'grantee_id' => $granteeId],
        $this->actor,
    ));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('users'))->toBeTrue()
        ->and($shape->sql)->toContain('"tenant_id" = ?')
        ->and($shape->hasBinding((string) $this->tenant->getKey()))->toBeTrue()
        ->and($shape->isKeyedTo('users', $granteeId))->toBeTrue();
});

it('refuses every grantee of a view outright while no tenant is bound', function (): void {
    GateSpy::allowing('share');
    AccessContext::forgetTenant();

    $shape = QueryShape::attemptedBy(function (): void {
        try {
            (new ShareSegmentAction)->execute(
                $this->segment,
                ['grantee_type' => 'user', 'grantee_id' => ModelStub::ulid('grantee')],
                $this->actor,
            );
        } catch (ModelNotFoundException) {
            return;
        }
    });

    expect($shape)->toBeNull();
});

it('asks the gate for the share ability of the parent view before revoking a grant', function (): void {
    $spy = GateSpy::allowing();

    $share = ModelStub::make(SegmentShare::class, [
        'id' => ModelStub::ulid('segment-share'),
        'tenant_id' => $this->tenant->getKey(),
        'segment_id' => $this->segment->getKey(),
        'grantee_type' => $this->actor->getMorphClass(),
        'grantee_id' => ModelStub::ulid('grantee'),
    ], ['segment' => $this->segment]);

    expect(fn () => (new RevokeSegmentShareAction)->execute($this->actor, $share))
        ->toThrow(AuthorizationException::class)
        ->and($spy->calls[0]['arguments'][0])->toBe($this->segment);
});

it('reserves the administrative default of a view for the manageDefaults ability', function (): void {
    $spy = GateSpy::allowing();

    expect(fn (): Segment => (new SetSegmentDefaultAction)->execute($this->actor, $this->segment, true))
        ->toThrow(AuthorizationException::class)
        ->and($spy->wasAskedFor('manageDefaults'))->toBeTrue()
        ->and($this->segment->is_default)->toBeFalse();
});

it('binds both identifiers of an administrative default to the tenant before it reads the view', function (): void {
    $shape = QueryShape::attemptedBy(fn (): Segment => (new SetAdminDefaultSegmentAction(new SetSegmentDefaultAction))
        ->execute($this->actor, [
            'object_type_id' => (string) $this->objectType->getKey(),
            'segment_id' => (string) $this->segment->getKey(),
        ]));

    expect($shape)->not->toBeNull()
        ->and($shape->hasBinding((string) $this->tenant->getKey()))->toBeTrue();
});

it('demands both the view and the object type before it sets an administrative default', function (): void {
    $refusal = null;

    try {
        (new SetAdminDefaultSegmentAction(new SetSegmentDefaultAction))->execute($this->actor, []);
    } catch (ValidationException $exception) {
        $refusal = $exception;
    }

    expect($refusal)->not->toBeNull()
        ->and(array_keys($refusal->errors()))->toBe(['object_type_id', 'segment_id']);
});

it('pairs the administrative default with the object type so a foreign view never becomes one', function (): void {
    ($this->passValidation)([
        'object_type_id' => (string) $this->objectType->getKey(),
        'segment_id' => (string) $this->segment->getKey(),
    ]);

    $shape = QueryShape::attemptedBy(fn (): Segment => (new SetAdminDefaultSegmentAction(new SetSegmentDefaultAction))
        ->execute($this->actor, []));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('segments'))->toBeTrue()
        ->and($shape->isKeyedTo('segments', (string) $this->segment->getKey()))->toBeTrue()
        ->and($shape->sql)->toContain('"object_type_id" = ?')
        ->and($shape->hasBinding((string) $this->objectType->getKey()))->toBeTrue();
});
