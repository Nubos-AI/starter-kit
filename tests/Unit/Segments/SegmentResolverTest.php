<?php

declare(strict_types=1);

use App\Models\CustomRecord;
use App\Models\FieldDefinition;
use App\Models\ObjectType;
use App\Models\Segment;
use App\Support\Aging\AgingEvaluator;
use App\Support\Authorization\FieldVisibilityResolver;
use App\Support\Engine\RecordFilterCompiler;
use App\Support\Segments\SegmentFilterFieldSource;
use App\Support\Segments\SegmentResolver;
use App\Support\Segments\SystemSegmentDescriptor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
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
    $this->viewer = AccessContext::user($this->tenant, [], 'segment-viewer');

    $this->compiler = Mockery::mock(RecordFilterCompiler::class);
    $this->aging = Mockery::mock(AgingEvaluator::class);
    $this->fieldSource = Mockery::mock(SegmentFilterFieldSource::class);

    $this->resolver = fn (): SegmentResolver => new SegmentResolver(
        $this->compiler,
        $this->aging,
        $this->fieldSource,
    );

    $this->objectType = ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('segment-object-type'),
        'tenant_id' => $this->tenant->getKey(),
        'slug' => 'companies',
    ]);

    /** @var callable(array<string, mixed>):Segment */
    $this->segment = fn (array $attributes = []): Segment => ModelStub::make(Segment::class, [
        'id' => ModelStub::ulid('resolved-segment'),
        'tenant_id' => $this->tenant->getKey(),
        'owner_id' => $this->viewer->getKey(),
        'object_type_id' => $this->objectType->getKey(),
        'filter_definition' => [],
        'is_system' => false,
        'is_default' => false,
        ...$attributes,
    ]);
});

afterEach(function (): void {
    AccessContext::forgetTenant();
    app()->forgetInstance(FieldVisibilityResolver::class);
    Mockery::close();
});

it('fails closed while no tenant is bound to the run and asks the database nothing', function (): void {
    AccessContext::actAs($this->viewer);
    AccessContext::forgetTenant();

    $shape = QueryShape::attemptedBy(function (): void {
        try {
            ($this->resolver)()->resolve(($this->segment)(), $this->viewer);
        } catch (AuthorizationException) {
            return;
        }
    });

    expect(fn (): array => ($this->resolver)()->resolve(($this->segment)(), $this->viewer))
        ->toThrow(AuthorizationException::class)
        ->and($shape)->toBeNull();
});

it('fails closed when nobody is signed in at all', function (): void {
    expect(fn (): array => ($this->resolver)()->resolve(($this->segment)(), $this->viewer))
        ->toThrow(AuthorizationException::class);
});

it('fails closed when the signed in identity is not the viewer the caller named', function (): void {
    $intruder = AccessContext::user($this->tenant, [], 'intruder');
    AccessContext::actAs($intruder);

    expect(fn (): array => ($this->resolver)()->resolve(($this->segment)(), $this->viewer))
        ->toThrow(AuthorizationException::class);
});

it('refuses resolution to a viewer the segment policy does not admit and reaches no record query', function (): void {
    AccessContext::actAs($this->viewer);
    $spy = GateSpy::allowing();

    $shape = QueryShape::attemptedBy(function (): void {
        try {
            ($this->resolver)()->resolve(($this->segment)(), $this->viewer);
        } catch (AuthorizationException) {
            return;
        }
    });

    expect(fn (): array => ($this->resolver)()->resolve(($this->segment)(), $this->viewer))
        ->toThrow(AuthorizationException::class)
        ->and($shape)->toBeNull()
        ->and($spy->wasAskedFor('view'))->toBeTrue();
});

it('narrows a single type segment to exactly the object type it names', function (): void {
    AccessContext::actAs($this->viewer);
    GateSpy::allowing('view');

    $shape = QueryShape::attemptedBy(fn (): array => ($this->resolver)()->resolve(($this->segment)(), $this->viewer));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('object_types'))->toBeTrue()
        ->and($shape->isKeyedTo('object_types', (string) $this->objectType->getKey()))->toBeTrue()
        ->and($shape->isScopedToTenant('object_types', (string) $this->tenant->getKey()))->toBeTrue();
});

