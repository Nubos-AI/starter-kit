<?php

declare(strict_types=1);

use App\Actions\Engine\MergeRecordsAction;
use App\Actions\Engine\UndoRecordMergeAction;
use App\Actions\Engine\UpdateRecordAction;
use App\DTOs\Engine\MergeBlocker;
use App\DTOs\Engine\MergePlanData;
use App\DTOs\Engine\MergeRequestData;
use App\Enums\Engine\MergeBlockReason;
use App\Enums\Engine\MergeRuleMode;
use App\Enums\Engine\MergeUndoRefusalReason;
use App\Exceptions\Engine\MergeRefusedException;
use App\Exceptions\Engine\MergeUndoRefusedException;
use App\Models\CustomRecord;
use App\Models\RecordMerge;
use App\Support\Engine\AncestorChainNotifier;
use App\Support\Engine\MergePlanner;
use App\Support\Engine\MergeRuleResolver;
use App\Support\Engine\MergeTransferCounter;
use App\Support\Engine\MergeTransferExecutor;
use App\Support\Engine\RecordSearchText;
use App\Support\Engine\RecordTreeQuery;
use App\Support\Engine\RollupOwnerStarter;
use App\Support\Timeline\TimelineRecorder;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->tenantId = (string) $this->tenant->getKey();
    $this->lowerId = ModelStub::ulid('a-record');
    $this->higherId = ModelStub::ulid('b-record');

    [$this->lowerId, $this->higherId] = $this->lowerId < $this->higherId
        ? [$this->lowerId, $this->higherId]
        : [$this->higherId, $this->lowerId];

    $this->recordRow = fn (string $id, array $overrides = []): array => [
        'id' => $id,
        'tenant_id' => $this->tenantId,
        'object_type_id' => ModelStub::ulid('companies'),
        'version' => 3,
        'data' => '{}',
        ...$overrides,
    ];

    /** @var callable(array<string, array<string, mixed>>, list<array<string, mixed>>): StaticQueryConnection */
    $this->install = fn (array $recordOverrides = [], array $mergeRows = []): StaticQueryConnection => StaticQueryConnection::install(
        function (string $sql) use ($recordOverrides, $mergeRows): array {
            if (str_contains($sql, 'from "record_merges"')) {
                return $mergeRows;
            }

            if (str_contains($sql, 'from "custom_records"')) {
                return [
                    ($this->recordRow)($this->lowerId, $recordOverrides[$this->lowerId] ?? []),
                    ($this->recordRow)($this->higherId, $recordOverrides[$this->higherId] ?? []),
                ];
            }

            return [];
        },
        static fn (): int => 1,
    );

    $this->blockedPlan = fn (string $targetId, string $sourceId): MergePlanData => new MergePlanData(
        $targetId,
        $sourceId,
        3,
        3,
        MergeRuleMode::Allow,
        null,
        null,
        null,
        false,
        [],
        [],
        [new MergeBlocker(MergeBlockReason::AlreadyMerged)],
    );

    $this->lockQueryOf = static fn (StaticQueryConnection $connection, string $table): ?array => collect($connection->queries)
        ->first(static fn (array $query): bool => str_contains($query['sql'], "from \"{$table}\"") && str_contains($query['sql'], 'for update'));
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    AccessContext::forgetTenant();
    Mockery::close();
});

