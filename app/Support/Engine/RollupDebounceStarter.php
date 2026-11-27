<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\Contracts\Outbox\OutboxWriter;
use App\DTOs\Outbox\OutboxSubject;
use App\Enums\Outbox\OutboxTopic;
use App\Models\CustomRecord;
use Throwable;

class RollupDebounceStarter
{
    /**
     * @var array<string, array{tenant_id: string, object_type_id: string, record_id: string, is_own: bool, is_cascade: bool, is_every_field: bool, keys: array<string, string>}>
     */
    private array $pending = [];

    private int $collectDepth = 0;

    public function __construct(private readonly OutboxWriter $outbox) {}

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
            $result = $work();
        } catch (Throwable $failure) {
            $this->collectDepth--;

            if ($this->collectDepth === 0) {
                $this->pending = [];
            }

            throw $failure;
        }

        $this->collectDepth--;
        $this->flushWhenCollected();

        return $result;
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
     */
    private function buffer(array $entry): void
    {
        $this->pending["{$entry['tenant_id']}:{$entry['record_id']}"] = $entry;

        $this->flushWhenCollected();
    }

    private function flushWhenCollected(): void
    {
        if ($this->collectDepth > 0 || $this->pending === []) {
            return;
        }

        $entries = array_values($this->pending);
        $this->pending = [];

        foreach ($entries as $entry) {
            $this->request($entry);
        }
    }

    /**
     * @param  array{tenant_id: string, object_type_id: string, record_id: string, is_own: bool, is_cascade: bool, is_every_field: bool, keys: array<string, string>}  $entry
     */
    private function request(array $entry): void
    {
        $keys = array_values($entry['keys']);
        sort($keys);

        $this->outbox->record(
            OutboxTopic::RollupRecomputeRequested->value,
            $entry['tenant_id'],
            new OutboxSubject((new CustomRecord)->getMorphClass(), $entry['record_id']),
            [
                'object_type_id' => $entry['object_type_id'],
                'is_own' => $entry['is_own'],
                'is_cascade' => $entry['is_cascade'],
                'changed_field_keys' => $entry['is_every_field'] ? [] : $keys,
            ],
        );
    }
}
