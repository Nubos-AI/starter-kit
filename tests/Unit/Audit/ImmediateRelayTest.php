<?php

declare(strict_types=1);

use App\Contracts\Engine\RecordChangeRelayWorkflowInterface;
use App\Support\Engine\RecordChangeRelayStarter;
use Illuminate\Support\Facades\Log;
use Temporal\Client\WorkflowClientInterface;
use Temporal\Client\WorkflowOptions;
use Temporal\Client\WorkflowRunInterface;
use Temporal\Exception\Client\WorkflowExecutionAlreadyStartedException;
use Temporal\Workflow\WorkflowExecution;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->client = Mockery::spy(WorkflowClientInterface::class);
    $this->starter = new RecordChangeRelayStarter($this->client);

    $this->client->shouldReceive('newWorkflowStub')->andReturn(new stdClass)->byDefault();
    $this->client->shouldReceive('start')->andReturn(Mockery::mock(WorkflowRunInterface::class))->byDefault();

    /** @var callable():?WorkflowOptions */
    $this->capturedOptions = function (): ?WorkflowOptions {
        $captured = null;

        $this->client->shouldHaveReceived('newWorkflowStub')
            ->once()
            ->withArgs(static function (string $class, WorkflowOptions $options) use (&$captured): bool {
                $captured = $options;

                return $class === RecordChangeRelayWorkflowInterface::class;
            });

        return $captured;
    };
});

it('starts the relay workflow under a fixed id on the configured queue once the flag is on', function (): void {
    config()->set('record-processing.schedules.relay_immediate', true);

    $this->starter->startNow();

    $options = ($this->capturedOptions)();

    expect($options)->toBeInstanceOf(WorkflowOptions::class)
        ->and($options->workflowId)->toBe('automation-relay-immediate')
        ->and($options->taskQueue)->toBe((string) config('temporal.queue'));

    $this->client->shouldHaveReceived('start')->once();
});

it('never touches the workflow client while the flag is off', function (): void {
    config()->set('record-processing.schedules.relay_immediate', false);

    $this->starter->startNow();

    $this->client->shouldNotHaveReceived('newWorkflowStub');
    $this->client->shouldNotHaveReceived('start');
});

it('swallows a duplicate start because the run already in flight drains the outbox', function (): void {
    config()->set('record-processing.schedules.relay_immediate', true);

    $this->client->shouldReceive('start')
        ->andThrow(new WorkflowExecutionAlreadyStartedException(new WorkflowExecution('automation-relay-immediate')));

    $this->starter->startNow();

    $this->client->shouldHaveReceived('start')->once();
});

it('warns instead of failing the write when the relay cannot be started at all', function (): void {
    config()->set('record-processing.schedules.relay_immediate', true);

    Log::spy();

    $this->client->shouldReceive('start')->andThrow(new RuntimeException('temporal is unreachable'));

    $this->starter->startNow();

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(static fn (string $message, array $context): bool => str_contains($message, 'Immediate automation relay start failed')
            && $context['reason'] === 'temporal is unreachable');
});
