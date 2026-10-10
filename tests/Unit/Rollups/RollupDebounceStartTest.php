<?php

declare(strict_types=1);

use App\Activities\Engine\RecomputeRollupsActivity;
use App\Contracts\Engine\RollupDebounceWorkflowInterface;
use App\DTOs\Engine\RecordChangeBatch;
use App\Support\Engine\RollupDebounceStarter;
use Temporal\Client\WorkflowClientInterface;
use Temporal\Client\WorkflowOptions;
use Temporal\Common\IdReusePolicy;
use Temporal\Workflow\WorkflowRunInterface;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenantId = ModelStub::ulid('tenant');
    $this->objectTypeId = ModelStub::ulid('object-type');
    $this->recordId = ModelStub::ulid('record');

    $this->started = new stdClass;
    $this->started->stubs = [];
    $this->started->signals = [];

    $client = Mockery::mock(WorkflowClientInterface::class);

    $client->shouldReceive('newWorkflowStub')
        ->andReturnUsing(function (string $class, WorkflowOptions $options): object {
            $this->started->stubs[] = [
                'class' => $class,
                'workflow_id' => $options->workflowId,
                'reuse_policy' => $options->workflowIdReusePolicy,
                'task_queue' => $options->taskQueue,
            ];

            return new stdClass;
        });

    $client->shouldReceive('startWithSignal')
        ->andReturnUsing(function (object $stub, string $signal, array $signalArgs = [], array $startArgs = []): WorkflowRunInterface {
            $this->started->signals[] = [
                'signal' => $signal,
                'signal_args' => $signalArgs,
                'start_args' => $startArgs,
            ];

            return Mockery::mock(WorkflowRunInterface::class);
        });

    app()->instance(WorkflowClientInterface::class, $client);

    $this->starter = app(RollupDebounceStarter::class);
});

it('debounces every change to one record under a workflow id of tenant and record', function (): void {
    $this->starter->start($this->tenantId, $this->objectTypeId, $this->recordId, ['amount']);

    expect($this->started->stubs[0]['class'])->toBe(RollupDebounceWorkflowInterface::class)
        ->and($this->started->stubs[0]['workflow_id'])->toBe('rollup:'.$this->tenantId.':'.$this->recordId)
        ->and($this->started->stubs[0]['task_queue'])->toBe((string) config('temporal.queue'));
});

it('lets a finished debounce run again under the very same workflow id', function (): void {
    $this->starter->start($this->tenantId, $this->objectTypeId, $this->recordId, ['amount']);
    $this->starter->startOwn($this->tenantId, $this->objectTypeId, $this->recordId);

    $policies = array_column($this->started->stubs, 'reuse_policy');

    expect($policies)->toBe([IdReusePolicy::POLICY_ALLOW_DUPLICATE, IdReusePolicy::POLICY_ALLOW_DUPLICATE])
        ->and($policies)->not->toContain(IdReusePolicy::POLICY_ALLOW_DUPLICATE_FAILED_ONLY);
});

it('carries the changed field keys into the cascade signal', function (): void {
    $this->starter->start($this->tenantId, $this->objectTypeId, $this->recordId, ['amount', 'status']);

    expect($this->started->signals)->toBe([[
        'signal' => 'enqueue',
        'signal_args' => [['amount', 'status']],
        'start_args' => [$this->tenantId, $this->objectTypeId, $this->recordId],
    ]]);
});

it('asks for the own roll-ups of a record without naming any changed field', function (): void {
    $this->starter->startOwn($this->tenantId, $this->objectTypeId, $this->recordId);

    expect($this->started->signals)->toBe([[
        'signal' => 'enqueueOwn',
        'signal_args' => [],
        'start_args' => [$this->tenantId, $this->objectTypeId, $this->recordId],
    ]]);
});

it('debounces one workflow per changed record of a relayed batch', function (): void {
    $secondRecordId = ModelStub::ulid('second-record');

    $handled = app(RecomputeRollupsActivity::class)->recomputeRollups(RecordChangeBatch::fromArray([
        [
            'tenant_id' => $this->tenantId,
            'object_type_id' => $this->objectTypeId,
            'record_id' => $this->recordId,
            'version' => 2,
            'sequence' => 1,
            'changed_field_keys' => ['amount'],
        ],
        [
            'tenant_id' => $this->tenantId,
            'object_type_id' => $this->objectTypeId,
            'record_id' => $secondRecordId,
            'version' => 3,
            'sequence' => 2,
            'changed_field_keys' => ['status'],
        ],
    ]));

    expect($handled)->toBe(2)
        ->and(array_column($this->started->stubs, 'workflow_id'))->toBe([
            'rollup:'.$this->tenantId.':'.$this->recordId,
            'rollup:'.$this->tenantId.':'.$secondRecordId,
        ])
        ->and(array_column($this->started->signals, 'signal_args'))->toBe([[['amount']], [['status']]]);
});

it('starts nothing for an empty relay batch', function (): void {
    $handled = app(RecomputeRollupsActivity::class)->recomputeRollups(RecordChangeBatch::fromArray([]));

    expect($handled)->toBe(0)
        ->and($this->started->stubs)->toBe([]);
});
