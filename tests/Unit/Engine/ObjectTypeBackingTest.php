<?php

declare(strict_types=1);

use App\Contracts\Engine\ObjectTypeBackingInterface;
use App\Enums\Authorization\CrudAction;
use App\Enums\Engine\ObjectTypeCapability;
use App\Enums\Engine\StorageStrategy;
use App\Exceptions\Engine\MissingObjectTypeBackingException;
use App\Exceptions\Engine\UntenantedObjectTypeBackingException;
use App\Models\ObjectType;
use App\Models\Segment;
use App\Support\Engine\EloquentModelBacking;
use App\Support\Engine\GenericRecordBacking;
use App\Support\Engine\ObjectTypeBackingRegistry;
use Tests\Fixtures\Records\Device;
use Tests\Fixtures\Records\Employee;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    AccessContext::suspendRowAccess();

    config()->set('engine.model_paths', []);
    config()->set('engine.native_backings', []);

    /** @var callable(string, StorageStrategy, ?string):ObjectType */
    $this->objectType = fn (string $slug, StorageStrategy $strategy, ?string $tenantId = null): ObjectType => ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('type-'.$slug.'-'.($tenantId ?? 'bound')),
        'tenant_id' => $tenantId ?? $this->tenant->getKey(),
        'key' => $slug,
        'slug' => $slug,
        'storage_strategy' => $strategy,
    ]);

    /** @var callable(ObjectType):ObjectTypeBackingInterface */
    $this->backingFor = static fn (ObjectType $type): ObjectTypeBackingInterface => (new ObjectTypeBackingRegistry)->for($type);
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('gives a generic object type the record backing', function (): void {
    expect(($this->backingFor)(($this->objectType)('companies', StorageStrategy::Generic)))
        ->toBeInstanceOf(GenericRecordBacking::class);
});

it('binds a model to a native object type through the configuration', function (): void {
    config()->set('engine.native_backings', ['employees' => Employee::class]);

    expect(($this->backingFor)(($this->objectType)('employees', StorageStrategy::Native)))
        ->toBeInstanceOf(EloquentModelBacking::class);
});

it('falls back to the record backing for a native object type without a bound model', function (): void {
    expect(($this->backingFor)(($this->objectType)('employees', StorageStrategy::Native)))
        ->toBeInstanceOf(GenericRecordBacking::class);
});

it('refuses a binding that is neither a model nor a backing', function (): void {
    config()->set('engine.native_backings', ['employees' => ObjectTypeBackingRegistry::class]);

    expect(fn (): ObjectTypeBackingInterface => ($this->backingFor)(($this->objectType)('employees', StorageStrategy::Native)))
        ->toThrow(MissingObjectTypeBackingException::class);
});

it('ignores a configured binding that names a class which does not exist', function (): void {
    config()->set('engine.native_backings', ['employees' => 'App\\Models\\DoesNotExist']);

    expect((new ObjectTypeBackingRegistry)->bindings())->not->toHaveKey('employees');
});

it('never binds a model to an object type that stores its rows generically', function (): void {
    config()->set('engine.native_backings', ['companies' => Employee::class]);

    expect(($this->backingFor)(($this->objectType)('companies', StorageStrategy::Generic)))
        ->toBeInstanceOf(GenericRecordBacking::class);
});

it('scopes the generic query to the object type inside the tenant', function (): void {
    $type = ($this->objectType)('companies', StorageStrategy::Generic);

    $shape = QueryShape::of(($this->backingFor)($type)->newQuery($type));

    expect($shape->targets('custom_records'))->toBeTrue()
        ->and($shape->hasColumnCondition('custom_records', 'object_type_id'))->toBeTrue()
        ->and($shape->hasBinding((string) $type->getKey()))->toBeTrue()
        ->and($shape->isScopedToTenant('custom_records', (string) $this->tenant->getKey()))->toBeTrue();
});

it('runs the native query against the table of the bound model', function (): void {
    config()->set('engine.native_backings', ['employees' => Employee::class]);

    $type = ($this->objectType)('employees', StorageStrategy::Native);
    $backing = ($this->backingFor)($type);

    expect($backing->modelClass($type))->toBe(Employee::class)
        ->and(QueryShape::of($backing->newQuery($type))->targets('employees'))->toBeTrue();
});

