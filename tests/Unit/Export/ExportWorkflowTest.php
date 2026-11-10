<?php

declare(strict_types=1);

use App\DTOs\Export\ExportData;
use App\Workflows\Export\ExportWorkflow;
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
    config()->set('temporal.activity_limits.maximum_attempts', 3);
    config()->set('temporal.activity_limits.schedule_to_close', 7200);

    $this->input = new ExportData(
        tenantId: ModelStub::ulid('export-tenant'),
        actingUserId: ModelStub::ulid('export-user'),
        exportJobId: ModelStub::ulid('export-job'),
    );
});

afterEach(function (): void {
    WorkflowRun::end();
});

it('bounds the export and its finalization by a maximum number of attempts and a schedule to close timeout', function (string $activity): void {
    $run = WorkflowRun::start(fn (): Generator => (new ExportWorkflow)->run($this->input))->resume();

    $options = $run->optionsOf($activity);

    expect($options?->retryOptions->maximumAttempts)->toBe(3)
        ->and(CarbonInterval::instance($options->scheduleToCloseTimeout)->totalSeconds)->toEqual(7200)
        ->and($options->retryOptions->nonRetryableExceptions)->toContain(AuthorizationException::class);
})->with([
    'export' => ['Export.exportRecords'],
    'finalize' => ['Export.finalizeExport'],
]);

it('finalizes the export job, logs the terminal failure with the workflow identifiers and fails the workflow when the export finally fails', function (): void {
    $failure = new ActivityFailure(5, 6, 'Export.exportRecords', 'activity-1', 0, 'worker', new ApplicationFailure('acting user gone', AuthorizationException::class, true));

    $run = WorkflowRun::start(fn (): Generator => (new ExportWorkflow)->run($this->input))->fail($failure);

    expect($run->lastStep())->toBe('activity')
        ->and(collect($run->activities())->pluck('activity')->all())->toBe(['Export.exportRecords', 'Export.finalizeExport'])
        ->and(fn (): WorkflowRun => $run->resume())->toThrow(ActivityFailure::class);

    $error = collect($run->logs)->firstWhere('level', 'error');

    expect($error['context'])->toMatchArray([
        'workflow_id' => $run->workflowId,
        'run_id' => $run->runId,
        'tenant_id' => ModelStub::ulid('export-tenant'),
        'export_job_id' => ModelStub::ulid('export-job'),
    ]);
});

it('logs the finished export with the workflow identifiers', function (): void {
    $run = WorkflowRun::start(fn (): Generator => (new ExportWorkflow)->run($this->input))->resume()->resume();

    expect($run->isFinished())->toBeTrue()
        ->and(collect($run->logs)->firstWhere('level', 'info')['context'] ?? null)->toMatchArray([
            'workflow_id' => $run->workflowId,
            'run_id' => $run->runId,
            'export_job_id' => ModelStub::ulid('export-job'),
        ]);
});

it('logs the terminal export failure even when finalizing the failed job fails as well', function (): void {
    $exportFailure = new ActivityFailure(5, 6, 'Export.exportRecords', 'activity-1', 0, 'worker', new ApplicationFailure('export exploded', RuntimeException::class, true));
    $finalizeFailure = new ActivityFailure(7, 8, 'Export.finalizeExport', 'activity-2', 0, 'worker', new ApplicationFailure('database gone', RuntimeException::class, true));

    $run = WorkflowRun::start(fn (): Generator => (new ExportWorkflow)->run($this->input))->fail($exportFailure);

    expect(fn (): WorkflowRun => $run->fail($finalizeFailure))->toThrow(ActivityFailure::class);

    $context = collect($run->logs)->firstWhere('level', 'error')['context'] ?? [];

    expect($context)->toMatchArray([
        'workflow_id' => $run->workflowId,
        'run_id' => $run->runId,
        'export_job_id' => ModelStub::ulid('export-job'),
    ])
        ->and($context['error'] ?? '')->toContain('export exploded');
});
