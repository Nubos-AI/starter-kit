<?php

declare(strict_types=1);

use App\Workflows\Engine\RollupDebounceWorkflow;
use Tests\Support\WithoutDatabase;
use Tests\Support\WorkflowRun;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

afterEach(function (): void {
    WorkflowRun::end();
});

it('recomputes every rollup change that arrives while a recompute runs instead of finishing with stale totals', function (): void {
    $workflow = new RollupDebounceWorkflow;

    $run = WorkflowRun::start(fn (): Generator => $workflow->run('tenant-1', 'object-type-1', 'record-1'))
        ->signal(fn () => $workflow->enqueueOwn())
        ->signal(fn () => $workflow->enqueue(['amount']))
        ->fireTimer();

    expect($run->waitsFor())->toBe('Rollups.recomputeOwnRollups');

    $run->signal(fn () => $workflow->enqueue(['quantity']))
        ->resume();

    expect($run->waitsFor())->toBe('Rollups.recomputeRollup');

    $run->signal(fn () => $workflow->enqueueOwn())
        ->resume()
        ->fireTimer()
        ->resume()
        ->resume();

    expect($run->activities())->toBe([
        ['activity' => 'Rollups.recomputeOwnRollups', 'arguments' => ['tenant-1', 'object-type-1', 'record-1']],
        ['activity' => 'Rollups.recomputeRollup', 'arguments' => ['tenant-1', 'object-type-1', 'record-1', ['amount']]],
        ['activity' => 'Rollups.recomputeOwnRollups', 'arguments' => ['tenant-1', 'object-type-1', 'record-1']],
        ['activity' => 'Rollups.recomputeRollup', 'arguments' => ['tenant-1', 'object-type-1', 'record-1', ['quantity']]],
    ])
        ->and($run->isFinished())->toBeTrue();
});
