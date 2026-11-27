<?php

declare(strict_types=1);

namespace App\Traits\Temporal;

use App\Enums\Temporal\ProcessFailureType;
use App\Support\Temporal\BoundedActivityOptions;
use App\Support\Temporal\FailureText;
use App\Support\Temporal\ProcessUpdateId;
use Carbon\CarbonInterval;
use Generator;
use React\Promise\PromiseInterface;
use Temporal\Exception\Failure\ApplicationFailure;
use Temporal\Internal\Workflow\ActivityProxy;
use Temporal\Workflow;
use Temporal\Workflow\ScopedContextInterface;
use Temporal\Workflow\UpdateContext;
use Throwable;

trait RunsProcessSteps
{
    private bool $isFirstStepRunning = false;

    private bool $hasFirstStepEnded = false;

    private bool $hasFirstStepSucceeded = false;

    private ?Throwable $firstStepFailure = null;

    private mixed $firstStepResult = null;

    private ?string $firstStepFingerprint = null;

    /**
     * @param  callable(): (PromiseInterface<mixed>|Generator)  $step
     *
     * @throws Throwable
     */
    private function answerFirstStep(callable $step): Generator
    {
        $fingerprint = $this->updateFingerprint();
        $isFirstSubmission = !$this->isFirstStepRunning && !$this->hasFirstStepEnded;

        if (!$isFirstSubmission && $fingerprint !== $this->firstStepFingerprint) {
            Workflow::getLogger()->warning('Process step refused a submission whose input differs from the first one.', $this->processLogContext([]));

            throw new ApplicationFailure('The process step was already submitted with different input.', ProcessFailureType::Conflicting->value, true);
        }

        $this->firstStepFingerprint = $fingerprint;

        if ($this->isFirstStepRunning) {
            yield Workflow::await(fn (): bool => $this->hasFirstStepEnded);
        }

        if ($this->firstStepFailure instanceof Throwable) {
            throw $this->firstStepFailure;
        }

        if ($this->hasFirstStepEnded) {
            return $this->firstStepResult;
        }

        $this->isFirstStepRunning = true;

        try {
            $outcome = $step();
            $this->firstStepResult = $outcome instanceof Generator ? yield from $outcome : yield $outcome;
        } catch (Throwable $failure) {
            $this->firstStepFailure = $failure;
            $this->hasFirstStepEnded = true;

            throw $failure;
        }

        $this->hasFirstStepSucceeded = true;
        $this->hasFirstStepEnded = true;

        return $this->firstStepResult;
    }

    private function awaitFirstStep(): Generator
    {
        $hasAnswered = yield Workflow::awaitWithTimeout(
            CarbonInterval::seconds((int) config('temporal.processes.first_step_timeout_seconds')),
            fn (): bool => $this->hasFirstStepEnded,
        );

        if (!$hasAnswered && $this->isFirstStepRunning) {
            yield Workflow::await(fn (): bool => $this->hasFirstStepEnded);
        }

        return $this->hasFirstStepEnded && $this->hasFirstStepSucceeded;
    }

    /**
     * @template TActivity of object
     *
     * @param  class-string<TActivity>  $activity
     * @return ActivityProxy<TActivity>
     */
    private function processSteps(string $activity, ?int $startToCloseSeconds = null): ActivityProxy
    {
        return BoundedActivityOptions::make(CarbonInterval::seconds($startToCloseSeconds ?? (int) config('temporal.processes.step_start_to_close_seconds')))
            ->build($activity);
    }

    /**
     * @param  array<string, scalar|null>  $context
     * @return array<string, scalar|null>
     */
    private function processLogContext(array $context): array
    {
        $execution = Workflow::getInfo()->execution;

        return [
            'workflow_id' => $execution->getID(),
            'run_id' => $execution->getRunID(),
            ...$context,
        ];
    }

    private function processRunId(): string
    {
        return (string) Workflow::getInfo()->execution->getRunID();
    }

    private function failureMessage(Throwable $failure): string
    {
        return FailureText::of($failure->getPrevious() ?? $failure);
    }

    private function updateFingerprint(): ?string
    {
        if (!Workflow::getCurrentContext() instanceof ScopedContextInterface) {
            return null;
        }

        $update = Workflow::getUpdateContext();

        return $update instanceof UpdateContext ? ProcessUpdateId::fingerprintOf($update->getUpdateId()) : null;
    }
}
