<?php

declare(strict_types=1);

use App\Activities\Promotion\RunPromotionActivity;
use App\Contracts\Promotion\PromotionWorkflowInterface;
use App\Contracts\Promotion\RunPromotionActivityInterface;
use App\DTOs\Promotion\PromotionRunData;
use App\Models\PromotionRun;
use App\Support\Promotion\TemporalPromotionDispatcher;
use App\Workflows\Promotion\PromotionWorkflow;
use Spiral\Attributes\AttributeReader;
use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;
use Temporal\Activity\ActivityOptions;
use Temporal\Client\WorkflowClientInterface;
use Temporal\Client\WorkflowOptions;
use Temporal\Internal\Declaration\Reader\ActivityReader;
use Temporal\Internal\Interceptor\Pipeline;
use Temporal\Internal\Workflow\ActivityProxy;
use Temporal\Workflow;
use Temporal\Workflow\ActivityStubInterface;
use Temporal\Workflow\WorkflowContextInterface;
use Temporal\Workflow\WorkflowInterface;
use Temporal\Workflow\WorkflowMethod;
use Temporal\Workflow\WorkflowRunInterface;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

use function React\Promise\resolve;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();

    $this->workflowClient = Mockery::mock(WorkflowClientInterface::class);

    app()->instance(WorkflowClientInterface::class, $this->workflowClient);

    $this->run = ModelStub::make(PromotionRun::class, [
        'id' => ModelStub::ulid('promotion-run'),
        'tenant_id' => $this->tenant->getKey(),
        'triggered_by_id' => ModelStub::ulid('release-manager'),
    ]);
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('starts the promotion workflow with a deterministic workflow id, the configured queue and the run payload', function (): void {
    config()->set('temporal.queue', 'promotion-resume-test-queue');

    $stub = new stdClass;

    /** @var list<array{0: string, 1: WorkflowOptions}> $stubCalls */
    $stubCalls = [];

    /** @var list<array{0: object, 1: list<mixed>}> $startCalls */
    $startCalls = [];

    $this->workflowClient->shouldReceive('newWorkflowStub')
        ->once()
        ->andReturnUsing(function (string $workflowClass, WorkflowOptions $options) use (&$stubCalls, $stub): object {
            $stubCalls[] = [$workflowClass, $options];

            return $stub;
        });

    $this->workflowClient->shouldReceive('start')
        ->once()
        ->andReturnUsing(function (object $workflowStub, mixed ...$arguments) use (&$startCalls): WorkflowRunInterface {
            $startCalls[] = [$workflowStub, $arguments];

            return Mockery::mock(WorkflowRunInterface::class);
        });

    (new TemporalPromotionDispatcher($this->workflowClient))->start($this->run);

    $tenantId = (string) $this->run->tenant_id;
    $runId = (string) $this->run->getKey();

    expect($stubCalls)->toHaveCount(1)
        ->and($stubCalls[0][0])->toBe(PromotionWorkflowInterface::class)
        ->and($stubCalls[0][1]->workflowId)->toBe("promotion:{$tenantId}:{$runId}")
        ->and($stubCalls[0][1]->taskQueue)->toBe('promotion-resume-test-queue')
        ->and($startCalls)->toHaveCount(1)
        ->and($startCalls[0][0])->toBe($stub)
        ->and($startCalls[0][1])->toHaveCount(1);

    $payload = $startCalls[0][1][0];

    expect($payload)->toBeInstanceOf(PromotionRunData::class)
        ->and($payload->tenantId)->toBe($tenantId)
        ->and($payload->promotionRunId)->toBe($runId)
        ->and($payload->actingUserId)->toBe((string) $this->run->triggered_by_id);
});