it('locks both records in key order inside the transaction and plans the merge from the locked rows', function (): void {
    $connection = ($this->install)([$this->lowerId => ['merged_into_record_id' => ModelStub::ulid('elsewhere')]]);

    $seen = new stdClass;

    $planner = Mockery::mock(MergePlanner::class);
    $planner->shouldReceive('plan')
        ->once()
        ->andReturnUsing(function (CustomRecord $target, CustomRecord $source) use ($connection, $seen): MergePlanData {
            $seen->transactionLevel = $connection->transactionLevel();
            $seen->sourceMergedInto = $source->merged_into_record_id;

            return ($this->blockedPlan)((string) $target->getKey(), (string) $source->getKey());
        });

    $action = new MergeRecordsAction(
        $planner,
        Mockery::mock(MergeRuleResolver::class),
        Mockery::mock(MergeTransferExecutor::class),
        Mockery::mock(UpdateRecordAction::class),
        Mockery::mock(TimelineRecorder::class),
        Mockery::mock(RecordTreeQuery::class),
        Mockery::mock(AncestorChainNotifier::class),
    );

    expect(fn () => $action->execute(new MergeRequestData($this->higherId, $this->lowerId)))
        ->toThrow(MergeRefusedException::class);

    $lock = ($this->lockQueryOf)($connection, 'custom_records');

    expect($seen->transactionLevel)->toBe(1)
        ->and($seen->sourceMergedInto)->toBe(ModelStub::ulid('elsewhere'))
        ->and($lock)->not->toBeNull()
        ->and($lock['sql'])->toContain('order by "id" asc')
        ->and($connection->writtenStatements)->toBe([]);
});

it('re-reads the merge under a lock and refuses an undo another request finished meanwhile', function (): void {
    config()->set('engine.merge.undo_window_days', 0);

    $merge = ModelStub::make(RecordMerge::class, [
        'id' => ModelStub::ulid('merge'),
        'tenant_id' => $this->tenantId,
        'object_type_id' => ModelStub::ulid('companies'),
        'target_record_id' => $this->higherId,
        'source_record_id' => $this->lowerId,
        'created_at' => now(),
        'undone_at' => null,
    ]);

    $connection = ($this->install)([], [[
        'id' => ModelStub::ulid('merge'),
        'tenant_id' => $this->tenantId,
        'object_type_id' => ModelStub::ulid('companies'),
        'target_record_id' => $this->higherId,
        'source_record_id' => $this->lowerId,
        'created_at' => now()->toDateTimeString(),
        'undone_at' => now()->toDateTimeString(),
    ]]);

    $action = new UndoRecordMergeAction(
        Mockery::mock(MergeTransferCounter::class),
        Mockery::mock(TimelineRecorder::class),
        Mockery::mock(RollupOwnerStarter::class),
        app(RecordSearchText::class),
    );

    $refusal = null;

    try {
        $action->execute($merge);
    } catch (MergeUndoRefusedException $exception) {
        $refusal = $exception->reason;
    }

    expect($refusal)->toBe(MergeUndoRefusalReason::AlreadyUndone)
        ->and(($this->lockQueryOf)($connection, 'record_merges'))->not->toBeNull()
        ->and($connection->writtenStatements)->toBe([]);
});

it('locks target and source of an undo in key order before it restores anything', function (): void {
    config()->set('engine.merge.undo_window_days', 0);

    $mergeRow = [
        'id' => ModelStub::ulid('merge'),
        'tenant_id' => $this->tenantId,
        'object_type_id' => ModelStub::ulid('companies'),
        'target_record_id' => $this->higherId,
        'source_record_id' => $this->lowerId,
        'created_at' => now()->toDateTimeString(),
        'undone_at' => null,
        'resolution' => '{"fields":[]}',
        'transfers' => '[]',
    ];

    $connection = ($this->install)([$this->lowerId => ['deleted_at' => now()->toDateTimeString()]], [$mergeRow]);

    $timeline = Mockery::mock(TimelineRecorder::class);
    $timeline->shouldReceive('record')->twice();

    (new UndoRecordMergeAction(
        Mockery::mock(MergeTransferCounter::class),
        $timeline,
        Mockery::mock(RollupOwnerStarter::class),
        app(RecordSearchText::class),
    ))->execute(ModelStub::make(RecordMerge::class, [...$mergeRow, 'created_at' => now(), 'undone_at' => null]));

    $recordLock = ($this->lockQueryOf)($connection, 'custom_records');
    $mergeLockIndex = collect($connection->queries)->search(static fn (array $query): bool => str_contains($query['sql'], 'from "record_merges"'));
    $recordLockIndex = collect($connection->queries)->search(static fn (array $query): bool => $query === $recordLock);

    expect($recordLock)->not->toBeNull()
        ->and($recordLock['sql'])->toContain('order by "id" asc')
        ->and($mergeLockIndex)->toBeLessThan($recordLockIndex);
});
