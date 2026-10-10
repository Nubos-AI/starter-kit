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

    /**
     * @var array<string, string>
     */
    private array $changedFieldKeys = [];

    public function run(string $tenantId, string $objectTypeId, string $recordId): Generator
    {
        $debounce = CarbonInterval::seconds((int) config('engine.rollups.debounce_seconds'));

        yield Workflow::await(fn (): bool => $this->pending);

        do {
            $this->pending = false;
            $extended = yield Workflow::awaitWithTimeout($debounce, fn (): bool => $this->pending);
        } while ($extended);

        $activity = Temporal::newActivity()
            ->withStartToCloseTimeout(CarbonInterval::minutes(2))
            ->withRetryOptions(MaintenanceRetryOptions::make())
            ->build(RecomputeRollupActivityInterface::class);

        if ($this->isOwnRecomputePending) {
            yield $activity->recomputeOwnRollups($tenantId, $objectTypeId, $recordId);
        }

        if ($this->isCascadePending) {
            yield $activity->recomputeRollup($tenantId, $objectTypeId, $recordId, array_values($this->changedFieldKeys));
        }
    }

    /**
     * @param  array<int, string>  $changedFieldKeys
     */
    public function enqueue(array $changedFieldKeys): void
    {
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
