<?php

declare(strict_types=1);

namespace App\Support\Temporal;

use App\Traits\Temporal\DescribesWorkflowExecution;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class ProcessStepRunner
{
    use DescribesWorkflowExecution;

    public function __construct(private readonly ActivityFailures $failures) {}

    /**
     * @template TResult
     *
     * @param  array<string, scalar|null>  $context
     * @param  callable(): TResult  $work
     * @return TResult
     *
     * @throws Throwable
     */
    public function run(string $step, array $context, callable $work): mixed
    {
        $logContext = ['step' => $step, ...$context, ...$this->workflowExecution()];

        try {
            $result = $work();
        } catch (ValidationException $rejection) {
            Log::info('Process step rejected its input.', [...$logContext, 'fields' => array_keys($rejection->errors())]);

            throw $this->failures->rejected($rejection);
        } catch (Throwable $failure) {
            Log::warning('Process step failed.', [...$logContext, 'exception' => $failure::class]);

            throw $failure;
        }

        Log::info('Process step finished.', $logContext);

        return $result;
    }
}
