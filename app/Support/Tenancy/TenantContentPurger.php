<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\DTOs\Tenancy\PurgeReport;
use App\Exceptions\Tenancy\TenantPurgeFailedException;
use App\Support\Engine\IndexRegistry;
use App\Support\Storage\AttachmentStorage;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class TenantContentPurger
{
    /**
     * @var array<string, string>
     */
    private array $triggerNames = [
        'audit_entries' => 'trg_audit_entries_append_only',
        'field_definition_versions' => 'trg_fdv_append_only',
        'object_type_definition_versions' => 'trg_otdv_append_only',
    ];

    /**
     * @var array<string, string>
     */
    private array $reservedOrder = [
        'attachments' => 'tenant_id',
        'audit_entries' => 'tenant_id',
        'field_definition_versions' => 'object_type_id',
        'object_type_definition_versions' => 'object_type_id',
        'outbox_events' => 'tenant_id',
    ];

    /**
     * @var list<string>
     */
    private array $sparedTables = [
        'tenants',
    ];

    /**
     * @var list<string>
     */
    private array $cascadeOnlyTables = [
        'approval_events',
    ];

    private string $carrierTable = 'object_types';

    /**
     * @var positive-int
     */
    private int $batchSize = 1000;

    public function __construct(
        private AttachmentStorage $attachmentStorage,
        private IndexRegistry $indexRegistry,
    ) {}

    /**
     * @param  list<array{table: string, via?: array{column: string, source: string, source_column?: string}}>  $tableOrder
     *
     * @throws TenantPurgeFailedException
     */
    public function purge(string $tenantId, array $tableOrder): PurgeReport
    {
        $transactionLevel = DB::transactionLevel();

        if ($transactionLevel > 0) {
            Log::warning('Tenant content purge runs inside an open transaction; the per-batch trigger window degrades to a savepoint, so the table lock it takes is no longer released per batch but only at the outer commit, and the protected tables stay write-locked installation-wide for the whole run.', [
                'tenant_id' => $tenantId,
                'transaction_level' => $transactionLevel,
            ]);
        }

        [$callerTargets, $skippedTables] = $this->inspect($tenantId, $tableOrder);

        $carriers = $this->resolveCarriers($tenantId, $callerTargets);
        $targets = $this->orderTargets($tenantId, $callerTargets, $carriers);

        $deletedRows = [];
        $deletedFiles = 0;
        $failedFiles = 0;
        $current = 'attachments';

        $report = function () use (&$deletedRows, &$deletedFiles, &$failedFiles, $skippedTables): PurgeReport {
            return new PurgeReport($deletedRows, $deletedFiles, $failedFiles, $skippedTables);
        };

        try {
            $this->deleteAttachmentFiles($tenantId, $deletedFiles, $failedFiles);

            if ($failedFiles > 0) {
                throw $this->fail(
                    __('i18n.backend.support.tenancy.tenant_content_purger.tenant_content_purge_aborted_before_deleting_any_row_because'),
                    $tenantId,
                    $current,
                    $report(),
                );
            }

            foreach ($targets as $target) {
                $current = $target['table'];
                $deletedRows[$current] ??= 0;
                $this->purgeTable($target, $deletedRows[$current]);
            }

            $current = (string) config('engine.records_table');

            foreach ($carriers[$this->carrierTable]['id'] as $objectTypeId) {
                $this->indexRegistry->dropObjectTypeIndexes($objectTypeId);
            }
        } catch (TenantPurgeFailedException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw $this->fail(
                __('i18n.backend.support.tenancy.tenant_content_purger.tenant_content_purge_failed'),
                $tenantId,
                $current,
                $report(),
                $exception,
            );
        }

        return $report();
    }

    /**
     * @param  list<array{table: string, via?: array{column: string, source: string, source_column?: string}}>  $tableOrder
     * @return array{0: list<array{table: string, column: string, carrier: array{source: string, column: string}|null, batched: bool}>, 1: list<string>}
     */
    private function inspect(string $tenantId, array $tableOrder): array
    {
        $targets = [];
        $skipped = [];

        foreach ($tableOrder as $entry) {
            $table = $entry['table'];

            if (in_array($table, $this->cascadeOnlyTables, true)) {
                throw $this->fail("Table [{$table}] is append-only and may only be removed through its parent cascade.", $tenantId, $table);
            }

            if (!Schema::hasTable($table)) {
                throw $this->fail("Table [{$table}] does not exist.", $tenantId, $table);
            }

            if (array_key_exists($table, $this->reservedOrder()) || in_array($table, $this->sparedTables, true)) {
                $skipped[$table] = $table;

                continue;
            }

            $via = $entry['via'] ?? null;
            $batched = Schema::hasColumn($table, 'id');

            if ($via === null) {
                if (!Schema::hasColumn($table, 'tenant_id')) {
                    throw $this->fail("Table [{$table}] has no tenant_id column and no carrier was given.", $tenantId, $table);
                }

                $targets[] = ['table' => $table, 'column' => 'tenant_id', 'carrier' => null, 'batched' => $batched];

                continue;
            }

            $sourceColumn = $via['source_column'] ?? 'id';

            if (!Schema::hasColumn($table, $via['column'])
                || !Schema::hasTable($via['source'])
                || !Schema::hasColumn($via['source'], $sourceColumn)
                || !Schema::hasColumn($via['source'], 'tenant_id')) {
                throw $this->fail("Table [{$table}] cannot be reached through the given carrier.", $tenantId, $table);
            }

            $targets[] = [
                'table' => $table,
                'column' => $via['column'],
                'carrier' => ['source' => $via['source'], 'column' => $sourceColumn],
                'batched' => $batched,
            ];
        }

        return [$targets, array_values($skipped)];
    }

    /**
     * @param  list<array{table: string, column: string, carrier: array{source: string, column: string}|null, batched: bool}>  $targets
     * @return array<string, array<string, list<string>>>
     */
    private function resolveCarriers(string $tenantId, array $targets): array
    {
        $wanted = [[$this->carrierTable, 'id']];

        foreach ($targets as $target) {
            if ($target['carrier'] !== null) {
                $wanted[] = [$target['carrier']['source'], $target['carrier']['column']];
            }
        }

        $carriers = [];

        foreach ($wanted as [$source, $column]) {
            if (isset($carriers[$source][$column])) {
                continue;
            }

            $carriers[$source][$column] = array_values(
                DB::table($source)
                    ->where('tenant_id', $tenantId)
                    ->whereNotNull($column)
                    ->orderBy($column)
                    ->pluck($column)
                    ->map(static fn (mixed $value): string => (string) $value)
                    ->all(),
            );
        }

        return $carriers;
    }

    /**
     * @param  list<array{table: string, column: string, carrier: array{source: string, column: string}|null, batched: bool}>  $callerTargets
     * @param  array<string, array<string, list<string>>>  $carriers
     * @return list<array{table: string, column: string, values: list<string>, batched: bool}>
     */
    private function orderTargets(string $tenantId, array $callerTargets, array $carriers): array
    {
        $carrierIds = $carriers[$this->carrierTable]['id'];

        $targets = [];

        foreach ($this->reservedOrder() as $table => $column) {
            $targets[] = [
                'table' => $table,
                'column' => $column,
                'values' => $column === 'tenant_id' ? [$tenantId] : $carrierIds,
                'batched' => true,
            ];
        }

        foreach (array_reverse($callerTargets) as $target) {
            $carrier = $target['carrier'];

            $targets[] = [
                'table' => $target['table'],
                'column' => $target['column'],
                'values' => $carrier === null ? [$tenantId] : $carriers[$carrier['source']][$carrier['column']],
                'batched' => $target['batched'],
            ];
        }

        return $targets;
    }

    private function deleteAttachmentFiles(string $tenantId, int &$deleted, int &$failed): void
    {
        DB::table('attachments')
            ->where('tenant_id', $tenantId)
            ->select(['id', 'disk', 'path'])
            ->chunkById($this->batchSize, function (Collection $rows) use ($tenantId, &$deleted, &$failed): void {
                foreach ($rows as $row) {
                    $disk = (string) $row->disk;
                    $cause = null;
                    $causeMessage = null;

                    try {
                        $removed = $this->attachmentStorage->delete($disk, (string) $row->path);
                    } catch (Throwable $exception) {
                        $removed = false;
                        $cause = $exception::class;
                        $causeMessage = $exception->getMessage();
                    }

                    if ($removed) {
                        $deleted++;

                        continue;
                    }

                    $failed++;

                    Log::warning('An attachment file could not be deleted.', [
                        'tenant_id' => $tenantId,
                        'attachment_id' => (string) $row->id,
                        'disk' => $disk,
                        'cause' => $cause,
                        'cause_message' => $causeMessage,
                    ]);
                }
            });
    }

    /**
     * @param  array{table: string, column: string, values: list<string>, batched: bool}  $target
     */
    private function purgeTable(array $target, int &$deleted): void
    {
        $table = $target['table'];
        $column = $target['column'];
        $trigger = $this->triggerNames[$table] ?? null;

        foreach (array_chunk($target['values'], $this->batchSize) as $keys) {
            if (DB::table($table)->whereIn($column, $keys)->doesntExist()) {
                continue;
            }

            if (!$target['batched']) {
                $deleted += $this->deleteBatch(
                    $table,
                    $trigger,
                    fn (): int => DB::table($table)->whereIn($column, $keys)->delete(),
                );

                continue;
            }

            do {
                $batch = $this->deleteBatch(
                    $table,
                    $trigger,
                    fn (): int => DB::table($table)
                        ->whereIn('id', function (Builder $query) use ($table, $column, $keys): void {
                            $query->select('id')
                                ->from($table)
                                ->whereIn($column, $keys)
                                ->limit($this->batchSize);
                        })
                        ->delete(),
                );

                $deleted += $batch;
            } while ($batch === $this->batchSize);
        }
    }

    /**
     * @param  callable(): int  $delete
     *
     * @throws Throwable
     */
    private function deleteBatch(string $table, ?string $trigger, callable $delete): int
    {
        return DB::transaction(function () use ($table, $trigger, $delete): int {
            if ($trigger !== null) {
                DB::statement("ALTER TABLE {$table} DISABLE TRIGGER {$trigger}");
            }

            $deleted = $delete();

            if ($trigger !== null) {
                DB::statement("ALTER TABLE {$table} ENABLE TRIGGER {$trigger}");
            }

            return $deleted;
        });
    }

    private function fail(
        string $message,
        string $tenantId,
        string $table,
        ?PurgeReport $report = null,
        ?Throwable $previous = null,
    ): TenantPurgeFailedException {
        $context = ['tenant_id' => $tenantId, 'table' => $table];

        if ($previous !== null) {
            $context['cause'] = $previous::class;
        }

        Log::error($message, $context);

        return new TenantPurgeFailedException($message, $report ?? new PurgeReport([], 0, 0, []), $previous);
    }

    /** @return array<string, string> */
    private function reservedOrder(): array
    {
        return [...$this->reservedOrder, ...config('modules.tenancy.purge_reserved', [])];
    }
}
