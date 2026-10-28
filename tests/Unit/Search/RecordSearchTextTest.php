<?php

declare(strict_types=1);

use App\Enums\CustomFields\FieldType;
use App\Models\CustomRecord;
use App\Models\FieldDefinition;
use App\Support\Engine\ObjectTypeRegistry;
use App\Support\Engine\RecordSearchText;
use Illuminate\Support\Collection;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticObjectTypeRegistry;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->tenantId = (string) $this->tenant->getKey();
    $this->objectTypeId = ModelStub::ulid('search-text-deals');
    $this->searchText = app(RecordSearchText::class);

    /** @var callable(string, array<string, mixed>):FieldDefinition */
    $this->field = fn (string $key, array $attributes = []): FieldDefinition => ModelStub::make(
        FieldDefinition::class,
        [
            'id' => ModelStub::ulid('search-text-field-'.$key),
            'tenant_id' => $this->tenantId,
            'object_type_id' => $this->objectTypeId,
            'key' => $key,
            'field_type' => FieldType::TextShort,
            'is_searchable' => true,
            'is_translatable' => false,
            'is_encrypted' => false,
            ...$attributes,
        ],
    );

    /** @var callable(string, array<string, mixed>):array<string, mixed> */
    $this->fieldRow = fn (string $key, array $attributes = []): array => [
        'id' => ModelStub::ulid('search-text-field-'.$key),
        'key' => $key,
        'field_type' => FieldType::TextShort->value,
        'is_searchable' => true,
        'is_translatable' => false,
        'is_encrypted' => false,
        ...$attributes,
    ];

    $this->record = ModelStub::make(CustomRecord::class, [
        'id' => ModelStub::ulid('search-text-record'),
        'tenant_id' => $this->tenantId,
        'object_type_id' => $this->objectTypeId,
        'version' => 1,
    ]);
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    app()->forgetInstance(ObjectTypeRegistry::class);
    AccessContext::forgetTenant();
});

it('joins the text of every searchable field and both business keys with a unit separator', function (): void {
    $fields = new Collection([($this->field)('titel'), ($this->field)('email', ['field_type' => FieldType::Email])]);

    expect($this->searchText->expression($fields))
        ->toBe("concat_ws(chr(31), (data->>'titel'), (data->>'email'), record_number, external_reference_id)");
});

it('carries only the business keys when the object type has no searchable field', function (): void {
    expect($this->searchText->expression(new Collection))
        ->toBe('concat_ws(chr(31), record_number, external_reference_id)');
});

it('leaves out every field the record search never reads', function (string $key, array $attributes): void {
    $fields = new Collection([($this->field)('titel'), ($this->field)($key, $attributes)]);

    expect($this->searchText->expression($fields))
        ->toBe("concat_ws(chr(31), (data->>'titel'), record_number, external_reference_id)");
})->with([
    'not searchable' => ['intern', ['is_searchable' => false]],
    'encrypted' => ['gehalt', ['is_encrypted' => true]],
    'translatable' => ['beschreibung', ['is_translatable' => true]],
    'file' => ['anhang', ['field_type' => FieldType::File]],
    'rollup' => ['summe', ['field_type' => FieldType::Rollup]],
    'computed' => ['formel', ['field_type' => FieldType::Computed]],
    'geo address' => ['adresse', ['field_type' => FieldType::GeoAddress]],
]);

it('refuses an unsafe field key before it can reach the sql', function (): void {
    $field = ($this->field)('titel');
    $field->key = "x') || pg_sleep(5) || ('";

    expect(fn (): string => $this->searchText->expression(new Collection([$field])))
        ->toThrow(InvalidArgumentException::class);
});

it('rewrites the search text of a record from the request scoped fields of its object type without asking for them again', function (): void {
    $connection = StaticQueryConnection::install(static fn (): array => [], static fn (): int => 1);

    app()->instance(ObjectTypeRegistry::class, new StaticObjectTypeRegistry([], [$this->objectTypeId => new Collection([
        'titel' => ($this->field)('titel'),
        'intern' => ($this->field)('intern', ['is_searchable' => false]),
        'telefon' => ($this->field)('telefon', ['field_type' => FieldType::Phone]),
    ])]));

    app(RecordSearchText::class)->refresh($this->record);

    expect($connection->queries)->toBe([])
        ->and($connection->writtenStatements)->toBe([[
            'sql' => "update \"custom_records\" set \"search_text\" = concat_ws(chr(31), (data->>'titel'), (data->>'telefon'), record_number, external_reference_id) where \"tenant_id\" = ? and \"object_type_id\" = ? and \"id\" in (?)",
            'bindings' => [$this->tenantId, $this->objectTypeId, ModelStub::ulid('search-text-record')],
        ]]);
});

