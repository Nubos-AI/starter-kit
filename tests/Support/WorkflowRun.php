<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use DateTimeImmutable;
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
use Temporal\Workflow\ScopedContextInterface;
use Temporal\Workflow\UpdateContext;
use Temporal\Workflow\WorkflowContextInterface;
use Temporal\Workflow\WorkflowExecution;
use Temporal\Workflow\WorkflowInfo;
use Throwable;

use function React\Promise\resolve;

class WorkflowRun
{
    /**
     * @var list<array{handler: string, step: string, activity: string|null, arguments: array<int, mixed>, options: ActivityOptionsInterface|null}>
     */
    public array $steps = [];

    public string $workflowId = 'workflow-run-test-id';

    public string $runId = 'workflow-run-test-run';

    public DateTimeImmutable $now;

    /**
     * @var list<array{level: string, message: string, context: array<string, mixed>}>
     */
    public array $logs = [];

    /**
     * @var list<Closure(): bool>
     */
    private array $awaitedConditions = [];

    /**
     * @var array<string, Generator<mixed, mixed, mixed, mixed>>
     */
    private array $generators = [];

    /**
     * @var array<string, array{waitsFor: string, condition: (Closure(): bool)|null}>
     */
    private array $pending = [];

    /**
     * @var array<string, mixed>
     */
    private array $results = [];

    /**
     * @var array<string, Throwable>
     */
    private array $failures = [];

    /**
     * @var array<string, non-empty-string>
     */
    private array $updateIds = [];

    private string $running = 'run';

    public function __construct()
    {
        $this->now = new DateTimeImmutable('2026-01-05T09:00:00+00:00');
    }

    /**
     * @param  Closure(): Generator<mixed, mixed, mixed, mixed>  $run
     */
    public static function start(Closure $run): self
    {
        $workflowRun = new self;

        Workflow::setCurrentContext($workflowRun->context());

        return $workflowRun->handle('run', $run());
    }

    /**
     * @param  Generator<mixed, mixed, mixed, mixed>  $generator
     */
    public function handle(string $handler, Generator $generator): self
    {
        $this->generators[$handler] = $generator;

        return $this->advance($handler, static fn (Generator $running): mixed => $running->current());
    }

    /**
     * @param  non-empty-string  $updateId
     * @param  Generator<mixed, mixed, mixed, mixed>  $generator
     */
    public function update(string $handler, string $updateId, Generator $generator): self
    {
        $this->updateIds[$handler] = $updateId;

        return $this->handle($handler, $generator);
    }

    public function resume(mixed $value = null, string $handler = 'run'): self
    {
        return $this->advance($handler, static fn (Generator $running): mixed => $running->send($value));
    }

    public function fail(Throwable $exception, string $handler = 'run'): self
    {
        return $this->advance($handler, static fn (Generator $running): mixed => $running->throw($exception));
    }

    public function fireTimer(string $handler = 'run'): self
    {
        return $this->advance($handler, static fn (Generator $running): mixed => $running->send(false));
    }

    public function signal(Closure $signal): self
    {
        $signal();

        return $this->settle();
    }

    public function waitsFor(string $handler = 'run'): ?string
    {
        return $this->pending[$handler]['waitsFor'] ?? null;
    }

    public function lastStep(): ?string
    {
        return collect($this->steps)->last()['step'] ?? null;
    }

    public function isFinished(string $handler = 'run'): bool
    {
        return !$this->generators[$handler]->valid() && !isset($this->failures[$handler]);
    }

    public function resultOf(string $handler): mixed
    {
        return $this->results[$handler] ?? null;
    }

    public function failureOf(string $handler = 'run'): ?Throwable
    {
        return $this->failures[$handler] ?? null;
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

    /**
     * @return list<array{handler: string, arguments: array<int, mixed>}>
     */
    public function activitiesNamed(string $activity): array
    {
        return collect($this->steps)
            ->where('activity', $activity)
            ->map(fn (array $step): array => ['handler' => $step['handler'], 'arguments' => $step['arguments']])
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

    /**
     * @param  Closure(Generator<mixed, mixed, mixed, mixed>): mixed  $step
     */
    private function advance(string $handler, Closure $step): self
    {
        $generator = $this->generators[$handler];
        $previous = $this->running;

        unset($this->pending[$handler]);
        $this->running = $handler;

        try {
            $step($generator);
        } catch (Throwable $failure) {
            $this->failures[$handler] = $failure;
        } finally {
            $this->running = $previous;
        }

        if (!isset($this->failures[$handler]) && !$generator->valid()) {
            $this->results[$handler] = $generator->getReturn();
        }

        return $this->settle();
    }

    private function settle(): self
    {
        foreach ($this->pending as $handler => $pending) {
            if ($pending['condition'] instanceof Closure && ($pending['condition'])()) {
                return $this->advance($handler, static fn (Generator $running): mixed => $running->send(true));
            }
        }

        return $this;
    }

    private function context(): WorkflowContextInterface
    {
        $context = Mockery::mock(WorkflowContextInterface::class.', '.ScopedContextInterface::class);

        $context->shouldReceive('getUpdateContext')->andReturnUsing(fn (): ?UpdateContext => isset($this->updateIds[$this->running]) ? new UpdateContext($this->updateIds[$this->running]) : null);

        $context->shouldReceive('await')->andReturnUsing(fn (mixed ...$conditions): PromiseInterface => $this->wait('await', $conditions));
        $context->shouldReceive('awaitWithTimeout')->andReturnUsing(fn (mixed $timeout, mixed ...$conditions): PromiseInterface => $this->wait('awaitWithTimeout', $conditions));

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

        $context->shouldReceive('now')->andReturnUsing(fn (): DateTimeImmutable => $this->now);

        $context->shouldReceive('allHandlersFinished')->andReturnUsing(fn (): bool => collect($this->generators)
            ->except('run')
            ->every(fn (Generator $generator): bool => !$generator->valid()));

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
     * @param  array<int|string, mixed>  $conditions
     * @return PromiseInterface<null>
     */
    private function wait(string $step, array $conditions): PromiseInterface
    {
        $closures = array_values(array_filter($conditions, static fn (mixed $condition): bool => $condition instanceof Closure));

        foreach ($closures as $closure) {
            $this->awaitedConditions[] = $closure;
        }

        $promise = $this->record($step);

        $this->pending[$this->running]['condition'] = static fn (): bool => array_filter($closures, static fn (Closure $condition): bool => (bool) $condition()) !== [];

        return $promise;
    }

    /**
     * @param  array<int, mixed>  $arguments
     * @return PromiseInterface<null>
     */
    private function record(string $step, ?string $activity = null, array $arguments = [], ?ActivityOptionsInterface $options = null): PromiseInterface
    {
        $this->steps[] = ['handler' => $this->running, 'step' => $step, 'activity' => $activity, 'arguments' => $arguments, 'options' => $options];
        $this->pending[$this->running] = ['waitsFor' => $activity ?? $step, 'condition' => null];

        return resolve(null);
    }
}