it('spans every object type of the tenant for a cross object segment', function (): void {
    AccessContext::actAs($this->viewer);
    GateSpy::allowing('view');

    $shape = QueryShape::attemptedBy(fn (): array => ($this->resolver)()->resolve(
        ($this->segment)(['object_type_id' => null]),
        $this->viewer,
    ));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('object_types'))->toBeTrue()
        ->and($shape->isScopedToTenant('object_types', (string) $this->tenant->getKey()))->toBeTrue()
        ->and($shape->sql)->not->toContain('"object_types"."id" =');
});

it('offers the filter tree only the filterable fields of the object type it scopes', function (): void {
    $allowed = new EloquentCollection([
        ModelStub::make(FieldDefinition::class, ['key' => 'stage', 'is_filterable' => true]),
    ]);

    $seen = null;

    $this->fieldSource->shouldReceive('forObjectType')->andReturn($allowed);
    $this->compiler->shouldReceive('applyTree')
        ->andReturnUsing(function (
            Builder $query,
            EloquentCollection $fields,
            array $tree,
            FieldVisibilityResolver $visibility,
            ObjectType $objectType,
        ) use (&$seen): void {
            $seen = [$fields, $tree, $objectType];
        });

    $tree = ['combinator' => 'and', 'conditions' => [['field' => 'stage', 'operator' => 'equals', 'value' => 'won']]];

    ($this->resolver)()->applyScope(
        CustomRecord::query(),
        ($this->segment)(['filter_definition' => $tree]),
        $this->objectType,
        $this->viewer,
    );

    expect($seen[0])->toBe($allowed)
        ->and($seen[1])->toBe($tree)
        ->and($seen[2])->toBe($this->objectType);
});

it('treats a segment without a stored filter as an empty tree rather than as no filter at all', function (): void {
    $seen = null;

    $this->fieldSource->shouldReceive('forObjectType')->andReturn(new EloquentCollection);
    $this->compiler->shouldReceive('applyTree')
        ->andReturnUsing(function (Builder $query, EloquentCollection $fields, array $tree) use (&$seen): void {
            $seen = $tree;
        });

    ($this->resolver)()->applyScope(
        CustomRecord::query(),
        ($this->segment)(['filter_definition' => null]),
        $this->objectType,
        $this->viewer,
    );

    expect($seen)->toBe([]);
});

it('narrows an owner scoped system segment to the records of the viewer who asked', function (): void {
    $this->fieldSource->shouldReceive('forObjectType')->andReturn(new EloquentCollection);
    $this->compiler->shouldReceive('applyTree');

    $query = CustomRecord::query();

    ($this->resolver)()->applyScope(
        $query,
        ($this->segment)(),
        $this->objectType,
        $this->viewer,
        new SystemSegmentDescriptor('system:mine', 'Meine', null, true),
    );

    $shape = QueryShape::of($query);

    expect($shape->sql)->toContain('"owner_id" = ?')
        ->and($shape->hasBinding((string) $this->viewer->getKey()))->toBeTrue();
});

it('leaves a system segment that is not owner scoped open to every record the viewer may read', function (): void {
    $this->fieldSource->shouldReceive('forObjectType')->andReturn(new EloquentCollection);
    $this->compiler->shouldReceive('applyTree');

    $query = CustomRecord::query();

    ($this->resolver)()->applyScope(
        $query,
        ($this->segment)(),
        $this->objectType,
        $this->viewer,
        new SystemSegmentDescriptor('system:recent', 'Zuletzt', null, false),
    );

    expect(QueryShape::of($query)->sql)->not->toContain('"owner_id" = ?');
});

it('keeps the record query inside the tenant and away from trashed rows', function (): void {
    $this->fieldSource->shouldReceive('forObjectType')->andReturn(new EloquentCollection);
    $this->compiler->shouldReceive('applyTree');

    $query = CustomRecord::query()->ofType($this->objectType);

    ($this->resolver)()->applyScope($query, ($this->segment)(), $this->objectType, $this->viewer);

    $shape = QueryShape::of($query);

    expect($shape->isScopedToTenant('custom_records', (string) $this->tenant->getKey()))->toBeTrue()
        ->and($shape->hidesSoftDeleted('custom_records'))->toBeTrue()
        ->and($shape->hasColumnCondition('custom_records', 'object_type_id'))->toBeTrue();
});
