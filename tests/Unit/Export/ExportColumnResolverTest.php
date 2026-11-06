<?php

declare(strict_types=1);

use App\Enums\CustomFields\FieldType;
use App\Enums\Export\ExportIdentityColumn;
use App\Models\CustomRecord;
use App\Models\FieldDefinition;
use App\Models\ObjectType;
use App\Models\RelationshipType;
use App\Support\Engine\ObjectTypeFieldLookup;
use App\Support\Engine\ObjectTypeRegistry;
use App\Support\Engine\RecordExchangeIdentity;
use App\Support\Export\ExportColumnResolver;
use App\Support\I18n\TranslatableValueResolver;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\FakeFieldVisibilityResolver;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->tenantId = (string) $this->tenant->getKey();

    $this->relationshipTypeId = ModelStub::ulid('rel');

    /** @var callable(string, FieldType, array<string, mixed>, bool):FieldDefinition */
    $this->field = fn (string $key, FieldType $type = FieldType::TextShort, array $config = [], bool $encrypted = false): FieldDefinition => ModelStub::make(FieldDefinition::class, [
        'id' => ModelStub::ulid("field-{$key}"),
        'tenant_id' => $this->tenantId,
        'object_type_id' => ModelStub::ulid('companies'),
        'key' => $key,
        'field_type' => $type,
        'config' => $config,
        'is_encrypted' => $encrypted,
        'list_position' => 1,
        'i18n_labels' => ['de' => mb_strtoupper($key)],
    ]);

    $this->fields = [
        ($this->field)('name'),
        ($this->field)('secret', FieldType::TextShort, [], true),
        ($this->field)('salary'),
        ($this->field)('contacts', FieldType::RelationHasMany, ['relationship_type_id' => $this->relationshipTypeId]),
    ];

    $this->objectType = ModelStub::make(
        ObjectType::class,
        ['id' => ModelStub::ulid('companies'), 'tenant_id' => $this->tenantId, 'slug' => 'companies'],
        ['fieldDefinitions' => new EloquentCollection($this->fields)],
    );

    $this->targetType = ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('contacts'),
        'tenant_id' => $this->tenantId,
        'slug' => 'contacts',
    ]);

    $this->relationshipType = ModelStub::make(RelationshipType::class, [
        'id' => $this->relationshipTypeId,
        'tenant_id' => $this->tenantId,
        'from_object_type_id' => (string) $this->objectType->getKey(),
        'to_object_type_id' => (string) $this->targetType->getKey(),
    ]);

    $this->exchangeIdentity = Mockery::mock(RecordExchangeIdentity::class);
    $this->registry = Mockery::mock(ObjectTypeRegistry::class);
    $this->registry->shouldReceive('find')->andReturn($this->targetType)->byDefault();
    $this->fieldLookup = Mockery::mock(ObjectTypeFieldLookup::class);
    $this->fieldLookup->shouldReceive('relationship')->andReturn($this->relationshipType)->byDefault();

    $this->resolver = new ExportColumnResolver(
        new TranslatableValueResolver,
        $this->exchangeIdentity,
        $this->registry,
        $this->fieldLookup,
    );

    /** @var callable(list<string>, list<string>, array<int|string, mixed>):array{headers: list<string>, descriptors: list<array<string, mixed>>} */
    $this->planFor = function (array $abilities, array $forbiddenRead = [], array $selected = []): array {
        new FakeFieldVisibilityResolver($forbiddenRead);

        $user = AccessContext::actAs(AccessContext::user($this->tenant, [], 'exporter'));
        AccessContext::grant(...$abilities);

        return $this->resolver->resolve($user, $this->objectType, $selected);
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();
    Mockery::close();
});

it('offers the identity columns first and then the fields of the object type', function (): void {
    $plan = ($this->planFor)(['contacts.view']);

    expect(array_column($plan['descriptors'], 'type'))
        ->toBe(['attribute', 'attribute', 'relation', 'data', 'data'])
        ->and($plan['descriptors'][0]['attr'])->toBe(ExportIdentityColumn::ExternalReferenceId->value)
        ->and($plan['descriptors'][1]['attr'])->toBe(ExportIdentityColumn::RecordNumber->value);
});

it('never offers an encrypted field as a column', function (): void {
    $plan = ($this->planFor)(['contacts.view']);

    expect(array_column($plan['descriptors'], 'key'))->not->toContain('secret')
        ->and($plan['headers'])->not->toContain('SECRET');
});

it('never offers a field the caller may not read as a column', function (): void {
    $plan = ($this->planFor)(['contacts.view'], ['salary']);

    expect(array_column($plan['descriptors'], 'key'))->not->toContain('salary')
        ->and($plan['headers'])->not->toContain('SALARY');
});

it('drops a field the caller may not read even when they asked for it by name', function (): void {
    $plan = ($this->planFor)(['contacts.view'], ['salary'], ['name', 'salary']);

    expect($plan['headers'])->toBe(['NAME']);
});

it('keeps the selected columns in the order the caller asked for', function (): void {
    $plan = ($this->planFor)(['contacts.view'], [], ['salary', 'record_number', 'name']);

    expect($plan['headers'])->toBe(['SALARY', __('i18n.backend.enums.export.export_identity_column.record_number'), 'NAME']);
});

it('keeps a relation column but empties it when the caller may not view the target type', function (): void {
    $plan = ($this->planFor)([], [], ['contacts']);

    $record = ModelStub::make(CustomRecord::class, [
        'id' => ModelStub::ulid('record'),
        'tenant_id' => $this->tenantId,
        'object_type_id' => (string) $this->objectType->getKey(),
        'data' => [],
    ]);

    $rows = null;

    $shape = QueryShape::attemptedBy(function () use ($plan, $record, &$rows): void {
        $rows = $this->resolver->rowsForChunk($plan, new EloquentCollection([$record]), $this->tenantId);
    });

    expect($plan['descriptors'][0]['relationshipTypeId'])->toBeNull()
        ->and($plan['headers'])->toBe(['CONTACTS'])
        ->and($rows)->toBe([['CONTACTS' => null]])
        ->and($shape)->toBeNull();
});

it('reads the links of a relation column once the caller may view the target type', function (): void {
    $plan = ($this->planFor)(['contacts.view'], [], ['contacts']);

    $record = ModelStub::make(CustomRecord::class, [
        'id' => ModelStub::ulid('record'),
        'tenant_id' => $this->tenantId,
        'object_type_id' => (string) $this->objectType->getKey(),
        'data' => [],
    ]);

    $shape = QueryShape::attemptedBy(fn (): array => $this->resolver->rowsForChunk(
        $plan,
        new EloquentCollection([$record]),
        $this->tenantId,
    ));

    expect($plan['descriptors'][0]['relationshipTypeId'])->toBe($this->relationshipTypeId)
        ->and($shape)->not->toBeNull()
        ->and($shape->targets('record_links'))->toBeTrue()
        ->and($shape->hasBinding($this->tenantId))->toBeTrue()
        ->and($shape->hasBinding($this->relationshipTypeId))->toBeTrue();
});

it('empties a relation column whose relationship type no longer exists', function (): void {
    $this->fieldLookup->shouldReceive('relationship')->andReturnNull();

    $plan = ($this->planFor)(['contacts.view'], [], ['contacts']);

    expect($plan['descriptors'][0]['relationshipTypeId'])->toBeNull()
        ->and($plan['headers'])->toBe(['CONTACTS']);
});
