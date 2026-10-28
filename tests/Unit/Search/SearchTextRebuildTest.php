<?php

declare(strict_types=1);

use App\Actions\Engine\ChangeFieldTypeAction;
use App\Actions\Engine\CreateFieldDefinitionAction;
use App\Actions\Engine\DeleteFieldDefinitionAction;
use App\Actions\Engine\RebuildRecordSearchTextAction;
use App\Actions\Engine\RollbackDefinitionAction;
use App\Actions\Engine\SnapshotDefinitionAction;
use App\Actions\Engine\StartRecordBackfillAction;
use App\Actions\Engine\UpdateFieldDefinitionAction;
use App\Enums\CustomFields\FieldType;
use App\Enums\Engine\RecordBackfillKind;
use App\Enums\Engine\StorageStrategy;
use App\Handlers\Engine\SearchTextBackfillStrategy;
use App\Models\CustomRecord;
use App\Models\FieldDefinition;
use App\Models\ObjectType;
use App\Models\ObjectTypeDefinitionVersion;
use App\Support\Engine\IndexRegistry;
use App\Support\Engine\RecordBackfillStrategyRegistry;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\DatabasePresenceVerifier;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->tenantId = (string) $this->tenant->getKey();
    $this->objectTypeId = ModelStub::ulid('rebuild-object-type');

    $this->objectTypeRow = [
        'id' => $this->objectTypeId,
        'tenant_id' => $this->tenantId,
        'slug' => 'projects',
        'key' => 'projects',
        'is_system' => false,
        'storage_strategy' => StorageStrategy::Generic->value,
    ];

    $this->objectType = ModelStub::make(ObjectType::class, $this->objectTypeRow);

    $this->hasRecords = true;

    $this->connection = StaticQueryConnection::install(
        fn (string $sql): array => match (true) {
            str_contains($sql, 'select exists(') => [['exists' => $this->hasRecords]],
            str_contains($sql, 'count(*) as "aggregate"') => [['aggregate' => 1]],
            str_contains($sql, 'from "object_types"') => [$this->objectTypeRow],
            default => [],
        },
        static fn (): int => 1,
    );

    app('validator')->setPresenceVerifier(new DatabasePresenceVerifier($this->connection));
    app()->instance(IndexRegistry::class, Mockery::mock(IndexRegistry::class)->shouldIgnoreMissing());

    $this->rebuilt = [];
    $rebuild = Mockery::mock(RebuildRecordSearchTextAction::class);
    $rebuild->shouldReceive('execute')->andReturnUsing(function (ObjectType $objectType): void {
        $this->rebuilt[] = (string) $objectType->getKey();
    });
    app()->instance(RebuildRecordSearchTextAction::class, $rebuild);

    /** @var callable(array<string, mixed>):FieldDefinition */
    $this->field = fn (array $attributes = []): FieldDefinition => ModelStub::make(FieldDefinition::class, [
        'id' => ModelStub::ulid('rebuild-field'),
        'tenant_id' => $this->tenantId,
        'object_type_id' => $this->objectTypeId,
        'key' => 'titel',
        'field_type' => FieldType::TextShort,
        'is_required' => false,
        'is_unique' => false,
        'is_searchable' => false,
        'is_translatable' => false,
        'is_encrypted' => false,
        'is_sortable' => true,
        'is_filterable' => true,
        ...$attributes,
    ], ['objectType' => $this->objectType]);
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    app()->forgetInstance(IndexRegistry::class);
    app()->forgetInstance(RebuildRecordSearchTextAction::class);
    app()->forgetInstance(StartRecordBackfillAction::class);
    AccessContext::forgetTenant();
    Mockery::close();
});

