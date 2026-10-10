<?php

declare(strict_types=1);

use App\Contracts\Engine\ObjectTypeBackingInterface;
use App\Enums\Authorization\CrudAction;
use App\Enums\Engine\ObjectTypeCapability;
use App\Enums\Engine\StorageStrategy;
use App\Exceptions\Engine\MissingObjectTypeBackingException;
use App\Models\CustomRecord;
use App\Models\ObjectType;
use App\Support\Engine\EloquentModelBacking;
use App\Support\Engine\GenericRecordBacking;
use App\Support\Engine\ObjectTypeBackingRegistry;
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

    /** @var callable(string, StorageStrategy):ObjectType */
    $this->objectType = fn (string $slug, StorageStrategy $strategy): ObjectType => ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('type-'.$slug),
        'tenant_id' => $this->tenant->getKey(),
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

it('names the primary key a generic backing addresses its rows by', function (): void {
    expect(($this->backingFor)(($this->objectType)('companies', StorageStrategy::Generic))->keyName())
        ->toBe((new CustomRecord)->getKeyName());
});
