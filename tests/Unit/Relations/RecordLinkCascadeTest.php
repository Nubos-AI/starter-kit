<?php

declare(strict_types=1);

use App\Models\CustomRecord;
use App\Models\ObjectType;
use App\Models\RecordLink;
use App\Observers\RecordLinkCascadeObserver;
use App\Support\Engine\ObjectTypeRegistry;
use App\Support\Engine\RecordLinkCascade;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Config;
use Tests\Fixtures\Records\Employee;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();

    $this->objectType = ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('companies'),
        'tenant_id' => $this->tenant->getKey(),
        'slug' => 'companies',
    ]);

    $this->record = ModelStub::make(CustomRecord::class, [
        'id' => ModelStub::ulid('company-record'),
        'tenant_id' => $this->tenant->getKey(),
        'object_type_id' => $this->objectType->getKey(),
    ]);

    $this->cascade = Mockery::mock(RecordLinkCascade::class);
    $this->registry = Mockery::mock(ObjectTypeRegistry::class);
    $this->observer = new RecordLinkCascadeObserver($this->cascade, $this->registry);
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('addresses both ends of an edge and ignores the tenant scope while purging', function (): void {
    $shape = QueryShape::attemptedBy(fn () => (new RecordLinkCascade)->purge(
        (string) $this->objectType->getKey(),
        (string) $this->record->getKey(),
    ));

    expect($shape)->not->toBeNull()
        ->and($shape->sql)->toContain('delete from "record_links"')
        ->and($shape->sql)->toContain('"from_record_type" = ?')
        ->and($shape->sql)->toContain('"from_record_id" = ?')
        ->and($shape->sql)->toContain('"to_record_type" = ?')
        ->and($shape->sql)->toContain('"to_record_id" = ?')
        ->and($shape->sql)->not->toContain('"record_links"."tenant_id"')
        ->and($shape->bindings)->toBe([
            (string) $this->objectType->getKey(),
            (string) $this->record->getKey(),
            (string) $this->objectType->getKey(),
            (string) $this->record->getKey(),
        ]);
});

it('keeps the edges of a record that was only soft-deleted', function (): void {
    $this->cascade->shouldNotReceive('purge');

    $this->observer->deleted($this->record);
});

it('purges the edges of a purged record under its own object type', function (): void {
    $record = $this->record;
    (function (): void {
        $this->forceDeleting = true;
    })->call($record);

    $this->cascade->shouldReceive('purge')->once()
        ->with((string) $this->objectType->getKey(), (string) $record->getKey());

    $this->observer->deleted($record);
});

it('purges the edges of a natively backed row through the object type its class declares', function (): void {
    $employee = ModelStub::make(Employee::class, ['id' => ModelStub::ulid('employee')]);

    $this->registry->shouldReceive('bySlug')->once()->with('employees')->andReturn($this->objectType);
    $this->cascade->shouldReceive('purge')->once()
        ->with((string) $this->objectType->getKey(), (string) $employee->getKey());

    $this->observer->deleted($employee);
});

it('purges the edges of a natively backed row the configuration maps to a slug', function (): void {
    Config::set('engine.native_backings', ['contacts' => RecordLink::class]);

    $native = ModelStub::make(RecordLink::class, ['id' => ModelStub::ulid('contact')]);

    $this->registry->shouldReceive('bySlug')->once()->with('contacts')->andReturn($this->objectType);
    $this->cascade->shouldReceive('purge')->once()
        ->with((string) $this->objectType->getKey(), (string) $native->getKey());

    $this->observer->deleted($native);
});

it('purges nothing for a native row whose object type is gone', function (): void {
    $this->registry->shouldReceive('bySlug')->once()->andThrow(new ModelNotFoundException);
    $this->cascade->shouldNotReceive('purge');

    $this->observer->deleted(ModelStub::make(Employee::class, ['id' => ModelStub::ulid('employee')]));
});

it('purges nothing for a model that backs no object type at all', function (): void {
    Config::set('engine.native_backings', []);

    $this->registry->shouldNotReceive('bySlug');
    $this->cascade->shouldNotReceive('purge');

    $this->observer->deleted(ModelStub::make(RecordLink::class, ['id' => ModelStub::ulid('contact')]));
});
