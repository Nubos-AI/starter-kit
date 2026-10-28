<?php

declare(strict_types=1);

use App\Workflows\Engine\RollupDebounceWorkflow;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\Support\WorkflowRun;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->workflow = new RollupDebounceWorkflow;

    $this->run = WorkflowRun::start(fn (): Generator => $this->workflow->run(
        ModelStub::ulid('tenant'),
        ModelStub::ulid('object-type'),
        ModelStub::ulid('record'),
    ));
});

afterEach(function (): void {
    WorkflowRun::end();
});

it('recomputes once more with only the changes that arrived while the first recompute was running', function (): void {
    $this->workflow->enqueue(['amount']);
    $this->run->resume()->resume(false);

    expect($this->run->lastStep())->toBe('activity');

    $this->workflow->enqueue(['stage']);
    $this->run->resume();

    expect($this->run->lastStep())->toBe('awaitWithTimeout');

    $this->run->resume(false)->resume();

    expect($this->run->isFinished())->toBeTrue()
        ->and($this->run->activities())->toBe([
            [
                'activity' => 'Rollups.recomputeRollup',
                'arguments' => [ModelStub::ulid('tenant'), ModelStub::ulid('object-type'), ModelStub::ulid('record'), ['amount']],
            ],
            [
                'activity' => 'Rollups.recomputeRollup',
                'arguments' => [ModelStub::ulid('tenant'), ModelStub::ulid('object-type'), ModelStub::ulid('record'), ['stage']],
            ],
        ]);
});

it('runs both recomputations of one round and ends without a further signal', function (): void {
    $this->workflow->enqueueOwn();
    $this->workflow->enqueue(['amount']);
    $this->run->resume()->resume(false)->resume()->resume();

    expect($this->run->isFinished())->toBeTrue()
        ->and(collect($this->run->activities())->pluck('activity')->all())->toBe(['Rollups.recomputeOwnRollups', 'Rollups.recomputeRollup']);
});

it('keeps recomputing every field when named keys follow a change of every field in one round', function (): void {
    $this->workflow->enqueue([]);
    $this->workflow->enqueue(['amount']);
    $this->run->resume()->resume(false)->resume();

    expect($this->run->isFinished())->toBeTrue()
        ->and($this->run->activities())->toBe([[
            'activity' => 'Rollups.recomputeRollup',
            'arguments' => [ModelStub::ulid('tenant'), ModelStub::ulid('object-type'), ModelStub::ulid('record'), []],
        ]]);
});

it('returns to named keys in the round after a change of every field was recomputed', function (): void {
    $this->workflow->enqueue([]);
    $this->run->resume()->resume(false);

    $this->workflow->enqueue(['stage']);
    $this->run->resume()->resume(false)->resume();

    expect($this->run->isFinished())->toBeTrue()
        ->and(array_column($this->run->activities(), 'arguments'))->toBe([
            [ModelStub::ulid('tenant'), ModelStub::ulid('object-type'), ModelStub::ulid('record'), []],
            [ModelStub::ulid('tenant'), ModelStub::ulid('object-type'), ModelStub::ulid('record'), ['stage']],
        ]);
});
