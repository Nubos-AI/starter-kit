<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use Generator;
use Mockery;
use React\Promise\PromiseInterface;
use Spiral\Attributes\AttributeReader;
use Temporal\Activity\ActivityOptions;
use Temporal\Activity\ActivityOptionsInterface;
use Temporal\Internal\Declaration\Reader\ActivityReader;
use Temporal\Internal\Interceptor\Pipeline;
use Temporal\Internal\Workflow\ActivityProxy;
use Temporal\Workflow;
use Temporal\Workflow\ActivityStubInterface;
use Temporal\Workflow\WorkflowContextInterface;
use Temporal\Workflow\WorkflowExecution;
use Temporal\Workflow\WorkflowInfo;
use Throwable;

use function React\Promise\resolve;

class WorkflowRun
{
    /**
     * @var list<array{step: string, activity: string|null, arguments: array<int, mixed>, options: ActivityOptionsInterface|null}>
     */
    public array $steps = [];

    public string $workflowId = 'workflow-run-test-id';

    public string $runId = 'workflow-run-test-run';

    /**
     * @var list<array{level: string, message: string, context: array<string, mixed>}>
     */
    public array $logs = [];

    /**
     * @var list<Closure(): bool>
     */
    private array $awaitedConditions = [];

    /**
     * @var Generator<mixed, mixed, mixed, mixed>
     */
    private Generator $generator;

    /**
     * @param  Closure(): Generator<mixed, mixed, mixed, mixed>  $run
     */
    public static function start(Closure $run): self
    {
        $workflowRun = new self;

        Workflow::setCurrentContext($workflowRun->context());

        $workflowRun->generator = $run();
        $workflowRun->generator->current();

        return $workflowRun;
    }

    public function resume(mixed $value = null): self
    {
        $this->generator->send($value);

        return $this;
    }

    public function fail(Throwable $exception): self
    {
        $this->generator->throw($exception);

        return $this;
    }

    public function lastStep(): ?string
    {
        return collect($this->steps)->last()['step'] ?? null;
    }

    public function isFinished(): bool
    {
        return !$this->generator->valid();
    }

    /**
     * @return list<array{activity: string|null, arguments: array<int, mixed>}>
     */
    public function activities(): array
    {
        return collect($this->steps)
            ->where('step', 'activity')
            ->map(fn (array $step): array => ['activity' => $step['activity'], 'arguments' => $step['arguments']])
            ->values()
            ->all();
    }

    public function optionsOf(string $activity): ?ActivityOptions
    {
        $options = collect($this->steps)->firstWhere('activity', $activity)['options'] ?? null;

        return $options instanceof ActivityOptions ? $options : null;
    }

    public function awaitedCondition(): ?Closure
    {
        return $this->awaitedConditions === [] ? null : $this->awaitedConditions[array_key_last($this->awaitedConditions)];
    }

    public static function end(): void
    {
        Workflow::setCurrentContext(null);
    }

    private function context(): WorkflowContextInterface
    {
        $context = Mockery::mock(WorkflowContextInterface::class);

        $context->shouldReceive('await')->andReturnUsing(fn (mixed ...$conditions): PromiseInterface => $this->recordAwait('await', $conditions));
        $context->shouldReceive('awaitWithTimeout')->andReturnUsing(fn (mixed $timeout, mixed ...$conditions): PromiseInterface => $this->recordAwait('awaitWithTimeout', $conditions));

        $context->shouldReceive('newActivityStub')->andReturnUsing(fn (string $class, ActivityOptionsInterface $options): ActivityProxy => new ActivityProxy(
            $class,
            (new ActivityReader(new AttributeReader))->fromClass($class),
            $options,
            $context,
            Pipeline::prepare([]),
        ));

        $context->shouldReceive('newUntypedActivityStub')->andReturnUsing(fn (?ActivityOptionsInterface $options = null): ActivityStubInterface => $this->activityStub($options));

        $context->shouldReceive('getLogger')->andReturn(new WorkflowRunLogger($this));

        $context->shouldReceive('getInfo')->andReturnUsing(fn (): WorkflowInfo => $this->info());

        return $context;
    }

    private function activityStub(?ActivityOptionsInterface $options): ActivityStubInterface
    {
        $stub = Mockery::mock(ActivityStubInterface::class);

        $stub->shouldReceive('execute')->andReturnUsing(fn (string $name, array $arguments = []): PromiseInterface => $this->record('activity', $name, array_values($arguments), $options));

        return $stub;
    }

    private function info(): WorkflowInfo
    {
        $info = new WorkflowInfo;
        $info->execution = new WorkflowExecution($this->workflowId, $this->runId);

        return $info;
    }

    /**
     * @param  array<int, mixed>  $conditions
     * @return PromiseInterface<null>
     */
    private function recordAwait(string $step, array $conditions): PromiseInterface
    {
        foreach ($conditions as $condition) {
            if ($condition instanceof Closure) {
                $this->awaitedConditions[] = $condition;
            }
        }

        return $this->record($step);
    }

    /**
     * @param  array<int, mixed>  $arguments
     * @return PromiseInterface<null>
     */
    private function record(string $step, ?string $activity = null, array $arguments = [], ?ActivityOptionsInterface $options = null): PromiseInterface
    {
        $this->steps[] = ['step' => $step, 'activity' => $activity, 'arguments' => $arguments, 'options' => $options];

        return resolve(null);
    }
}