it('asks for rows of a native object type only inside the tenant of the object type', function (): void {
    config()->set('engine.native_backings', ['employees' => Employee::class]);
    AccessContext::forgetTenant();

    $type = ($this->objectType)('employees', StorageStrategy::Native);
    $backing = ($this->backingFor)($type);

    $shape = QueryShape::attemptedBy(static fn (): bool => $backing->hasRows($type));

    expect($shape?->targets('employees'))->toBeTrue()
        ->and($shape?->isScopedToTenant('employees', (string) $this->tenant->getKey()))->toBeTrue();
});

it('keeps the row check of a tenant scoped native object type inside its tenant after lifting the global scopes', function (): void {
    config()->set('engine.native_backings', ['segments' => Segment::class]);

    $otherTenantId = ModelStub::ulid('other-tenant');
    $type = ($this->objectType)('segments', StorageStrategy::Native, $otherTenantId);
    $backing = ($this->backingFor)($type);

    $shape = QueryShape::attemptedBy(static fn (): bool => $backing->hasRows($type));

    expect($shape?->targets('segments'))->toBeTrue()
        ->and($shape?->isScopedToTenant('segments', $otherTenantId))->toBeTrue()
        ->and($shape?->hasBinding((string) $this->tenant->getKey()))->toBeFalse();
});

it('mints the record permissions of a generic object type from its slug', function (): void {
    $type = ($this->objectType)('companies', StorageStrategy::Generic);

    expect(($this->backingFor)($type)->permissionFor($type, CrudAction::View))->toBe('companies.view')
        ->and(($this->backingFor)($type)->permissionFor($type, CrudAction::Delete))->toBe('companies.delete')
        ->and(($this->backingFor)($type)->indexPath($type))->toBe('/records/companies');
});

it('offers the record capabilities on a generic object type only', function (): void {
    config()->set('engine.native_backings', ['employees' => Employee::class]);

    $generic = ($this->backingFor)(($this->objectType)('companies', StorageStrategy::Generic));
    $native = ($this->backingFor)(($this->objectType)('employees', StorageStrategy::Native));

    expect($generic->supports(ObjectTypeCapability::Records))->toBeTrue()
        ->and($native->supports(ObjectTypeCapability::Records))->toBeFalse()
        ->and($native->supports(ObjectTypeCapability::Relations))->toBeTrue();
});

it('keeps the backing of one tenant apart from an object type with the same slug in another tenant', function (): void {
    config()->set('engine.native_backings', ['employees' => Employee::class]);

    $registry = new ObjectTypeBackingRegistry;
    $native = ($this->objectType)('employees', StorageStrategy::Native);
    $generic = ($this->objectType)('employees', StorageStrategy::Generic, ModelStub::ulid('other-tenant'));

    expect($registry->for($native))->toBeInstanceOf(EloquentModelBacking::class)
        ->and($registry->for($generic))->toBeInstanceOf(GenericRecordBacking::class);
});

it('pins the native query of a model without a tenant scope to the tenant of its object type', function (): void {
    config()->set('engine.native_backings', ['employees' => Employee::class]);

    $otherTenantId = ModelStub::ulid('other-tenant');
    $type = ($this->objectType)('employees', StorageStrategy::Native, $otherTenantId);
    $backing = ($this->backingFor)($type);

    $shape = QueryShape::of($backing->newQuery($type)->whereKey(ModelStub::ulid('employee')));

    expect($shape->isScopedToTenant('employees', $otherTenantId))->toBeTrue()
        ->and($shape->hasBinding((string) $this->tenant->getKey()))->toBeFalse();
});

it('leaves the tenant filter of a tenant scoped model to its global scope', function (): void {
    config()->set('engine.native_backings', ['segments' => Segment::class]);

    $type = ($this->objectType)('segments', StorageStrategy::Native);

    $shape = QueryShape::of(($this->backingFor)($type)->newQuery($type));

    expect($shape->isScopedToTenant('segments', (string) $this->tenant->getKey()))->toBeTrue()
        ->and(substr_count($shape->sql, '"tenant_id"'))->toBe(1);
});

it('refuses to bind a model that carries no tenant at all', function (): void {
    config()->set('engine.native_backings', ['devices' => Device::class]);

    expect(fn (): ObjectTypeBackingInterface => ($this->backingFor)(($this->objectType)('devices', StorageStrategy::Native)))
        ->toThrow(UntenantedObjectTypeBackingException::class);
});
