<?php

declare(strict_types=1);

namespace App\Support\Temporal;

use App\Contracts\Temporal\ProcessStarterInterface;
use App\DTOs\Temporal\ProcessStartData;
use App\Exceptions\Temporal\ProcessStepFailedException;
use App\Exceptions\Temporal\ProcessStepRejectedException;
use Carbon\CarbonInterval;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;
use Temporal\Client\Update\LifecycleStage;
use Temporal\Client\Update\UpdateOptions;
use Temporal\Client\WorkflowClientInterface;
use Temporal\Client\WorkflowOptions;
use Temporal\Common\WorkflowIdConflictPolicy;
use Temporal\Exception\Client\WorkflowExecutionAlreadyStartedException;
use Temporal\Exception\Client\WorkflowUpdateException;
use Temporal\Exception\Client\WorkflowUpdateRPCTimeoutOrCanceledException;
use Throwable;

class TemporalProcessStarter implements ProcessStarterInterface
{
    public function __construct(
        private readonly WorkflowClientInterface $workflowClient,
        private readonly Repository $config,
        private readonly ActivityFailures $failures,
        private readonly ProcessFingerprints $fingerprints,
    ) {}

    public function start(ProcessStartData $process): bool
    {
        try {
            $this->workflowClient->start($this->stubFor($process), ...$process->arguments);
        } catch (Throwable $throwable) {
            if (!$this->isAlreadyStarted($throwable)) {
                throw $throwable;
            }

            Log::info('Process start skipped because the workflow is already running.', ['workflow_id' => $process->workflowId]);

            return false;
        }

        return true;
    }

    public function startWithSignal(ProcessStartData $process, string $signal, array $signalArguments = []): void
    {
        $this->workflowClient->signalWithStart($this->stubFor($process), $signal, $signalArguments, $process->arguments);
    }

    public function startWithUpdate(ProcessStartData $process, string $update, array $updateArguments = [], ?string $resultType = null): mixed
    {
        $stub = $this->stubFor($process, $process->conflictPolicy ?? WorkflowIdConflictPolicy::UseExisting);

        $updateOptions = UpdateOptions::new($update, LifecycleStage::StageAccepted)
            ->withUpdateId(ProcessUpdateId::for($this->fingerprints->of([$update, ...$updateArguments])));

        try {
            $handle = $this->workflowClient->updateWithStart($stub, $updateOptions, $updateArguments, $process->arguments);

            return $handle->getEncodedValues($this->updateTimeoutSeconds())->getValue(0, $resultType);
        } catch (WorkflowUpdateException $failure) {
            throw $this->mappedUpdateFailure($process, $update, $failure);
        } catch (WorkflowUpdateRPCTimeoutOrCanceledException $failure) {
            Log::error('Process step did not answer within the update timeout.', ['workflow_id' => $process->workflowId, 'update' => $update]);

            throw ProcessStepFailedException::forUpdate($process->workflowId, $update, $failure);
        } catch (WorkflowExecutionAlreadyStartedException $failure) {
            Log::warning('Process step refused because the workflow id belongs to a closed run.', ['workflow_id' => $process->workflowId, 'update' => $update]);

            throw ProcessStepFailedException::alreadyClosed($process->workflowId, $update, $failure);
        }
    }

    private function mappedUpdateFailure(ProcessStartData $process, string $update, WorkflowUpdateException $failure): Throwable
    {
        $context = ['workflow_id' => $process->workflowId, 'update' => $update];

        if ($this->failures->isConflictIn($failure)) {
            Log::info('Process step refused a submission whose input differs from the one it already answers.', $context);

            return ProcessStepRejectedException::conflictingSubmission($process->submissionField);
        }

        if ($this->failures->isRaisedIn($failure, AuthorizationException::class)) {
            Log::info('Process step was refused for missing rights.', $context);

            return new AuthorizationException;
        }

        if ($this->failures->isRaisedIn($failure, ModelNotFoundException::class)) {
            Log::info('Process step did not find the record it was asked for.', $context);

            return new ModelNotFoundException;
        }

        $errors = $this->failures->rejectionErrorsIn($failure);

        if ($errors !== null) {
            Log::info('Process step rejected its input.', ['workflow_id' => $process->workflowId, 'update' => $update, 'fields' => array_keys($errors)]);

            return ProcessStepRejectedException::fromErrors($errors);
        }

        Log::error('Process step failed.', ['workflow_id' => $process->workflowId, 'update' => $update, 'exception' => ($failure->getPrevious() ?? $failure)::class]);

        return ProcessStepFailedException::forUpdate($process->workflowId, $update, $failure);
    }

    private function stubFor(ProcessStartData $process, ?WorkflowIdConflictPolicy $conflictPolicy = null): object
    {
        $options = WorkflowOptions::new()
            ->withWorkflowId($process->workflowId)
            ->withTaskQueue((string) $this->config->get('temporal.queue'))
            ->withWorkflowIdReusePolicy($process->reusePolicy);

        $conflictPolicy ??= $process->conflictPolicy;

        if ($conflictPolicy instanceof WorkflowIdConflictPolicy) {
            $options = $options->withWorkflowIdConflictPolicy($conflictPolicy);
        }

        if ($process->executionTimeoutSeconds !== null) {
            $options = $options->withWorkflowExecutionTimeout(CarbonInterval::seconds($process->executionTimeoutSeconds));
        }

        return $this->workflowClient->newWorkflowStub($process->workflow, $options);
    }

    private function updateTimeoutSeconds(): int
    {
        return max(1, (int) $this->config->get('temporal.processes.update_timeout_seconds'));
    }

    private function isAlreadyStarted(Throwable $throwable): bool
    {
        return $throwable instanceof WorkflowExecutionAlreadyStartedException
            || str_contains($throwable::class, 'WorkflowExecutionAlreadyStarted');
    }
}
