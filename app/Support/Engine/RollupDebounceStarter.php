<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\Contracts\Engine\RollupDebounceWorkflowInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Temporal\Client\WorkflowClientInterface;
use Temporal\Client\WorkflowOptions;
use Temporal\Common\IdReusePolicy;
use Throwable;

class RollupDebounceStarter
{
    /**
     * @var array<string, array{tenant_id: string, object_type_id: string, record_id: string, is_own: bool, is_cascade: bool, is_every_field: bool, keys: array<string, string>}>
     */
    private array $pending = [];

    private int $collectDepth = 0;

    public function __construct(private readonly WorkflowClientInterface $workflowClient) {}

    /**
     * @param  array<int, string>  $changedFieldKeys
     */
    public function start(string $tenantId, string $objectTypeId, string $recordId, array $changedFieldKeys): void
    {
        $entry = $this->entryFor($tenantId, $objectTypeId, $recordId);
        $entry['is_cascade'] = true;
        $entry['is_every_field'] = $entry['is_every_field'] || $changedFieldKeys === [];

        foreach ($changedFieldKeys as $key) {
            $entry['keys'][$key] = $key;
        }

        $this->buffer($entry);
    }

    public function startOwn(string $tenantId, string $objectTypeId, string $recordId): void
    {
        $entry = $this->entryFor($tenantId, $objectTypeId, $recordId);
        $entry['is_own'] = true;

        $this->buffer($entry);
    }

    /**
     * @template TResult
     *
     * @param  callable(): TResult  $work
     * @return TResult
     *
     * @throws Throwable
     */
    public function collect(callable $work): mixed
    {
        $this->collectDepth++;

        try {
            return $work();
        } finally {
            $this->collectDepth--;
            $this->flushWhenSettled();
        }
    }

    /**
     * @return array{tenant_id: string, object_type_id: string, record_id: string, is_own: bool, is_cascade: bool, is_every_field: bool, keys: array<string, string>}
     */
    private function entryFor(string $tenantId, string $objectTypeId, string $recordId): array
    {
        return $this->pending["{$tenantId}:{$recordId}"] ?? [
            'tenant_id' => $tenantId,
            'object_type_id' => $objectTypeId,
            'record_id' => $recordId,
            'is_own' => false,
            'is_cascade' => false,
            'is_every_field' => false,
            'keys' => [],
        ];
    }

    /**
     * @param  array{tenant_id: string, object_type_id: string, record_id: string, is_own: bool, is_cascade: bool, is_every_field: bool, keys: array<string, string>}  $entry
     *
     * @throws Throwable
     */
    private function buffer(array $entry): void
    {
        $this->pending["{$entry['tenant_id']}:{$entry['record_id']}"] = $entry;

        if (DB::transactionLevel() > 0) {
            DB::afterCommit(fn () => $this->flushWhenSettled());
            DB::afterRollBack(fn () => $this->discardAfterRollback());

            return;
        }

        $this->flushWhenSettled();
    }

    /**
     * @throws Throwable
     */
    private function flushWhenSettled(): void
    {
        if ($this->collectDepth > 0 || DB::transactionLevel() > 0 || $this->pending === []) {
            return;
        }

        $entries = array_values($this->pending);
        $this->pending = [];

        $startedCount = 0;

        /** @var list<array{tenant_id: string, record_id: string}> $failed */
        $failed = [];
        $firstFailure = null;

        foreach ($entries as $entry) {
            try {
                $this->send($entry);
                $startedCount++;
            } catch (Throwable $exception) {
                $failed[] = ['tenant_id' => $entry['tenant_id'], 'record_id' => $entry['record_id']];
                $firstFailure ??= $exception;
            }
        }

        if ($firstFailure === null) {
            return;
        }

        Log::error('Failed to start roll-up recomputes after the write committed.', [
            'failed' => $failed,
            'failed_count' => count($failed),
            'started_count' => $startedCount,
            'pending_count' => count($entries),
            'exception' => $firstFailure::class,
        ]);

        throw $firstFailure;
    }

    private function discardAfterRollback(): void
    {
        if ($this->collectDepth > 0 || DB::transactionLevel() > 0) {
            return;
        }

        $this->pending = [];
    }

    /**
     * @param  array{tenant_id: string, object_type_id: string, record_id: string, is_own: bool, is_cascade: bool, is_every_field: bool, keys: array<string, string>}  $entry
     */
    private function send(array $entry): void
    {
        $startArguments = [$entry['tenant_id'], $entry['object_type_id'], $entry['record_id']];

        if ($entry['is_cascade']) {
            $this->workflowClient->startWithSignal(
                $this->newStub($entry['tenant_id'], $entry['record_id']),
                'enqueue',
                [$entry['is_every_field'] ? [] : array_values($entry['keys'])],
                $startArguments,
            );
        }

        if ($entry['is_own']) {
            $this->workflowClient->startWithSignal(
                $this->newStub($entry['tenant_id'], $entry['record_id']),
                'enqueueOwn',
                [],
                $startArguments,
            );
        }
    }

    private function newStub(string $tenantId, string $recordId): object
    {
        return $this->workflowClient->newWorkflowStub(
            RollupDebounceWorkflowInterface::class,
            WorkflowOptions::new()
                ->withWorkflowId("rollup:{$tenantId}:{$recordId}")
                ->withWorkflowIdReusePolicy(IdReusePolicy::AllowDuplicate)
                ->withTaskQueue((string) config('temporal.queue')),
        );
    }
}
