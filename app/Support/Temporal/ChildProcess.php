<?php

declare(strict_types=1);

namespace App\Support\Temporal;

use InvalidArgumentException;
use Keepsuit\LaravelTemporal\Builder\ChildWorkflowBuilder;
use Keepsuit\LaravelTemporal\Facade\Temporal;
use React\Promise\PromiseInterface;
use ReflectionClass;
use Temporal\Common\RetryOptions;
use Temporal\Internal\Workflow\ChildWorkflowProxy;
use Temporal\Workflow\ParentClosePolicy;
use Temporal\Workflow\WorkflowMethod;

class ChildProcess
{
    /**
     * @template TWorkflow of object
     *
     * @param  class-string<TWorkflow>  $workflow
     * @param  non-empty-string  $workflowId
     * @return ChildWorkflowProxy<TWorkflow>
     */
    public static function awaited(string $workflow, string $workflowId): ChildWorkflowProxy
    {
        return self::options($workflowId)->build($workflow);
    }

    /**
     * @param  class-string  $workflow
     * @param  non-empty-string  $workflowId
     * @param  list<mixed>  $arguments
     * @return PromiseInterface<mixed>
     */
    public static function detached(string $workflow, string $workflowId, array $arguments = []): PromiseInterface
    {
        return self::options($workflowId)
            ->withParentClosePolicy(ParentClosePolicy::Abandon->value)
            ->buildUntyped(self::workflowType($workflow))
            ->start(...$arguments);
    }

    /**
     * @param  non-empty-string  $workflowId
     */
    private static function options(string $workflowId): ChildWorkflowBuilder
    {
        return Temporal::newChildWorkflow()
            ->withWorkflowId($workflowId)
            ->withRetryOptions(RetryOptions::new()->withMaximumAttempts(1));
    }

    /**
     * @param  class-string  $workflow
     */
    private static function workflowType(string $workflow): string
    {
        foreach ((new ReflectionClass($workflow))->getMethods() as $method) {
            foreach ($method->getAttributes(WorkflowMethod::class) as $attribute) {
                return $attribute->newInstance()->name ?? (new ReflectionClass($workflow))->getShortName();
            }
        }

        throw new InvalidArgumentException("The class \"{$workflow}\" declares no workflow method.");
    }
}
