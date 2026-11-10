<?php

declare(strict_types=1);

use App\Actions\Engine\CreateRecordAction;
use App\Actions\Engine\UpdateRecordAction;
use App\Actions\Engine\UpdateRecordCellAction;
use App\Enums\CustomFields\FieldType;
use App\Models\CustomRecord;
use App\Models\FieldDefinition;
use App\Models\ObjectType;
use App\Support\Authorization\FieldVisibilityResolver;
use App\Support\Engine\AuditRecorder;
use App\Support\Engine\ComputedFieldWriter;
use App\Support\Engine\DefaultValueResolver;
use App\Support\Engine\ObjectTypeRegistry;
use App\Support\Engine\RecordNumberFormatter;
use App\Support\Engine\RecordSearchText;
use App\Support\Engine\RecordValidator;
use App\Support\Engine\RollupRecomputer;
use App\Support\Modules\RecordExtensions;
use App\Support\Watchers\WatcherAutoSubscriber;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\FakeFieldVisibilityResolver;
use Tests\Support\Doubles\FakePresenceVerifier;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->objectTypeId = ModelStub::ulid('derived-object-type');

    AccessContext::actAs(AccessContext::user($this->tenant));
    new FakeFieldVisibilityResolver;

    $this->fieldRow = fn (string $key, FieldType $type): array => [
        'id' => ModelStub::ulid("derived-field-{$key}"),
        'tenant_id' => (string) $this->tenant->getKey(),
        'object_type_id' => $this->objectTypeId,
        'key' => $key,
        'field_type' => $type->value,
        'is_encrypted' => false,
    ];

    $this->fieldRows = [
        ($this->fieldRow)('title', FieldType::TextShort),
        ($this->fieldRow)('score', FieldType::Rollup),
        ($this->fieldRow)('total', FieldType::Computed),
    ];

    $this->objectType = ModelStub::make(ObjectType::class, [
        'id' => $this->objectTypeId,
        'tenant_id' => $this->tenant->getKey(),
        'slug' => 'deal',
    ], [
        'fieldDefinitions' => ModelStub::collection(FieldDefinition::class, $this->fieldRows),
    ]);

    $this->record = ModelStub::make(CustomRecord::class, [
        'id' => ModelStub::ulid('derived-record'),
        'tenant_id' => $this->tenant->getKey(),
        'object_type_id' => $this->objectTypeId,
        'version' => 2,
        'data' => ['title' => 'Alt', 'score' => 7, 'total' => 21],
    ], ['objectType' => $this->objectType]);

    $this->validated = null;

    $this->recordValidator = Mockery::mock(RecordValidator::class);
    $this->recordValidator->shouldReceive('validate')
        ->andReturnUsing(function (ObjectType $type, array $data): never {
            $this->validated = $data;

            throw ValidationException::withMessages(['data' => 'stop']);
        });

    $registry = Mockery::mock(ObjectTypeRegistry::class);
    $registry->shouldReceive('forRecord')->andReturn($this->objectType);
    $registry->shouldReceive('fields')->andReturn($this->objectType->fieldDefinitions);

    $this->updateAction = new UpdateRecordAction(
        $registry,
        $this->recordValidator,
        Mockery::mock(AuditRecorder::class),
        Mockery::mock(WatcherAutoSubscriber::class),
        Mockery::mock(ComputedFieldWriter::class),
        Mockery::mock(RecordExtensions::class),
        Mockery::mock(RecordSearchText::class)->shouldIgnoreMissing(),
    );

    $this->submitted = ['title' => 'Neu', 'score' => 999, 'total' => -1, 'ghost' => 'nicht definiert'];
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    AccessContext::forgetTenant();
    app()->forgetInstance(FieldVisibilityResolver::class);
    Mockery::close();
});

it('never stores a rollup, a computed value or an undefined key that an update sends', function (): void {
    expect(fn (): CustomRecord => $this->updateAction->execute($this->record, [
        'version' => 2,
        'data' => $this->submitted,
    ]))->toThrow(ValidationException::class)
        ->and($this->validated)->toBe(['title' => 'Neu', 'score' => 7, 'total' => 21]);
});

it('ignores a cell edit that targets a rollup field or a key the object type does not define', function (string $field): void {
    $cellAction = new UpdateRecordCellAction($this->updateAction);

    expect(fn (): CustomRecord => $cellAction->execute($this->record, ['field' => $field, 'value' => 12345, 'version' => 2]))
        ->toThrow(ValidationException::class)
        ->and($this->validated)->toBe(['title' => 'Alt', 'score' => 7, 'total' => 21]);
})->with(['rollup field' => 'score', 'undefined key' => 'ghost']);

it('creates a record with the defined writable fields only', function (): void {
    StaticQueryConnection::install(fn (string $sql): array => match (true) {
        str_contains($sql, 'from "object_types"') => [[
            'id' => $this->objectTypeId,
            'tenant_id' => (string) $this->tenant->getKey(),
            'slug' => 'deal',
        ]],
        str_contains($sql, 'from "field_definitions"') => $this->fieldRows,
        default => [],
    });

    FakePresenceVerifier::holding(['object_types' => [$this->objectTypeId]]);

    $createAction = new CreateRecordAction(
        Mockery::mock(RecordNumberFormatter::class),
        $this->recordValidator,
        app(DefaultValueResolver::class),
        Mockery::mock(AuditRecorder::class),
        Mockery::mock(ComputedFieldWriter::class),
        Mockery::mock(RecordExtensions::class),
        Mockery::mock(RollupRecomputer::class),
        Mockery::mock(RecordSearchText::class),
    );

    expect(fn (): CustomRecord => $createAction->execute([
        'object_type_id' => $this->objectTypeId,
        'data' => $this->submitted,
    ]))->toThrow(ValidationException::class)
        ->and($this->validated)->toBe(['title' => 'Neu']);
});
