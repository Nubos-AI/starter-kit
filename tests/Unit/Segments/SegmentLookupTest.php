<?php

declare(strict_types=1);

use App\Models\FieldDefinition;
use App\Models\ObjectType;
use App\Models\Segment;
use App\Support\Authorization\FieldVisibilityResolver;
use App\Support\Engine\FilterTreeValidator;
use App\Support\Segments\ManageableSegmentResolver;
use App\Support\Segments\SegmentFilterFieldSource;
use App\Support\Segments\SegmentFilterGuard;
use App\Support\Segments\SystemSegmentDescriptor;
use App\Support\Segments\SystemSegmentRegistry;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\GateSpy;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->actor = AccessContext::user($this->tenant, [], 'lookup-actor');

    $this->registry = new SystemSegmentRegistry;

    $this->objectType = ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('lookup-type'),
        'tenant_id' => $this->tenant->getKey(),
        'slug' => 'companies',
    ]);
});

afterEach(function (): void {
    AccessContext::forgetTenant();
    app()->forgetInstance(FieldVisibilityResolver::class);
    Mockery::close();
});

it('looks a manageable view up inside the tenant of the actor before it asks the gate', function (): void {
    $spy = GateSpy::allowing();
    $segmentId = ModelStub::ulid('manageable');

    $shape = QueryShape::attemptedBy(fn (): Segment => (new ManageableSegmentResolver)
        ->resolveAuthorized($this->actor, $segmentId, 'manageDefaults'));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('segments'))->toBeTrue()
        ->and($shape->sql)->toContain('"tenant_id" = ?')
        ->and($shape->hasBinding((string) $this->actor->tenant_id))->toBeTrue()
        ->and($shape->isKeyedTo('segments', $segmentId))->toBeTrue()
        ->and($spy->calls)->toBe([]);
});

it('offers the filter tree only the filterable fields of the object type and the aging fields beside them', function (): void {
    $shape = QueryShape::attemptedBy(fn (): EloquentCollection => app(SegmentFilterFieldSource::class)
        ->forObjectType($this->objectType));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('field_definitions'))->toBeTrue()
        ->and($shape->sql)->toContain('"object_type_id" = ?')
        ->and($shape->sql)->toContain('"is_filterable" = ?')
        ->and($shape->hasBinding((string) $this->objectType->getKey()))->toBeTrue()
        ->and($shape->hasBinding(1))->toBeTrue()
        ->and($shape->isScopedToTenant('field_definitions', (string) $this->tenant->getKey()))->toBeTrue()
        ->and($shape->hidesSoftDeleted('field_definitions'))->toBeTrue();
});

it('hands the filter validator the allowed field set the field source produced', function (): void {
    $allowed = new EloquentCollection([
        ModelStub::make(FieldDefinition::class, ['key' => 'stage', 'is_filterable' => true]),
    ]);

    $fieldSource = Mockery::mock(SegmentFilterFieldSource::class);
    $fieldSource->shouldReceive('forObjectType')->andReturn($allowed);

    $seen = null;

    $validator = Mockery::mock(FilterTreeValidator::class);
    $validator->shouldReceive('validate')
        ->andReturnUsing(function (
            array $tree,
            EloquentCollection $fields,
            FieldVisibilityResolver $visibility,
            ObjectType $objectType,
        ) use (&$seen): void {
            $seen = [$tree, $fields, $objectType];
        });

    $tree = ['combinator' => 'and', 'conditions' => []];

    (new SegmentFilterGuard($validator, $fieldSource))->assertValid($this->objectType, $tree);

    expect($seen[0])->toBe($tree)
        ->and($seen[1])->toBe($allowed)
        ->and($seen[2])->toBe($this->objectType);
});

it('offers exactly the two system views the picker shows and keeps their synthetic identifiers', function (): void {
    $descriptors = $this->registry->all();

    expect(array_map(static fn (SystemSegmentDescriptor $d): string => $d->id, $descriptors))
        ->toBe(['system:mine', 'system:recent']);
});

it('marks the personal system view as owner scoped and the recency one as open', function (): void {
    expect($this->registry->find('system:mine')?->ownerScoped)->toBeTrue()
        ->and($this->registry->find('system:recent')?->ownerScoped)->toBeFalse();
});

it('sorts both system views by recency with the direction spelled out', function (): void {
    expect($this->registry->find('system:mine')?->sort)->toBe(['column' => 'updated_at', 'direction' => 'desc'])
        ->and($this->registry->find('system:recent')?->sort)->toBe(['column' => 'updated_at', 'direction' => 'desc']);
});

it('spans every object type with both system views rather than pinning them to one', function (): void {
    expect($this->registry->find('system:mine')?->objectTypeId)->toBeNull()
        ->and($this->registry->find('system:recent')?->objectTypeId)->toBeNull();
});

it('knows nothing about a system view identifier it never offered', function (): void {
    expect($this->registry->find('system:everything'))->toBeNull()
        ->and($this->registry->find(''))->toBeNull();
});

it('names the system views in german because the picker shows them unchanged', function (): void {
    expect($this->registry->find('system:mine')?->name)->toBe('Meine')
        ->and($this->registry->find('system:recent')?->name)->toBe('Zuletzt geändert');
});

it('turns a system descriptor into an unsaved view that carries the tenant of the viewer', function (): void {
    $segment = $this->registry->find('system:mine')?->toSegment($this->actor);

    expect($segment)->toBeInstanceOf(Segment::class)
        ->and($segment?->exists)->toBeFalse()
        ->and($segment?->getKey())->toBe('system:mine')
        ->and($segment?->tenant_id)->toBe($this->actor->tenant_id)
        ->and($segment?->owner_id)->toBeNull()
        ->and($segment?->is_system)->toBeTrue()
        ->and($segment?->is_default)->toBeFalse()
        ->and($segment?->filter_definition)->toBe([]);
});

it('never lets a system view claim an owner so it can never be mistaken for a private one', function (): void {
    foreach ($this->registry->all() as $descriptor) {
        expect($descriptor->toSegment($this->actor)->owner_id)->toBeNull();
    }
});
