<?php

declare(strict_types=1);

use App\Contracts\Engine\RollupDebounceWorkflowInterface;
use App\DTOs\Engine\RecordTreeNode;
use App\Support\Engine\AncestorChainNotifier;
use App\Support\Engine\RollupDebounceStarter;
use App\Support\Engine\RollupOwnerFilter;
use Illuminate\Support\Facades\DB;
use Temporal\Client\WorkflowClientInterface;
use Temporal\Workflow\SignalMethod;
use Temporal\Workflow\WorkflowRunInterface;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenantId = ModelStub::ulid('tenant');
    $this->objectTypeId = ModelStub::ulid('companies');

    /** @var callable(string, int):RecordTreeNode */
    $this->ancestor = fn (string $seed, int $depth): RecordTreeNode => new RecordTreeNode(
        recordId: ModelStub::ulid($seed),
        objectTypeId: $this->objectTypeId,
        depth: $depth,
    );

    $this->starter = Mockery::mock(RollupDebounceStarter::class);
    $ownerFilter = Mockery::mock(RollupOwnerFilter::class);
    $ownerFilter->shouldReceive('ownsRollups')->andReturn(true);
    $this->notifier = new AncestorChainNotifier($this->starter, $ownerFilter);
});

it('starts one own-recompute for every resolved ancestor of the chain', function (): void {
    $parent = ($this->ancestor)('parent', 1);
    $grandparent = ($this->ancestor)('grandparent', 2);

    $this->starter->shouldReceive('startOwn')->once()->with($this->tenantId, $this->objectTypeId, $parent->recordId);
    $this->starter->shouldReceive('startOwn')->once()->with($this->tenantId, $this->objectTypeId, $grandparent->recordId);

    $this->notifier->notify($this->tenantId, [$parent, $grandparent]);
});

it('notifies an ancestor that appears twice in the chain exactly once', function (): void {
    $parent = ($this->ancestor)('parent', 1);

    $this->starter->shouldReceive('startOwn')->once()->with($this->tenantId, $this->objectTypeId, $parent->recordId);

    $this->notifier->notify($this->tenantId, [$parent, ($this->ancestor)('parent', 3)]);
});

it('starts nothing for an empty ancestor chain', function (): void {
    $this->starter->shouldNotReceive('startOwn');

    $this->notifier->notify($this->tenantId, []);
});

it('leaves the deferral until commit to the shared start buffer instead of registering its own hook', function (): void {
    DB::shouldReceive('afterCommit')->never();
    $this->starter->shouldReceive('startOwn')->once();

    $this->notifier->notify($this->tenantId, [($this->ancestor)('parent', 1)]);
});

it('lets a failing start reach the caller', function (): void {
    $parent = ($this->ancestor)('parent', 1);

    $this->starter->shouldReceive('startOwn')->once()
        ->with($this->tenantId, $this->objectTypeId, $parent->recordId)
        ->andThrow(new RuntimeException('temporal is unreachable'));

    expect(fn () => $this->notifier->notify($this->tenantId, [$parent]))
        ->toThrow(RuntimeException::class, 'temporal is unreachable');
});

it('writes no outbox event and never reaches the database itself', function (): void {
    $this->starter->shouldReceive('startOwn')->once();

    $attempt = QueryShape::attemptedBy(fn () => $this->notifier->notify($this->tenantId, [($this->ancestor)('parent', 1)]));

    expect($attempt)->toBeNull();
});

it('starts the own recompute with a signal the roll-up workflow interface really declares', function (): void {
    $stub = new stdClass;
    $recordId = ModelStub::ulid('parent');

    $signalled = new stdClass;
    $signalled->name = '';
    $signalled->arguments = [];

    $client = Mockery::mock(WorkflowClientInterface::class);
    $client->shouldReceive('newWorkflowStub')->once()->andReturn($stub);
    $client->shouldReceive('startWithSignal')->once()
        ->andReturnUsing(function (object $workflow, string $signal, array $signalArguments, array $startArguments) use ($signalled): WorkflowRunInterface {
            $signalled->name = $signal;
            $signalled->arguments = $startArguments;

            return Mockery::mock(WorkflowRunInterface::class);
        });

    (new RollupDebounceStarter($client))->startOwn($this->tenantId, $this->objectTypeId, $recordId);

    $declared = [];

    foreach ((new ReflectionClass(RollupDebounceWorkflowInterface::class))->getMethods() as $method) {
        if ($method->getAttributes(SignalMethod::class) !== []) {
            $declared[] = $method->getName();
        }
    }

    expect($signalled->name)->toBe('enqueueOwn')
        ->and($declared)->toContain($signalled->name)
        ->and($signalled->arguments)->toBe([$this->tenantId, $this->objectTypeId, $recordId]);
});