it('starts the search text backfill for every record of a generic object type', function (): void {
    app()->forgetInstance(RebuildRecordSearchTextAction::class);

    $start = Mockery::mock(StartRecordBackfillAction::class);
    $start->shouldReceive('execute')->once()->with($this->tenantId, RecordBackfillKind::SearchText, $this->objectTypeId)->andReturn('run');
    app()->instance(StartRecordBackfillAction::class, $start);

    app(RebuildRecordSearchTextAction::class)->execute($this->objectType);
});

it('starts nothing and writes no run for an object type that holds no record yet', function (): void {
    app()->forgetInstance(RebuildRecordSearchTextAction::class);
    $this->hasRecords = false;

    $start = Mockery::mock(StartRecordBackfillAction::class);
    $start->shouldNotReceive('execute');
    app()->instance(StartRecordBackfillAction::class, $start);

    app(RebuildRecordSearchTextAction::class)->execute($this->objectType);

    $probe = collect($this->connection->queries)->first(static fn (array $query): bool => str_contains($query['sql'], 'select exists('));

    expect($probe)->not->toBeNull()
        ->and($probe['sql'])->toBe('select exists(select * from "custom_records" where "tenant_id" = ? and "object_type_id" = ?) as "exists"')
        ->and($probe['bindings'])->toBe([$this->tenantId, $this->objectTypeId])
        ->and($this->connection->writtenStatements)->toBe([]);
});

it('starts nothing for an object type whose records do not live in the generic records table', function (): void {
    app()->forgetInstance(RebuildRecordSearchTextAction::class);

    $start = Mockery::mock(StartRecordBackfillAction::class);
    $start->shouldNotReceive('execute');
    app()->instance(StartRecordBackfillAction::class, $start);

    app(RebuildRecordSearchTextAction::class)->execute(
        ModelStub::make(ObjectType::class, [...$this->objectTypeRow, 'storage_strategy' => StorageStrategy::Native->value]),
    );
});

it('registers a backfill strategy that rewrites the search text of a whole batch in one statement', function (): void {
    $strategy = app(RecordBackfillStrategyRegistry::class)->for(RecordBackfillKind::SearchText);

    $records = new Collection([
        ModelStub::make(CustomRecord::class, ['id' => ModelStub::ulid('rebuild-a'), 'tenant_id' => $this->tenantId, 'object_type_id' => $this->objectTypeId]),
        ModelStub::make(CustomRecord::class, ['id' => ModelStub::ulid('rebuild-b'), 'tenant_id' => $this->tenantId, 'object_type_id' => $this->objectTypeId]),
    ]);

    expect($strategy)->toBeInstanceOf(SearchTextBackfillStrategy::class)
        ->and($strategy->batchSize())->toBe((int) config('engine.backfill.search_text_batch_size'))
        ->and($strategy->applyBatch($this->tenantId, $records))->toBe(0)
        ->and($this->connection->writtenStatements)->toBe([[
            'sql' => 'update "custom_records" set "search_text" = concat_ws(chr(31), record_number, external_reference_id) where "tenant_id" = ? and "object_type_id" = ? and "id" in (?, ?)',
            'bindings' => [$this->tenantId, $this->objectTypeId, ModelStub::ulid('rebuild-a'), ModelStub::ulid('rebuild-b')],
        ]]);
});

it('rebuilds the search text when a change decides whether the field is searched', function (array $before, array $change): void {
    FieldDefinition::withoutEvents(fn (): FieldDefinition => app(UpdateFieldDefinitionAction::class)
        ->execute(($this->field)($before), $change));

    expect($this->rebuilt)->toBe([$this->objectTypeId]);
})->with([
    'switched on' => [[], ['is_searchable' => true]],
    'switched off' => [['is_searchable' => true], ['is_searchable' => false]],
    'encrypted' => [['is_searchable' => true, 'is_sortable' => false, 'is_filterable' => false], ['is_encrypted' => true]],
    'made translatable' => [['is_searchable' => true], ['is_translatable' => true]],
]);

