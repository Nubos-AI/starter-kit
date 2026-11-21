<?php

declare(strict_types=1);

use App\DTOs\Engine\BulkActionData;
use App\Exceptions\Maintenance\TenantUnderMaintenanceException;
use App\Workflows\Engine\BulkActionWorkflow;
use Carbon\CarbonInterval;
use Illuminate\Auth\Access\AuthorizationException;
use Temporal\Exception\Failure\ActivityFailure;
use Temporal\Exception\Failure\ApplicationFailure;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\Support\WorkflowRun;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    config()->set('temporal.activity_limits.maximum_attempts', 4);
    config()->set('temporal.activity_limits.schedule_to_close', 5400);

    $this->firstChunk = [ModelStub::ulid('bulk-record-1'), ModelStub::ulid('bulk-record-2')];
    $this->failingChunk = [ModelStub::ulid('bulk-record-3'), ModelStub::ulid('bulk-record-4')];

    $this->input = new BulkActionData(
        bulkRunId: ModelStub::ulid('bulk-run'),
        tenantId: ModelStub::ulid('bulk-tenant'),
        actingUserId: ModelStub::ulid('bulk-user'),
        objectTypeId: ModelStub::ulid('bulk-object-type'),
        action: 'update-field',
        chunks: [$this->firstChunk, $this->failingChunk],
        payload: [],
        threshold: 10,
        objectTypeSlug: 'companies',
        notify: true,
    );

    $this->chunkFailure = new ActivityFailure(5, 6, 'Bulk.processBulkChunk', 'activity-2', 0, 'worker', new ApplicationFailure('chunk exploded', RuntimeException::class, true));
});

afterEach(function (): void {
    WorkflowRun::end();
});

it('bounds every bulk activity by a maximum number of attempts and a schedule to close timeout and never retries an expected refusal', function (string $activity): void {
    $run = WorkflowRun::start(fn (): Generator => (new BulkActionWorkflow)->run($this->input))
        ->resume()
        ->resume();

    $options = $run->optionsOf($activity);

    expect($options?->retryOptions->maximumAttempts)->toBe(4)
        ->and(CarbonInterval::instance($options->scheduleToCloseTimeout)->totalSeconds)->toEqual(5400)
        ->and($options->retryOptions->nonRetryableExceptions)->toContain(AuthorizationException::class)
        ->and($options->retryOptions->nonRetryableExceptions)->toContain(TenantUnderMaintenanceException::class);
})->with([
    'chunk' => ['Bulk.processBulkChunk'],
    'finalize' => ['Bulk.finalizeBulk'],
]);

it('reports a failed chunk instead of swallowing it and leaves its records out of the affected records', function (): void {
    $run = WorkflowRun::start(fn (): Generator => (new BulkActionWorkflow)->run($this->input))
        ->resume()
        ->fail($this->chunkFailure);

    $finalize = collect($run->activities())->firstWhere('activity', 'Bulk.finalizeBulk');

    expect($finalize['arguments'][3])->toBe($this->firstChunk)
        ->and($finalize['arguments'][7])->toBe([$this->failingChunk]);

    $error = collect($run->logs)->firstWhere('level', 'error');

    expect($error['context'])->toMatchArray([
        'workflow_id' => $run->workflowId,
        'run_id' => $run->runId,
        'bulk_run_id' => ModelStub::ulid('bulk-run'),
        'tenant_id' => ModelStub::ulid('bulk-tenant'),
        'action' => 'update-field',
        'record_count' => 2,
    ]);
});

it('hands the finalizer no failed records when every chunk succeeded', function (): void {
    $run = WorkflowRun::start(fn (): Generator => (new BulkActionWorkflow)->run($this->input))
        ->resume()
        ->resume();

    $finalize = collect($run->activities())->firstWhere('activity', 'Bulk.finalizeBulk');

    expect($finalize['arguments'][3])->toBe([...$this->firstChunk, ...$this->failingChunk])
        ->and($finalize['arguments'][7])->toBe([])
        ->and(collect($run->logs)->where('level', 'error'))->toBeEmpty();
});

it('lets a failure that is not an activity failure end the workflow', function (): void {
    $run = WorkflowRun::start(fn (): Generator => (new BulkActionWorkflow)->run($this->input));

    expect(fn (): WorkflowRun => $run->fail(new LogicException('workflow bug')))->toThrow(LogicException::class);
});

it('logs the terminal outcome of the bulk run with its workflow identifiers once the finalizer ran', function (bool $isChunkFailing, int $failedChunkCount): void {
    $run = WorkflowRun::start(fn (): Generator => (new BulkActionWorkflow)->run($this->input))->resume();
    $run = $isChunkFailing ? $run->fail($this->chunkFailure) : $run->resume();
    $run->resume();

    $finished = collect($run->logs)->firstWhere('message', 'Bulk action finished.');

    expect($finished)->not->toBeNull()
        ->and($finished['level'])->toBe('info')
        ->and($finished['context'])->toMatchArray([
            'workflow_id' => $run->workflowId,
            'run_id' => $run->runId,
            'bulk_run_id' => ModelStub::ulid('bulk-run'),
            'tenant_id' => ModelStub::ulid('bulk-tenant'),
            'action' => 'update-field',
            'failed_chunk_count' => $failedChunkCount,
        ]);
})->with([
    'every chunk succeeded' => [false, 0],
    'one chunk failed' => [true, 1],
]);
