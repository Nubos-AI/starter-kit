<?php

declare(strict_types=1);

use App\Activities\Engine\RelayOutboxActivity;
use App\Activities\Outbox\DispatchOutboxMessagesActivity;
use App\Contracts\Outbox\IdempotentOutboxHandler;
use App\DTOs\Engine\RecordChange;
use App\DTOs\Engine\RecordChangeBatch;
use App\DTOs\Outbox\OutboxDispatchOutcome;
use App\DTOs\Outbox\OutboxMessage;
use App\Exceptions\Outbox\OutboxClaimLostException;
use App\Support\Engine\OutboxRelay;
use App\Support\Outbox\OutboxHandlerRegistry;
use App\Support\Outbox\OutboxMessageDispatcher;
use App\Workflows\Engine\RecordChangeRelayWorkflow;
use Illuminate\Support\Facades\Log;
use Temporal\Activity;
use Temporal\Activity\ActivityContextInterface;
use Temporal\Activity\ActivityInfo;
use Temporal\Workflow\WorkflowExecution;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\Support\WorkflowRun;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $info = new ActivityInfo;
    $info->workflowExecution = new WorkflowExecution('automation-relay-immediate', 'relay-run');
    $info->id = '7';
    $activityContext = Mockery::mock(ActivityContextInterface::class);
    $activityContext->shouldReceive('getInfo')->andReturn($info);
    Activity::setCurrentContext($activityContext);

    $this->dispatcher = Mockery::mock(OutboxMessageDispatcher::class);

    $this->handlerFor = fn (string $topic): IdempotentOutboxHandler => new class($topic) implements IdempotentOutboxHandler
    {
        public function __construct(private readonly string $handledTopic) {}

        public function topic(): string
        {
            return $this->handledTopic;
        }

        public function handle(OutboxMessage $message): void {}
    };
});

afterEach(function (): void {
    Activity::setCurrentContext(null);
    WorkflowRun::end();
    Mockery::close();
});

it('logs the terminal outcome of a dispatch together with the relay run', function (): void {
    Log::spy();
    $this->dispatcher->shouldReceive('dispatchPending')->once()->andReturn(new OutboxDispatchOutcome(handled: 4, retried: 1, failed: 0));

    $settled = (new DispatchOutboxMessagesActivity($this->dispatcher))->dispatchMessages();

    expect($settled)->toBe(5);

    Log::shouldHaveReceived('info')->with('Outbox entries dispatched.', [
        'handled' => 4,
        'retried' => 1,
        'failed' => 0,
        'workflow_id' => 'automation-relay-immediate',
        'run_id' => 'relay-run',
    ]);
});

it('logs and rethrows a dispatch that could not claim its entries so Temporal retries it', function (): void {
    Log::spy();
    $this->dispatcher->shouldReceive('dispatchPending')->once()->andThrow(new RuntimeException('database gone'));

    expect(fn () => (new DispatchOutboxMessagesActivity($this->dispatcher))->dispatchMessages())->toThrow(RuntimeException::class, 'database gone');

    Log::shouldHaveReceived('error')->with('Outbox dispatch failed.', [
        'exception' => RuntimeException::class,
        'workflow_id' => 'automation-relay-immediate',
        'run_id' => 'relay-run',
    ]);
});

it('lists every handler a module registered under the topic it handles', function (): void {
    $first = ($this->handlerFor)('crm.first');
    $second = ($this->handlerFor)('crm.second');
    $alsoFirst = ($this->handlerFor)('crm.first');

    $registry = new OutboxHandlerRegistry([$first, $second, $alsoFirst]);

    expect($registry->handlersFor('crm.first'))->toBe([$first, $alsoFirst])
        ->and($registry->handlersFor('crm.second'))->toBe([$second])
        ->and($registry->handlersFor('crm.unknown'))->toBe([]);
});

it('dispatches the topic entries even when no record changed', function (): void {
    $run = WorkflowRun::start(fn (): Generator => (new RecordChangeRelayWorkflow)->dispatch());

    $run->resume(new RecordChangeBatch([]));

    expect(array_column($run->activities(), 'activity'))->toBe([
        'AutomationDispatch.publishOutbox',
        'Outbox.dispatchMessages',
    ]);
});

it('dispatches the topic entries after the record change consumers', function (): void {
    $change = RecordChange::fromArray([
        'tenant_id' => ModelStub::ulid('relay-tenant'),
        'object_type_id' => ModelStub::ulid('relay-type'),
        'record_id' => ModelStub::ulid('relay-record'),
        'version' => 2,
        'sequence' => 7,
        'changed_field_keys' => ['name'],
    ]);

    $run = WorkflowRun::start(fn (): Generator => (new RecordChangeRelayWorkflow)->dispatch());

    $run->resume(new RecordChangeBatch([$change]));

    expect(array_column($run->activities(), 'activity'))->toBe([
        'AutomationDispatch.publishOutbox',
        'AutomationDispatch.evaluateTriggers',
    ]);

    $run->resume(1)->resume()->resume()->resume();

    expect(array_column($run->activities(), 'activity'))->toContain('Outbox.dispatchMessages')
        ->and(array_column($run->activities(), 'activity'))->toHaveCount(6)
        ->and(array_column($run->activities(), 'activity')[5])->toBe('Outbox.dispatchMessages');
});

