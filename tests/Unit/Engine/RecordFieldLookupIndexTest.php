<?php

declare(strict_types=1);

use App\Enums\CustomFields\FieldType;
use App\Models\FieldDefinition;
use App\Models\ObjectType;
use App\Support\Engine\IndexRegistry;
use App\Support\Engine\ObjectTypeRegistry;
use Illuminate\Support\Collection;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();

    $this->objectTypeId = ModelStub::ulid('companies');

    /** @var callable(string, FieldType):FieldDefinition */
    $this->field = fn (string $key, FieldType $type): FieldDefinition => ModelStub::make(FieldDefinition::class, [
        'id' => ModelStub::ulid('field-'.$key),
        'tenant_id' => $this->tenant->getKey(),
        'object_type_id' => $this->objectTypeId,
        'key' => $key,
        'field_type' => $type,
        'is_encrypted' => false,
        'is_translatable' => false,
        'is_filterable' => true,
    ]);

    /** @var callable(array<string, FieldDefinition>):void */
    $this->declare = function (array $fields): void {
        $registry = Mockery::mock(ObjectTypeRegistry::class);
        $registry->shouldReceive('field')
            ->andReturnUsing(static fn (string $objectTypeId, string $key): ?FieldDefinition => $fields[$key] ?? null);

        app()->instance(ObjectTypeRegistry::class, $registry);
    };

    /** @var callable(list<mixed>):ObjectType */
    $this->objectType = fn (array $dedupKeys): ObjectType => ModelStub::make(ObjectType::class, [
        'id' => $this->objectTypeId,
        'tenant_id' => $this->tenant->getKey(),
        'slug' => 'companies',
        'dedup_keys' => $dedupKeys,
    ]);
});

afterEach(function (): void {
    AccessContext::forgetTenant();
    app()->forgetInstance(ObjectTypeRegistry::class);
});

it('compares a numeric dedup key through the same numeric expression the index carries', function (): void {
    $field = ($this->field)('headcount', FieldType::Number);
    ($this->declare)(['headcount' => $field]);

    $objectType = ($this->objectType)([['headcount']]);

    $attempt = QueryShape::attemptedBy(fn (): Collection => $objectType->findDuplicates(
        ['headcount' => 500.5],
        (string) $this->tenant->getKey(),
    ));

    expect($attempt)->not->toBeNull()
        ->and($attempt?->sql)->toContain(app(IndexRegistry::class)->indexExpression($field, 'headcount'))
        ->and($attempt?->bindings)->toContain(500.5);
});

it('keeps the duplicate search inside the tenant although it drops the global scopes', function (): void {
    ($this->declare)(['email' => ($this->field)('email', FieldType::Email)]);

    $objectType = ($this->objectType)([['email']]);

    $attempt = QueryShape::attemptedBy(fn (): Collection => $objectType->findDuplicates(
        ['email' => 'gleich@nubos.de'],
        (string) $this->tenant->getKey(),
    ));

    expect($attempt)->not->toBeNull()
        ->and($attempt?->sql)->toContain('"tenant_id" = ?')
        ->and($attempt?->hasBinding((string) $this->tenant->getKey()))->toBeTrue()
        ->and($attempt?->hasColumnCondition('custom_records', 'object_type_id'))->toBeTrue()
        ->and($attempt?->hasBinding($this->objectTypeId))->toBeTrue()
        ->and($attempt?->sql)->toContain('"deleted_at" is null');
});

it('searches for nothing when the values do not cover every key of a group', function (): void {
    ($this->declare)([
        'email' => ($this->field)('email', FieldType::Email),
        'city' => ($this->field)('city', FieldType::TextShort),
    ]);

    $objectType = ($this->objectType)([['email', 'city']]);

    $attempt = QueryShape::attemptedBy(fn (): Collection => $objectType->findDuplicates(
        ['email' => 'gleich@nubos.de'],
        (string) $this->tenant->getKey(),
    ));

    expect($attempt)->toBeNull()
        ->and($objectType->findDuplicates(['email' => 'gleich@nubos.de'], (string) $this->tenant->getKey()))
        ->toBeEmpty();
});

it('searches for nothing when a group names a key the object type does not declare', function (): void {
    ($this->declare)([]);

    $objectType = ($this->objectType)([['serial']]);

    expect($objectType->findDuplicates(['serial' => 42], (string) $this->tenant->getKey()))->toBeEmpty();
});

it('searches for nothing when the object type declares no duplicate keys', function (): void {
    ($this->declare)([]);

    expect(($this->objectType)([])->findDuplicates(['email' => 'x'], (string) $this->tenant->getKey()))
        ->toBeEmpty();
});

it('puts every applicable group into its own parenthesised alternative', function (): void {
    ($this->declare)([
        'email' => ($this->field)('email', FieldType::Email),
        'serial' => ($this->field)('serial', FieldType::Number),
    ]);

    $objectType = ($this->objectType)([['email'], ['serial']]);

    $attempt = QueryShape::attemptedBy(fn (): Collection => $objectType->findDuplicates(
        ['email' => 'gleich@nubos.de', 'serial' => 7],
        (string) $this->tenant->getKey(),
    ));

    expect($attempt?->sql)->toContain(' or (')
        ->and($attempt?->bindings)->toContain('gleich@nubos.de')
        ->and($attempt?->bindings)->toContain(7);
});