it('takes the fields a caller already loaded and ignores any of another object type', function (): void {
    $connection = StaticQueryConnection::install(static fn (): array => [], static fn (): int => 1);

    $foreign = ($this->field)('fremd');
    $foreign->object_type_id = ModelStub::ulid('search-text-other-type');

    $this->searchText->refresh($this->record, new Collection([($this->field)('titel'), $foreign]));

    expect($connection->queries)->toBe([])
        ->and($connection->writtenStatements[0]['sql'])->toContain("concat_ws(chr(31), (data->>'titel'), record_number, external_reference_id)");
});

it('reads the fields of the record tenant explicitly when no tenant or another one is bound', function (bool $bindsOtherTenant): void {
    $connection = StaticQueryConnection::install(
        fn (string $sql): array => str_contains($sql, 'from "field_definitions"') ? [($this->fieldRow)('titel')] : [],
        static fn (): int => 1,
    );

    AccessContext::forgetTenant();

    if ($bindsOtherTenant) {
        AccessContext::tenant('search-text-other-tenant');
    }

    $this->searchText->refresh($this->record);

    $fieldQuery = collect($connection->queries)->first(static fn (array $query): bool => str_contains($query['sql'], 'from "field_definitions"'));

    expect($fieldQuery['bindings'] ?? null)->toBe([$this->tenantId, $this->objectTypeId])
        ->and($connection->writtenStatements[0]['sql'])->toContain("(data->>'titel')");
})->with([
    'no tenant' => [false],
    'another tenant' => [true],
]);

it('reads the field definitions of the record tenant explicitly so a run without a bound tenant still sees them', function (): void {
    $connection = StaticQueryConnection::install(static fn (): array => [], static fn (): int => 1);

    AccessContext::forgetTenant();

    $this->searchText->refreshMany($this->tenantId, $this->objectTypeId, [ModelStub::ulid('search-text-record')]);

    $fieldQuery = collect($connection->queries)->first(static fn (array $query): bool => str_contains($query['sql'], 'from "field_definitions"'));

    expect($fieldQuery)->not->toBeNull()
        ->and($fieldQuery['sql'])->toContain('"tenant_id" = ?')
        ->and($fieldQuery['sql'])->toContain('"object_type_id" = ?')
        ->and($fieldQuery['sql'])->toContain('"field_definitions"."deleted_at" is null')
        ->and($fieldQuery['sql'])->not->toContain('1 = 0')
        ->and($fieldQuery['bindings'])->toBe([$this->tenantId, $this->objectTypeId]);
});

it('rewrites a whole batch at once without touching the update timestamp and including trashed rows', function (): void {
    $connection = StaticQueryConnection::install(static fn (): array => [], static fn (): int => 2);

    $ids = [ModelStub::ulid('search-text-a'), ModelStub::ulid('search-text-b')];

    expect($this->searchText->refreshMany($this->tenantId, $this->objectTypeId, $ids))->toBe(2);

    $statement = $connection->writtenStatements[0];

    expect($connection->writtenStatements)->toHaveCount(1)
        ->and($statement['sql'])->toBe('update "custom_records" set "search_text" = concat_ws(chr(31), record_number, external_reference_id) where "tenant_id" = ? and "object_type_id" = ? and "id" in (?, ?)')
        ->and($statement['bindings'])->toBe([$this->tenantId, $this->objectTypeId, ...$ids]);
});

it('sends nothing for an empty batch', function (): void {
    $connection = StaticQueryConnection::install(static fn (): array => [], static fn (): int => 0);

    expect($this->searchText->refreshMany($this->tenantId, $this->objectTypeId, []))->toBe(0)
        ->and($connection->queries)->toBe([])
        ->and($connection->writtenStatements)->toBe([]);
});

it('never serialises the search text with a record, because it carries the values of fields a reader may not see', function (): void {
    $record = ModelStub::make(CustomRecord::class, [
        'id' => ModelStub::ulid('search-text-record'),
        'tenant_id' => $this->tenantId,
        'object_type_id' => $this->objectTypeId,
        'search_text' => "Aurora\x1fgeheim",
    ]);

    expect($record->toArray())->not->toHaveKey('search_text');
});
