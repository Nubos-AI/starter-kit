<?php

declare(strict_types=1);

use App\Actions\Engine\CreateRecordAction;
use App\Actions\Engine\UndoRecordMergeAction;
use App\Actions\Engine\UpdateRecordAction;
use App\Enums\CustomFields\FieldType;
use App\Models\CustomRecord;
use App\Models\FieldDefinition;
use App\Models\ObjectType;
use App\Models\RecordMerge;
use App\Support\Authorization\FieldVisibilityResolver;
use App\Support\Engine\AuditRecorder;
use App\Support\Engine\ComputedFieldWriter;
use App\Support\Engine\MergeTransferCounter;
use App\Support\Engine\ObjectTypeRegistry;
use App\Support\Engine\RecordNumberFormatter;
use App\Support\Engine\RecordValidator;
use App\Support\Engine\RollupOwnerStarter;
use App\Support\Engine\RollupRecomputer;
use App\Support\Modules\RecordExtensions;
use App\Support\Timeline\TimelineRecorder;
use App\Support\Watchers\WatcherAutoSubscriber;
use Illuminate\Support\Collection;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\FakePresenceVerifier;
use Tests\Support\Doubles\StaticObjectTypeRegistry;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->tenantId = (string) $this->tenant->getKey();
    $this->objectTypeId = ModelStub::ulid('write-path-deals');
    $this->recordId = ModelStub::ulid('write-path-record');

    $this->objectType = ModelStub::make(ObjectType::class, [
        'id' => $this->objectTypeId,
        'tenant_id' => $this->tenantId,
        'slug' => 'deals',
    ]);

    $this->record = ModelStub::make(CustomRecord::class, [
        'id' => $this->recordId,
        'tenant_id' => $this->tenantId,
        'object_type_id' => $this->objectTypeId,
        'record_number' => 'DEAL-0000000001',
        'version' => 3,
        'data' => ['titel' => 'Alt'],
    ], ['objectType' => $this->objectType]);

    $this->searchTextSql = "update \"custom_records\" set \"search_text\" = concat_ws(chr(31), (data->>'titel'), record_number, external_reference_id) where \"tenant_id\" = ? and \"object_type_id\" = ? and \"id\" in (?)";

    $this->connection = StaticQueryConnection::install(
        fn (string $sql, array $bindings): array => match (true) {
            str_contains($sql, 'from "object_types"') => [['id' => $this->objectTypeId, 'tenant_id' => $this->tenantId, 'slug' => 'deals']],
            str_contains($sql, 'from "field_definitions"') => [[
                'id' => ModelStub::ulid('write-path-titel'),
                'tenant_id' => $this->tenantId,
                'object_type_id' => $this->objectTypeId,
                'key' => 'titel',
                'field_type' => FieldType::TextShort->value,
                'is_searchable' => true,
                'is_translatable' => false,
                'is_encrypted' => false,
            ]],
            str_contains($sql, 'from "custom_records"') => [[
                'id' => $this->recordId,
                'tenant_id' => $this->tenantId,
                'object_type_id' => $this->objectTypeId,
                'record_number' => 'DEAL-0000000001',
                'version' => 4,
                'data' => '{"titel":"Berlin"}',
            ]],
            default => [],
        },
        static fn (): int => 1,
    );

    /** @var callable():list<array{sql: string, bindings: list<mixed>}> */
    $this->searchTextWrites = fn (): array => array_values(array_filter(
        $this->connection->writtenStatements,
        static fn (array $statement): bool => str_contains($statement['sql'], '"search_text"'),
    ));

    $resolver = Mockery::mock(FieldVisibilityResolver::class);
    $resolver->shouldReceive('forbiddenWriteFieldKeys')->andReturn([]);
    app()->instance(FieldVisibilityResolver::class, $resolver);

    app()->instance(RecordValidator::class, Mockery::mock(RecordValidator::class)->shouldIgnoreMissing());
    app()->instance(AuditRecorder::class, Mockery::mock(AuditRecorder::class)->shouldIgnoreMissing());
    app()->instance(WatcherAutoSubscriber::class, Mockery::mock(WatcherAutoSubscriber::class)->shouldIgnoreMissing());

    $formulas = Mockery::mock(ComputedFieldWriter::class);
    $formulas->shouldReceive('materialize')->andReturn([]);
    app()->instance(ComputedFieldWriter::class, $formulas);

    $extensions = Mockery::mock(RecordExtensions::class)->shouldIgnoreMissing();
    $extensions->shouldReceive('attributes')->andReturn([]);
    $extensions->shouldReceive('auditAttributes')->andReturn([]);
    app()->instance(RecordExtensions::class, $extensions);

    app()->instance(ObjectTypeRegistry::class, new StaticObjectTypeRegistry([], [$this->objectTypeId => new Collection([
        'titel' => ModelStub::make(FieldDefinition::class, [
            'id' => ModelStub::ulid('write-path-titel'),
            'tenant_id' => $this->tenantId,
            'object_type_id' => $this->objectTypeId,
            'key' => 'titel',
            'field_type' => FieldType::TextShort,
            'is_searchable' => true,
            'is_translatable' => false,
            'is_encrypted' => false,
        ]),
    ])]));
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    AccessContext::forgetTenant();

    foreach ([
        FieldVisibilityResolver::class, RecordValidator::class, AuditRecorder::class, WatcherAutoSubscriber::class,
        ComputedFieldWriter::class, RecordExtensions::class, ObjectTypeRegistry::class, RecordNumberFormatter::class,
        RollupRecomputer::class, MergeTransferCounter::class, TimelineRecorder::class, RollupOwnerStarter::class,
    ] as $abstract) {
        app()->forgetInstance($abstract);
    }

    Mockery::close();
});