it('acknowledges a durable batch only after all consumers completed and then claims the next batch', function (): void {
    $batch = new RecordChangeBatch([RecordChange::fromArray([
        'tenant_id' => ModelStub::ulid('tenant'),
        'object_type_id' => ModelStub::ulid('type'),
        'record_id' => ModelStub::ulid('record'),
        'sequence' => 1,
        'version' => 1,
    ])], 'run:activity');
    $run = WorkflowRun::start(fn (): Generator => (new RecordChangeRelayWorkflow)->dispatch());
    $run->resume($batch);

    for ($consumer = 0; $consumer < 4; $consumer++) {
        expect(array_column($run->activities(), 'activity'))->not->toContain('AutomationDispatch.acknowledgeOutbox');
        $run->resume(true)->resume(1);
    }

    expect(array_column($run->activities(), 'activity'))->toBe([
        'AutomationDispatch.publishOutbox',
        'AutomationDispatch.renewOutbox',
        'AutomationDispatch.evaluateTriggers',
        'AutomationDispatch.renewOutbox',
        'NotificationRules.evaluateRules',
        'AutomationDispatch.renewOutbox',
        'WebhookDispatch.dispatchWebhooks',
        'AutomationDispatch.renewOutbox',
        'Rollups.recomputeRollups',
        'AutomationDispatch.acknowledgeOutbox',
    ]);

    $run->resume()->resume(new RecordChangeBatch([]));

    expect(array_column($run->activities(), 'activity'))->toHaveCount(12)
        ->and(array_column($run->activities(), 'activity')[11])->toBe('Outbox.dispatchMessages');
});

it('never acknowledges a batch when a consumer fails', function (): void {
    $generator = null;
    $run = WorkflowRun::start(function () use (&$generator): Generator {
        return $generator = (new RecordChangeRelayWorkflow)->dispatch();
    });
    $run->resume(new RecordChangeBatch([RecordChange::fromArray([
        'tenant_id' => ModelStub::ulid('tenant'),
        'object_type_id' => ModelStub::ulid('type'),
        'record_id' => ModelStub::ulid('record'),
        'sequence' => 1,
    ])], 'run:activity'))->resume(true);

    expect(fn () => $generator->throw(new RuntimeException('consumer failed')))->toThrow(RuntimeException::class)
        ->and(array_column($run->activities(), 'activity'))->not->toContain('AutomationDispatch.acknowledgeOutbox');
});

it('stops an old execution before invoking consumers after its claim was reclaimed', function (): void {
    $run = WorkflowRun::start(fn (): Generator => (new RecordChangeRelayWorkflow)->dispatch());
    $run->resume(new RecordChangeBatch([RecordChange::fromArray([
        'tenant_id' => ModelStub::ulid('tenant'),
        'object_type_id' => ModelStub::ulid('type'),
        'record_id' => ModelStub::ulid('record'),
        'sequence' => 1,
    ])], 'run:activity'));

    expect(fn () => $run->resume(false))->toThrow(OutboxClaimLostException::class)
        ->and(array_column($run->activities(), 'activity'))->toBe([
            'AutomationDispatch.publishOutbox',
            'AutomationDispatch.renewOutbox',
        ]);
});

it('uses the same claim identity on activity retries', function (): void {
    $relay = Mockery::mock(OutboxRelay::class);
    $relay->shouldReceive('claim')->twice()->with('relay-run:7')->andReturn(new RecordChangeBatch([]));
    $activity = new RelayOutboxActivity($relay);

    expect($activity->publishOutbox()->isEmpty())->toBeTrue()
        ->and($activity->publishOutbox()->isEmpty())->toBeTrue();
});

it('caps the number of batches in one workflow even while the outbox remains full', function (): void {
    $run = WorkflowRun::start(fn (): Generator => (new RecordChangeRelayWorkflow)->dispatch());

    for ($batch = 1; $batch <= 10; $batch++) {
        $run->resume(new RecordChangeBatch([RecordChange::fromArray([
            'tenant_id' => ModelStub::ulid('tenant'),
            'object_type_id' => ModelStub::ulid('type'),
            'record_id' => ModelStub::ulid('record'),
            'sequence' => $batch,
        ])], "run:{$batch}"));

        for ($consumer = 0; $consumer < 4; $consumer++) {
            $run->resume(true)->resume(1);
        }

        $run->resume();
    }

    $activities = array_column($run->activities(), 'activity');

    expect(array_count_values($activities)['AutomationDispatch.publishOutbox'])->toBe(10)
        ->and(end($activities))->toBe('Outbox.dispatchMessages');
});
