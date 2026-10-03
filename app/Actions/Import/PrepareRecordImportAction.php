<?php

declare(strict_types=1);

namespace App\Actions\Import;

use App\Actions\Engine\AppendFieldOptionsAction;
use App\Enums\Import\ImportJobStatus;
use App\Enums\Import\ImportMissingOptionMode;
use App\Models\FieldDefinition;
use App\Models\ImportJob;
use App\Models\ObjectType;
use App\Models\User;
use App\Support\Import\ImportReaderFactory;
use App\Support\Import\MissingOptionResolver;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Validator;

class PrepareRecordImportAction
{
    public function __construct(
        private readonly ImportReaderFactory $readerFactory,
        private readonly MissingOptionResolver $missingOptionResolver,
        private readonly AppendFieldOptionsAction $appendFieldOptionsAction,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function execute(ObjectType $objectType, User $user, array $input): ImportJob
    {
        $validated = Validator::make(
            $input,
            [
                'path' => ['required', 'string'],
                'format' => ['required', 'string', 'in:csv,xlsx,xls'],
                'sheet' => ['nullable', 'string'],
                'mapping' => ['required', 'array'],
                'duplicate_mode' => ['required', 'string', 'in:skip,upsert,insert'],
                'missing_option_mode' => ['nullable', 'string', 'in:create,error'],
            ]
        )->validate();

        $disk = $this->readerFactory->uploadDisk();
        $path = (string) $validated['path'];
        $format = (string) $validated['format'];
        $sheet = isset($validated['sheet']) ? (string) $validated['sheet'] : null;
        $missingOptionMode = $this->missingOptionResolver->effectiveMode(
            ImportMissingOptionMode::from((string) ($validated['missing_option_mode'] ?? 'error')),
            $user,
            $objectType,
        );

        /** @var array<string, mixed> $mapping */
        $mapping = $validated['mapping'];

        $reader = $this->readerFactory->make($disk, $path, $format, $sheet, $user);

        $selectColumns = $missingOptionMode === ImportMissingOptionMode::Create
            ? $this->inlineSelectColumns($objectType, $mapping)
            : [];

        $totalRows = 0;

        /** @var array<string, array<string, true>> $missingByField */
        $missingByField = [];

        foreach ($reader->rows() as $row) {
            $totalRows++;

            foreach ($selectColumns as $sourceColumn => $field) {
                foreach ($this->missingOptionResolver->missingValues($field, $row[$sourceColumn] ?? null) as $value) {
                    $missingByField[$field->key][$value] = true;
                }
            }
        }

        $this->createMissingOptions($selectColumns, $missingByField);

        return ImportJob::query()->create([
            'tenant_id' => (string) $user->tenant_id,
            'object_type_id' => $objectType->getKey(),
            'user_id' => (string) $user->getKey(),
            'status' => ImportJobStatus::Running,
            'original_filename' => basename($path),
            'source_path' => $path,
            'source_disk' => $disk,
            'format' => $format,
            'mapping' => $mapping,
            'duplicate_mode' => (string) $validated['duplicate_mode'],
            'missing_option_mode' => $missingOptionMode->value,
            'total_rows' => $totalRows,
            'started_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $mapping
     * @return array<string, FieldDefinition>
     */
    private function inlineSelectColumns(ObjectType $objectType, array $mapping): array
    {
        $columns = is_array($mapping['columns'] ?? null) ? $mapping['columns'] : [];

        if ($columns === []) {
            return [];
        }

        /** @var Collection<array-key, FieldDefinition> $fields */
        $fields = $objectType->fieldDefinitions()->get()->keyBy('key');

        $selectColumns = [];

        foreach ($columns as $sourceColumn => $target) {
            if (!is_string($target) || $target === '') {
                continue;
            }

            $field = $fields->get($target);

            if ($field instanceof FieldDefinition && $this->missingOptionResolver->isInlineSelect($field)) {
                $selectColumns[(string) $sourceColumn] = $field;
            }
        }

        return $selectColumns;
    }

    /**
     * @param  array<string, FieldDefinition>  $selectColumns
     * @param  array<string, array<string, true>>  $missingByField
     */
    private function createMissingOptions(array $selectColumns, array $missingByField): void
    {
        if ($missingByField === []) {
            return;
        }

        $fieldsByKey = [];

        foreach ($selectColumns as $field) {
            $fieldsByKey[$field->key] = $field;
        }

        foreach ($missingByField as $fieldKey => $values) {
            $field = $fieldsByKey[$fieldKey] ?? null;

            if ($field instanceof FieldDefinition) {
                $this->appendFieldOptionsAction->execute($field, array_keys($values));
            }
        }
    }
}
