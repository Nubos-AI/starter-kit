<?php

declare(strict_types=1);

namespace App\Contracts\Temporal;

use App\DTOs\Temporal\ProcessStartData;
use App\Exceptions\Temporal\ProcessStepFailedException;
use App\Exceptions\Temporal\ProcessStepRejectedException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

interface ProcessStarterInterface
{
    public function start(ProcessStartData $process): bool;

    /**
     * @param  non-empty-string  $signal
     * @param  list<mixed>  $signalArguments
     */
    public function startWithSignal(ProcessStartData $process, string $signal, array $signalArguments = []): void;

    /**
     * @param  non-empty-string  $update
     * @param  list<mixed>  $updateArguments
     * @param  class-string|string|null  $resultType
     *
     * @throws ProcessStepRejectedException
     * @throws ProcessStepFailedException
     * @throws AuthorizationException
     * @throws ModelNotFoundException
     */
    public function startWithUpdate(ProcessStartData $process, string $update, array $updateArguments = [], ?string $resultType = null): mixed;
}
