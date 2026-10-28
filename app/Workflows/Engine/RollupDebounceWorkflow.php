<?php

declare(strict_types=1);

namespace App\Workflows\Engine;

use App\Contracts\Engine\RecomputeRollupActivityInterface;
use App\Contracts\Engine\RollupDebounceWorkflowInterface;
use App\Support\Maintenance\MaintenanceRetryOptions;
use Carbon\CarbonInterval;
use Generator;
use Keepsuit\LaravelTemporal\Facade\Temporal;
use Temporal\Workflow;

class RollupDebounceWorkflow implements RollupDebounceWorkflowInterface
{
    private bool $pending = false;

    private bool $isOwnRecomputePending = false;

    private bool $isCascadePending = false;

    private bool $isEveryFieldChanged = false;

    /**
     * @var array<string, string>
     */
    private array $changedFieldKeys = [];

    public function run(string $tenantId, string $objectTypeId, string $recordId): Generator
    {
        $debounce = CarbonInterval::seconds((int) config('engine.rollups.debounce_seconds'));

        $activity = Temporal::newActivity()
            ->withStartToCloseTimeout(CarbonInterval::minutes(2))
            ->withRetryOptions(MaintenanceRetryOptions::make())
            ->build(RecomputeRollupActivityInterface::class);

        yield Workflow::await(fn (): bool => $this->pending);

        while ($this->pending) {
            do {
                $this->pending = false;
                $extended = yield Workflow::awaitWithTimeout($debounce, fn (): bool => $this->pending);
            } while ($extended);

            $isOwnRecomputePending = $this->isOwnRecomputePending;
            $isCascadePending = $this->isCascadePending;
            $changedFieldKeys = $this->isEveryFieldChanged ? [] : array_values($this->changedFieldKeys);

            $this->isOwnRecomputePending = false;
            $this->isCascadePending = false;
            $this->isEveryFieldChanged = false;
            $this->changedFieldKeys = [];

            if ($isOwnRecomputePending) {
                yield $activity->recomputeOwnRollups($tenantId, $objectTypeId, $recordId);
            }

            if ($isCascadePending) {
                yield $activity->recomputeRollup($tenantId, $objectTypeId, $recordId, $changedFieldKeys);
            }
        }
    }

    /**
     * @param  array<int, string>  $changedFieldKeys
     */
    public function enqueue(array $changedFieldKeys): void
    {
        if ($changedFieldKeys === []) {
            $this->isEveryFieldChanged = true;
        }

        foreach ($changedFieldKeys as $key) {
            $this->changedFieldKeys[$key] = $key;
        }

        $this->isCascadePending = true;
        $this->pending = true;
    }

    public function enqueueOwn(): void
    {
        $this->isOwnRecomputePending = true;
        $this->pending = true;
    }
}
