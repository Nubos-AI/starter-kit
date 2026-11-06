<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\Actions\Engine\DeleteRecordAction;
use App\Actions\Engine\RestoreRecordAction;
use App\Actions\Engine\UpdateRecordAction;
use App\Models\CustomRecord;
use App\Models\FieldDefinition;
use App\Models\ObjectType;
use App\Models\User;
use App\Support\Authorization\FieldVisibilityResolver;
use App\Support\Tenancy\ActingUserContext;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class BulkChunkRunner
{
    public function __construct(
        private readonly ActingUserContext $context,
        private readonly RecordSelectionResolver $selectionResolver,
        private readonly UpdateRecordAction $updateRecord,
        private readonly DeleteRecordAction $deleteRecord,
        private readonly RestoreRecordAction $restoreRecord,
    ) {}

    /**
     * @param  list<string>  $recordIds
     * @param  array<string, mixed>  $payload
     */
    public function run(
        string $action,
        string $tenantId,
        string $actingUserId,
        string $objectTypeId,
        array $recordIds,
        array $payload,
        string $reportKey,
    ): void {
        $this->context->run($tenantId, $actingUserId, function (User $actingUser) use (
            $action,
            $tenantId,
            $objectTypeId,
            $recordIds,
            $payload,
            $reportKey,
        ): void {
            match ($action) {
                'set-field' => $this->setField($tenantId, $objectTypeId, $recordIds, $payload, $reportKey),
                'soft-delete' => $this->softDelete($actingUser, $tenantId, $objectTypeId, $recordIds, $payload, $reportKey),
                'restore' => $this->restore($actingUser, $tenantId, $objectTypeId, $recordIds, $reportKey),
                'export-csv' => $this->exportCsv($actingUser, $tenantId, $objectTypeId, $recordIds, $reportKey),
                default => null,
            };
        });

        BulkBatchReport::markChunkProcessed($reportKey);
    }

    /**
     * @param  list<string>  $recordIds
     * @param  array<string, mixed>  $payload
     */
    private function setField(string $tenantId, string $objectTypeId, array $recordIds, array $payload, string $reportKey): void
    {
        $updateRecordAction = $this->updateRecord;

        /** @var list<array{recordId: string, message: string}> $errors */
        $errors = [];

        foreach ($recordIds as $recordId) {
            $record = $this->findRecord($tenantId, $objectTypeId, $recordId);

            if (!$record instanceof CustomRecord) {
                continue;
            }

            try {
                $updateRecordAction->execute($record, [
                    'data' => $payload,
                    'version' => $record->version,
                ]);
            } catch (Throwable $exception) {
                $errors[] = ['recordId' => $recordId, 'message' => $exception->getMessage()];
            }
        }

        $this->reportErrors($reportKey, $errors);
    }

    /**
     * @param  list<string>  $recordIds
     * @param  array<string, mixed>  $payload
     */
    private function softDelete(User $actingUser, string $tenantId, string $objectTypeId, array $recordIds, array $payload, string $reportKey): void
    {
        /** @var list<array{recordId: string, message: string}> $errors */
        $errors = [];

        foreach ($recordIds as $recordId) {
            $record = $this->findRecord($tenantId, $objectTypeId, $recordId);

            if (!$record instanceof CustomRecord) {
                continue;
            }

            if ($actingUser->cannot('delete', $record)) {
                $errors[] = ['recordId' => $recordId, 'message' => __('i18n.backend.support.engine.bulk_chunk_runner.you_may_not_delete_this_record')];

                continue;
            }

            try {
                $this->deleteRecord->execute($record, $payload);
            } catch (Throwable $exception) {
                $errors[] = ['recordId' => $recordId, 'message' => $exception->getMessage()];
            }
        }

        $this->reportErrors($reportKey, $errors);
    }

    /**
     * @param  list<string>  $recordIds
     */
    private function restore(User $actingUser, string $tenantId, string $objectTypeId, array $recordIds, string $reportKey): void
    {
        $restoreRecordAction = $this->restoreRecord;

        /** @var list<array{recordId: string, message: string}> $errors */
        $errors = [];

        foreach ($recordIds as $recordId) {
            $record = CustomRecord::onlyTrashed()
                ->where('tenant_id', $tenantId)
                ->where('object_type_id', $objectTypeId)
                ->whereKey($recordId)
                ->first();

            if (!$record instanceof CustomRecord) {
                continue;
            }

            if ($actingUser->cannot('delete', $record)) {
                $errors[] = ['recordId' => $recordId, 'message' => __('i18n.backend.support.engine.bulk_chunk_runner.you_may_not_restore_this_record')];

                continue;
            }

            try {
                $restoreRecordAction->execute($record);
            } catch (Throwable $exception) {
                $errors[] = ['recordId' => $recordId, 'message' => $exception->getMessage()];
            }
        }

        $this->reportErrors($reportKey, $errors);
    }

    /**
     * @param  list<string>  $recordIds
     */
    private function exportCsv(User $actingUser, string $tenantId, string $objectTypeId, array $recordIds, string $reportKey): void
    {
        $objectType = ObjectType::query()->whereKey($objectTypeId)->firstOrFail();
        $objectType->load('fieldDefinitions');

        $columns = $this->readableColumns($actingUser, $objectType, $objectTypeId);

        $query = $this->selectionResolver
            ->scopedQuery($objectType, ['mode' => 'visible', 'includedIds' => $recordIds], collect())
            ->where('tenant_id', $tenantId);

        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            throw new RuntimeException(__('i18n.backend.support.engine.bulk_chunk_runner.unable_to_open_a_temporary_stream_for_the_csv'));
        }

        fputcsv($handle, $columns);

        $rowCount = 0;

        foreach ($query->cursor() as $record) {
            $data = is_array($record->data) ? $record->data : [];

            fputcsv($handle, array_map(
                fn (string $key): string => $this->cellValue($data[$key] ?? null),
                $columns,
            ));

            $rowCount++;
        }

        if ($rowCount === 0) {
            fclose($handle);

            throw new RuntimeException(__('i18n.backend.support.engine.bulk_chunk_runner.bulk_export_produced_no_visible_rows'));
        }

        rewind($handle);

        $disk = (string) config('engine.bulk.export_disk', config('filesystems.default'));
        $path = "exports/{$tenantId}/{$reportKey}.csv";

        Storage::disk($disk)->writeStream($path, $handle);

        fclose($handle);

        BulkBatchReport::setDownloadPath($reportKey, $path);
    }

    private function findRecord(string $tenantId, string $objectTypeId, string $recordId): ?CustomRecord
    {
        return CustomRecord::query()
            ->where('tenant_id', $tenantId)
            ->ofType($objectTypeId)
            ->whereKey($recordId)
            ->first();
    }

    /**
     * @param  list<array{recordId: string, message: string}>  $errors
     */
    private function reportErrors(string $reportKey, array $errors): void
    {
        if ($errors !== []) {
            BulkBatchReport::addErrors($reportKey, $errors);
        }
    }

    /**
     * @return list<string>
     */
    private function readableColumns(User $actingUser, ObjectType $objectType, string $objectTypeId): array
    {
        $forbidden = FieldVisibilityResolver::forRequest()
            ->forbiddenReadFieldKeys($actingUser, $objectTypeId);

        return array_values(
            $objectType->fieldDefinitions
                ->filter(fn (FieldDefinition $field): bool => !$field->is_encrypted && !in_array($field->key, $forbidden, true))
                ->sortBy([
                    ['list_position', 'asc'],
                    ['key', 'asc'],
                ])
                ->map(fn (FieldDefinition $field): string => $field->key)
                ->all(),
        );
    }

    private function cellValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return (string) json_encode($value);
    }
}
