<?php

declare(strict_types=1);

use App\Activities\Temporal\RunConsoleCommandActivity;
use App\Enums\Temporal\PeriodicScanCommand;
use App\Exceptions\Temporal\PeriodicScanFailedException;
use App\Workflows\Temporal\PeriodicScansWorkflow;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Temporal\Activity;
use Temporal\Activity\ActivityContextInterface;
use Temporal\Activity\ActivityInfo;
use Temporal\Exception\Failure\ActivityFailure;
use Temporal\Workflow\WorkflowExecution;
use Tests\Support\WithoutDatabase;
use Tests\Support\WorkflowRun;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $info = new ActivityInfo;
    $info->workflowExecution = new WorkflowExecution('periodic-scans', 'periodic-run');
    $activityContext = Mockery::mock(ActivityContextInterface::class);
    $activityContext->shouldReceive('getInfo')->andReturn($info);
    Activity::setCurrentContext($activityContext);

    config()->set('modules.periodic_commands', ['aging:scan-triggers']);

    $this->failure = static fn (string $command): ActivityFailure => new ActivityFailure(1, 2, 'PeriodicScans.runConsoleCommand', $command, 0, 'worker');
});

afterEach(function (): void {
    Activity::setCurrentContext(null);
    WorkflowRun::end();
});

it('lists the core scans followed by the scans the installed modules registered', function (): void {
    expect((new RunConsoleCommandActivity)->periodicCommands())->toBe([
        ...array_column(PeriodicScanCommand::cases(), 'value'),
        'aging:scan-triggers',
    ]);
});

it('asks an activity for the scans instead of reading the configuration inside the workflow, so a replay stays deterministic', function (): void {
    $run = WorkflowRun::start(fn (): Generator => (new PeriodicScansWorkflow)->scan());

    config()->set('modules.periodic_commands', ['changed:after-start']);
    $run->resume(['reminders:scan-due', 'aging:scan-triggers'])->resume()->resume();

    expect($run->activities())->toBe([
        ['activity' => 'PeriodicScans.periodicCommands', 'arguments' => []],
        ['activity' => 'PeriodicScans.runConsoleCommand', 'arguments' => ['reminders:scan-due']],
        ['activity' => 'PeriodicScans.runConsoleCommand', 'arguments' => ['aging:scan-triggers']],
    ])->and($run->isFinished())->toBeTrue()
        ->and(end($run->logs))->toMatchArray(['level' => 'info', 'context' => ['commands' => 2, 'workflow_id' => $run->workflowId, 'run_id' => $run->runId]]);
});

it('still runs every later scan when one scan fails and then fails the run with that scan', function (): void {
    $run = WorkflowRun::start(fn (): Generator => (new PeriodicScansWorkflow)->scan());
    $run->resume(['notifications:scan-date-triggers', 'notifications:send-digests', 'approvals:scan-deadlines']);

    $run->fail(($this->failure)('notifications:scan-date-triggers'))->resume();

    expect(fn (): WorkflowRun => $run->resume())->toThrow(ActivityFailure::class);

    expect(array_column($run->activities(), 'arguments'))->toBe([
        [],
        ['notifications:scan-date-triggers'],
        ['notifications:send-digests'],
        ['approvals:scan-deadlines'],
    ])->and(collect($run->logs)->where('level', 'error')->pluck('context.failed')->filter()->first())->toBe(['notifications:scan-date-triggers']);
});

it('reports a scan that exits with a failure as a failed activity and logs it with the workflow run', function (): void {
    Log::spy();
    Artisan::shouldReceive('call')->once()->with('notifications:scan-date-triggers')->andReturn(1);

    expect(fn () => (new RunConsoleCommandActivity)->runConsoleCommand('notifications:scan-date-triggers'))
        ->toThrow(PeriodicScanFailedException::class);

    Log::shouldHaveReceived('error')->once()->withArgs(static fn (string $message, array $context): bool => $context === [
        'command' => 'notifications:scan-date-triggers',
        'exit_code' => 1,
        'workflow_id' => 'periodic-scans',
        'run_id' => 'periodic-run',
    ]);
});

it('logs a scan that finished cleanly with the workflow run', function (): void {
    Log::spy();
    Artisan::shouldReceive('call')->once()->with('reminders:scan-due')->andReturn(0);

    (new RunConsoleCommandActivity)->runConsoleCommand('reminders:scan-due');

    Log::shouldHaveReceived('info')->once()->withArgs(static fn (string $message, array $context): bool => $context === [
        'command' => 'reminders:scan-due',
        'exit_code' => 0,
        'workflow_id' => 'periodic-scans',
        'run_id' => 'periodic-run',
    ]);
});

it('refuses a console command that is no registered periodic scan without running it', function (): void {
    Artisan::shouldReceive('call')->never();

    expect(fn () => (new RunConsoleCommandActivity)->runConsoleCommand('db:wipe'))->toThrow(InvalidArgumentException::class);
});

it('still logs the outcome of a scan run outside a workflow, only without a run to name', function (): void {
    Activity::setCurrentContext(null);
    Log::spy();
    Artisan::shouldReceive('call')->once()->with('reminders:scan-due')->andReturn(0);

    (new RunConsoleCommandActivity)->runConsoleCommand('reminders:scan-due');

    Log::shouldHaveReceived('info')->once()->withArgs(static fn (string $message, array $context): bool => $context === [
        'command' => 'reminders:scan-due',
        'exit_code' => 0,
        'workflow_id' => null,
        'run_id' => null,
    ]);
});
