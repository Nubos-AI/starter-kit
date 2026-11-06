<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use Generator;
use Mockery;
use React\Promise\PromiseInterface;
use Spiral\Attributes\AttributeReader;
use Temporal\Activity\ActivityOptionsInterface;
use Temporal\Internal\Declaration\Reader\ActivityReader;
use Temporal\Internal\Interceptor\Pipeline;
use Temporal\Internal\Workflow\ActivityProxy;
use Temporal\Workflow;
use Temporal\Workflow\ActivityStubInterface;
use Temporal\Workflow\WorkflowContextInterface;
use Throwable;

use function React\Promise\resolve;

class WorkflowRun
{
    /**
     * @var list<array{step: string, activity: string|null, arguments: array<int, mixed>}>
     */
    public array $steps = [];

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

        $context->shouldReceive('newUntypedActivityStub')->andReturnUsing(fn (): ActivityStubInterface => $this->activityStub());

        $context->shouldReceive('getLogger')->andReturn(new WorkflowRunLogger($this));

        return $context;
    }

    private function activityStub(): ActivityStubInterface
    {
        $stub = Mockery::mock(ActivityStubInterface::class);

        $stub->shouldReceive('execute')->andReturnUsing(fn (string $name, array $arguments = []): PromiseInterface => $this->record('activity', $name, array_values($arguments)));

        return $stub;
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
    private function record(string $step, ?string $activity = null, array $arguments = []): PromiseInterface
    {
        $this->steps[] = ['step' => $step, 'activity' => $activity, 'arguments' => $arguments];

        return resolve(null);
    }
}