it('rebuilds nothing for a change that leaves the searched fields as they are', function (array $before, array $change): void {
    FieldDefinition::withoutEvents(fn (): FieldDefinition => app(UpdateFieldDefinitionAction::class)
        ->execute(($this->field)($before), $change));

    expect($this->rebuilt)->toBe([]);
})->with([
    'sorting only' => [['is_searchable' => true], ['is_sortable' => false]],
    'searchable again' => [['is_searchable' => true], ['is_searchable' => true]],
    'not free text' => [['field_type' => FieldType::Date], ['is_searchable' => true]],
]);

it('rebuilds the search text when a searchable field is created and only then', function (bool $isSearchable, array $expected): void {
    ObjectType::withoutEvents(fn (): FieldDefinition => FieldDefinition::withoutEvents(
        fn (): FieldDefinition => app(CreateFieldDefinitionAction::class)->execute([
            'object_type_id' => $this->objectTypeId,
            'key' => 'industry',
            'field_type' => FieldType::TextShort->value,
            'is_searchable' => $isSearchable,
        ]),
    ));

    expect($this->rebuilt)->toBe($expected);
})->with([
    'searchable' => [true, [ModelStub::ulid('rebuild-object-type')]],
    'not searchable' => [false, []],
]);

it('rebuilds the search text when a searchable field is deleted and only then', function (bool $isSearchable, array $expected): void {
    FieldDefinition::withoutEvents(fn () => app(DeleteFieldDefinitionAction::class)
        ->execute(($this->field)(['is_searchable' => $isSearchable])));

    expect($this->rebuilt)->toBe($expected);
})->with([
    'searchable' => [true, [ModelStub::ulid('rebuild-object-type')]],
    'not searchable' => [false, []],
]);

it('rebuilds the search text when a type change moves a field in or out of the search', function (FieldType $from, FieldType $to, array $expected): void {
    $snapshot = Mockery::mock(SnapshotDefinitionAction::class);
    $snapshot->shouldReceive('execute')->andReturn(ModelStub::make(ObjectTypeDefinitionVersion::class, [
        'tenant_id' => $this->tenantId,
        'object_type_id' => $this->objectTypeId,
    ]));
    app()->instance(SnapshotDefinitionAction::class, $snapshot);

    FieldDefinition::withoutEvents(fn (): array => app(ChangeFieldTypeAction::class)
        ->execute(($this->field)(['field_type' => $from, 'is_searchable' => true]), $to));

    app()->forgetInstance(SnapshotDefinitionAction::class);

    expect($this->rebuilt)->toBe($expected);
})->with([
    'out of the search' => [FieldType::TextShort, FieldType::Date, [ModelStub::ulid('rebuild-object-type')]],
    'into the search' => [FieldType::Date, FieldType::TextShort, [ModelStub::ulid('rebuild-object-type')]],
    'stays outside' => [FieldType::Date, FieldType::DateTime, []],
]);

it('rebuilds the search text after a definition rollback', function (): void {
    $snapshot = Mockery::mock(SnapshotDefinitionAction::class);
    $snapshot->shouldReceive('execute')->andReturn(ModelStub::make(ObjectTypeDefinitionVersion::class, [
        'tenant_id' => $this->tenantId,
        'object_type_id' => $this->objectTypeId,
        'version_number' => 2,
    ]));
    app()->instance(SnapshotDefinitionAction::class, $snapshot);

    $target = ModelStub::make(ObjectTypeDefinitionVersion::class, [
        'tenant_id' => $this->tenantId,
        'object_type_id' => $this->objectTypeId,
        'version_number' => 1,
        'snapshot' => ['object_type' => [], 'field_definitions' => []],
    ]);

    ObjectType::withoutEvents(fn (): array => app(RollbackDefinitionAction::class)->execute($this->objectType, $target));

    app()->forgetInstance(SnapshotDefinitionAction::class);

    expect($this->rebuilt)->toBe([$this->objectTypeId]);
});