it('declares a discoverable promotion workflow with promotion activity under the promotion prefix', function (): void {
    $interface = new ReflectionClass(PromotionWorkflowInterface::class);

    expect($interface->getAttributes(WorkflowInterface::class))->not->toBeEmpty()
        ->and((new ReflectionClass(PromotionWorkflow::class))->implementsInterface(PromotionWorkflowInterface::class))->toBeTrue();

    $workflowMethod = (new ReflectionMethod(PromotionWorkflowInterface::class, 'run'))->getAttributes(WorkflowMethod::class);

    expect($workflowMethod)->toHaveCount(1)
        ->and($workflowMethod[0]->newInstance()->name)->toBe('PromotionWorkflow');

    $runParameters = (new ReflectionMethod(PromotionWorkflowInterface::class, 'run'))->getParameters();

    expect($runParameters)->toHaveCount(1)
        ->and((string) $runParameters[0]->getType())->toBe(PromotionRunData::class);

    $activityAttributes = (new ReflectionClass(RunPromotionActivityInterface::class))->getAttributes(ActivityInterface::class);

    expect($activityAttributes)->toHaveCount(1)
        ->and($activityAttributes[0]->newInstance()->prefix)->toBe('Promotion.')
        ->and((new ReflectionClass(RunPromotionActivity::class))->implementsInterface(RunPromotionActivityInterface::class))->toBeTrue();

    $activityMethod = new ReflectionMethod(RunPromotionActivityInterface::class, 'runPromotion');
    $activityMethodAttributes = $activityMethod->getAttributes(ActivityMethod::class);

    expect($activityMethodAttributes)->toHaveCount(1)
        ->and($activityMethodAttributes[0]->newInstance()->name)->toBe('runPromotion')
        ->and(array_map(static fn (ReflectionParameter $parameter): string => (string) $parameter->getType(), $activityMethod->getParameters()))
        ->toBe(['string', 'string', 'string'])
        ->and((string) $activityMethod->getReturnType())->toBe('string');

});

it('builds the single promotion activity with exactly one attempt so a failed promotion is never replayed', function (): void {
    $options = [];
    $activities = [];

    $context = Mockery::mock(WorkflowContextInterface::class);
    $context->shouldReceive('newActivityStub')->andReturnUsing(function (string $class, ActivityOptions $given) use ($context, &$options, &$activities): ActivityProxy {
        $options[] = $given;
        $activities[] = $class;

        return new ActivityProxy($class, (new ActivityReader(new AttributeReader))->fromClass($class), $given, $context, Pipeline::prepare([]));
    });
    $context->shouldReceive('newUntypedActivityStub')->andReturn(Mockery::mock(ActivityStubInterface::class, fn ($stub) => $stub->shouldReceive('execute')->andReturn(resolve(null))));

    Workflow::setCurrentContext($context);

    try {
        (new PromotionWorkflow)->run(new PromotionRunData(
            tenantId: (string) $this->tenant->getKey(),
            promotionRunId: (string) $this->run->getKey(),
            actingUserId: (string) $this->run->triggered_by_id,
        ))->current();
    } finally {
        Workflow::setCurrentContext(null);
    }

    expect($options)->toHaveCount(1)
        ->and($activities)->toBe([RunPromotionActivityInterface::class])
        ->and($options[0]->retryOptions->maximumAttempts)->toBe(1)
        ->and((int) $options[0]->startToCloseTimeout->totalHours)->toBe(6);
});

it('round trips the promotion run payload through its temporal representation', function (): void {
    $data = new PromotionRunData(
        tenantId: ModelStub::ulid('payload-tenant'),
        promotionRunId: ModelStub::ulid('payload-run'),
        actingUserId: ModelStub::ulid('payload-actor'),
    );

    $payload = $data->toTemporalPayload();

    expect($payload)->toEqual([
        'tenant_id' => $data->tenantId,
        'promotion_run_id' => $data->promotionRunId,
        'acting_user_id' => $data->actingUserId,
    ])
        ->and(PromotionRunData::fromTemporalPayload($payload))->toEqual($data);
});
