<?php

declare(strict_types=1);

use App\Support\Engine\RollupDebounceStarter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Temporal\Client\WorkflowClientInterface;
use Temporal\Client\WorkflowOptions;
use Temporal\Workflow\WorkflowRunInterface;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenantId = ModelStub::ulid('buffer-tenant');
    $this->objectTypeId = ModelStub::ulid('buffer-object-type');
    $this->firstRecordId = ModelStub::ulid('buffer-first-record');
    $this->secondRecordId = ModelStub::ulid('buffer-second-record');

    $this->sent = new stdClass;
    $this->sent->signals = [];
    $this->sent->failingWorkflowIds = [];

    $client = Mockery::mock(WorkflowClientInterface::class);

    $client->shouldReceive('newWorkflowStub')
        ->andReturnUsing(function (string $class, WorkflowOptions $options): object {
            $stub = new stdClass;
            $stub->workflowId = $options->workflowId;

            return $stub;
        });

    $client->shouldReceive('startWithSignal')
        ->andReturnUsing(function (object $stub, string $signal, array $signalArgs = [], array $startArgs = []): WorkflowRunInterface {
            if (in_array($stub->workflowId, $this->sent->failingWorkflowIds, true)) {
                throw new RuntimeException('temporal is unreachable');
            }

            $this->sent->signals[] = [
                'workflow_id' => $stub->workflowId,
                'signal' => $signal,
                'signal_args' => $signalArgs,
            ];

            return Mockery::mock(WorkflowRunInterface::class);
        });

    app()->instance(WorkflowClientInterface::class, $client);
    app()->forgetInstance(RollupDebounceStarter::class);

    $pdo = Mockery::mock(PDO::class);
    $pdo->shouldReceive('beginTransaction', 'commit', 'rollBack', 'inTransaction')->andReturn(true);
    $pdo->shouldReceive('exec')->andReturn(0);
    DB::connection()->setPdo($pdo);

    $this->workflowIdOf = fn (string $recordId): string => "rollup:{$this->tenantId}:{$recordId}";
    $this->starter = app(RollupDebounceStarter::class);
});

afterEach(function (): void {
    Mockery::close();
});

it('shares one buffer between every class that starts a roll-up recompute', function (): void {
    expect(app(RollupDebounceStarter::class))->toBe($this->starter);
});

it('starts at once when no transaction is open', function (): void {
    $this->starter->startOwn($this->tenantId, $this->objectTypeId, $this->firstRecordId);

    expect($this->sent->signals)->toBe([[
        'workflow_id' => ($this->workflowIdOf)($this->firstRecordId),
        'signal' => 'enqueueOwn',
        'signal_args' => [],
    ]]);
});

it('sends nothing before the outermost transaction commits and one start per record after it', function (): void {
    $signalsBeforeCommit = DB::transaction(function (): array {
        $this->starter->startOwn($this->tenantId, $this->objectTypeId, $this->firstRecordId);
        $this->starter->startOwn($this->tenantId, $this->objectTypeId, $this->firstRecordId);

        DB::transaction(function (): void {
            $this->starter->startOwn($this->tenantId, $this->objectTypeId, $this->firstRecordId);
            $this->starter->startOwn($this->tenantId, $this->objectTypeId, $this->secondRecordId);
        });

        return $this->sent->signals;
    });

    expect($signalsBeforeCommit)->toBe([])
        ->and($this->sent->signals)->toBe([
            ['workflow_id' => ($this->workflowIdOf)($this->firstRecordId), 'signal' => 'enqueueOwn', 'signal_args' => []],
            ['workflow_id' => ($this->workflowIdOf)($this->secondRecordId), 'signal' => 'enqueueOwn', 'signal_args' => []],
        ]);
});

it('unites the changed field keys of one record into a single cascade signal', function (): void {
    DB::transaction(function (): void {
        $this->starter->start($this->tenantId, $this->objectTypeId, $this->firstRecordId, ['amount']);
        $this->starter->start($this->tenantId, $this->objectTypeId, $this->firstRecordId, ['status', 'amount']);
    });

    expect($this->sent->signals)->toBe([[
        'workflow_id' => ($this->workflowIdOf)($this->firstRecordId),
        'signal' => 'enqueue',
        'signal_args' => [['amount', 'status']],
    ]]);
});

it('keeps an empty key list meaning every field when it meets named keys', function (): void {
    DB::transaction(function (): void {
        $this->starter->start($this->tenantId, $this->objectTypeId, $this->firstRecordId, ['amount']);
        $this->starter->start($this->tenantId, $this->objectTypeId, $this->firstRecordId, []);
        $this->starter->start($this->tenantId, $this->objectTypeId, $this->firstRecordId, ['status']);
    });

    expect($this->sent->signals)->toBe([[
        'workflow_id' => ($this->workflowIdOf)($this->firstRecordId),
        'signal' => 'enqueue',
        'signal_args' => [[]],
    ]]);
});