it('writes the search text of a new record right after the record itself', function (): void {
    FakePresenceVerifier::holding(['object_types' => [$this->objectTypeId]]);

    $numbers = Mockery::mock(RecordNumberFormatter::class);
    $numbers->shouldReceive('next')->andReturn('DEAL-0000000002');
    app()->instance(RecordNumberFormatter::class, $numbers);

    $rollups = Mockery::mock(RollupRecomputer::class);
    $rollups->shouldReceive('materializeOwn')->andReturn([]);
    app()->instance(RollupRecomputer::class, $rollups);

    $record = app(CreateRecordAction::class)->execute([
        'object_type_id' => $this->objectTypeId,
        'external_reference_id' => 'EXT-7',
        'data' => ['titel' => 'Aurora'],
    ]);

    $statements = array_column($this->connection->writtenStatements, 'sql');

    expect(($this->searchTextWrites)())->toBe([[
        'sql' => $this->searchTextSql,
        'bindings' => [$this->tenantId, $this->objectTypeId, (string) $record->getKey()],
    ]])
        ->and($statements[0])->toStartWith('insert into "custom_records"')
        ->and($statements[1])->toBe($this->searchTextSql)
        ->and($this->connection->sqlOf('field_definitions'))->toHaveCount(1);
});

it('rewrites the search text once a data change took effect', function (): void {
    app(UpdateRecordAction::class)->execute($this->record, ['version' => 3, 'data' => ['titel' => 'Berlin']]);

    $statements = array_column($this->connection->writtenStatements, 'sql');

    expect(($this->searchTextWrites)())->toBe([[
        'sql' => $this->searchTextSql,
        'bindings' => [$this->tenantId, $this->objectTypeId, $this->recordId],
    ]])
        ->and($statements[0])->toContain('"version" = ?')
        ->and($statements[1])->toBe($this->searchTextSql)
        ->and($this->connection->sqlOf('field_definitions'))->toBe([]);
});

it('rewrites the search text when only the external reference changes', function (): void {
    app(UpdateRecordAction::class)->execute($this->record, ['version' => 3, 'external_reference_id' => 'EXT-9']);

    expect(($this->searchTextWrites)())->toHaveCount(1);
});

it('leaves the search text alone for a write that touches neither the data nor a business key', function (): void {
    app(UpdateRecordAction::class)->execute($this->record, ['version' => 3]);

    expect(($this->searchTextWrites)())->toBe([]);
});

it('rewrites the search text of the target when an undone merge brings fields back', function (): void {
    config()->set('engine.merge.undo_window_days', 0);

    app()->instance(MergeTransferCounter::class, Mockery::mock(MergeTransferCounter::class));
    app()->instance(TimelineRecorder::class, Mockery::mock(TimelineRecorder::class)->shouldIgnoreMissing());
    app()->instance(RollupOwnerStarter::class, Mockery::mock(RollupOwnerStarter::class)->shouldIgnoreMissing());

    $merge = ModelStub::make(RecordMerge::class, [
        'id' => ModelStub::ulid('write-path-merge'),
        'tenant_id' => $this->tenantId,
        'object_type_id' => $this->objectTypeId,
        'target_record_id' => $this->recordId,
        'source_record_id' => ModelStub::ulid('write-path-source'),
        'resolution' => ['fields' => ['titel' => ['before' => 'Karlsruhe', 'after' => 'Berlin']]],
        'transfers' => [],
        'created_at' => now(),
        'undone_at' => null,
    ]);

    $report = app(UndoRecordMergeAction::class)->execute($merge);

    expect($report->restoredFields)->toBe(['titel'])
        ->and(($this->searchTextWrites)())->toBe([[
            'sql' => $this->searchTextSql,
            'bindings' => [$this->tenantId, $this->objectTypeId, $this->recordId],
        ]]);
});