it('delivers an own and a cascade request for the same record as both signals of one workflow', function (): void {
    DB::transaction(function (): void {
        $this->starter->startOwn($this->tenantId, $this->objectTypeId, $this->firstRecordId);
        $this->starter->start($this->tenantId, $this->objectTypeId, $this->firstRecordId, ['amount']);
        $this->starter->startOwn($this->tenantId, $this->objectTypeId, $this->firstRecordId);
    });

    expect($this->sent->signals)->toBe([
        ['workflow_id' => ($this->workflowIdOf)($this->firstRecordId), 'signal' => 'enqueue', 'signal_args' => [['amount']]],
        ['workflow_id' => ($this->workflowIdOf)($this->firstRecordId), 'signal' => 'enqueueOwn', 'signal_args' => []],
    ]);
});

it('drops the buffered starts of a transaction that rolls back', function (): void {
    expect(fn () => DB::transaction(function (): void {
        $this->starter->startOwn($this->tenantId, $this->objectTypeId, $this->firstRecordId);

        throw new RuntimeException('write failed');
    }))->toThrow(RuntimeException::class, 'write failed');

    $this->starter->startOwn($this->tenantId, $this->objectTypeId, $this->secondRecordId);

    expect(array_column($this->sent->signals, 'workflow_id'))->toBe([($this->workflowIdOf)($this->secondRecordId)]);
});

it('keeps the starts of the outer transaction when a nested savepoint rolls back', function (): void {
    DB::transaction(function (): void {
        $this->starter->startOwn($this->tenantId, $this->objectTypeId, $this->firstRecordId);

        rescue(fn () => DB::transaction(function (): void {
            $this->starter->startOwn($this->tenantId, $this->objectTypeId, $this->secondRecordId);

            throw new RuntimeException('nested write failed');
        }), report: false);
    });

    expect(array_column($this->sent->signals, 'workflow_id'))->toContain(($this->workflowIdOf)($this->firstRecordId));
});

it('collects the starts of a batch outside a transaction and sends them once the batch ends', function (): void {
    $signalsInsideBatch = $this->starter->collect(function (): array {
        $this->starter->start($this->tenantId, $this->objectTypeId, $this->firstRecordId, ['amount']);
        $this->starter->start($this->tenantId, $this->objectTypeId, $this->firstRecordId, ['status']);
        $this->starter->startOwn($this->tenantId, $this->objectTypeId, $this->secondRecordId);

        return $this->sent->signals;
    });

    expect($signalsInsideBatch)->toBe([])
        ->and($this->sent->signals)->toBe([
            ['workflow_id' => ($this->workflowIdOf)($this->firstRecordId), 'signal' => 'enqueue', 'signal_args' => [['amount', 'status']]],
            ['workflow_id' => ($this->workflowIdOf)($this->secondRecordId), 'signal' => 'enqueueOwn', 'signal_args' => []],
        ]);
});

it('waits for the batch to end when a transaction inside the batch commits', function (): void {
    $signalsAfterCommit = $this->starter->collect(function (): array {
        DB::transaction(function (): void {
            $this->starter->startOwn($this->tenantId, $this->objectTypeId, $this->firstRecordId);
        });

        $this->starter->startOwn($this->tenantId, $this->objectTypeId, $this->firstRecordId);

        return $this->sent->signals;
    });

    expect($signalsAfterCommit)->toBe([])
        ->and(array_column($this->sent->signals, 'workflow_id'))->toBe([($this->workflowIdOf)($this->firstRecordId)]);
});

it('still starts every other record, logs the failed ones with tenant and record, and rethrows', function (): void {
    Log::spy();

    $thirdRecordId = ModelStub::ulid('buffer-third-record');
    $this->sent->failingWorkflowIds = [($this->workflowIdOf)($this->secondRecordId)];

    expect(fn () => DB::transaction(function () use ($thirdRecordId): void {
        $this->starter->startOwn($this->tenantId, $this->objectTypeId, $this->firstRecordId);
        $this->starter->startOwn($this->tenantId, $this->objectTypeId, $this->secondRecordId);
        $this->starter->startOwn($this->tenantId, $this->objectTypeId, $thirdRecordId);
    }))->toThrow(RuntimeException::class, 'temporal is unreachable');

    expect(array_column($this->sent->signals, 'workflow_id'))->toBe([
        ($this->workflowIdOf)($this->firstRecordId),
        ($this->workflowIdOf)($thirdRecordId),
    ]);

    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $message, array $context): bool => $context['failed'] === [['tenant_id' => $this->tenantId, 'record_id' => $this->secondRecordId]]
            && $context['failed_count'] === 1
            && $context['started_count'] === 2
            && $context['pending_count'] === 3);
});
