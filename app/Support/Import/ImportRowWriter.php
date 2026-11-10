<?php

declare(strict_types=1);

namespace App\Support\Import;

use App\Actions\Engine\CreateRecordAction;
use App\Actions\Engine\LinkRecordsAction;
use App\Actions\Engine\UpdateRecordAction;
use App\DTOs\Import\ImportRowValues;
use App\Enums\Import\ImportDuplicateMode;
use App\Exceptions\StaleRecordException;
use App\Models\CustomRecord;
use App\Models\FieldDefinition;
use App\Models\ObjectType;
use App\Support\Engine\ObjectTypeFieldLookup;
use App\Support\Engine\RecordExchangeIdentity;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class ImportRowWriter
{
    private int $maxLwwAttempts = 5;

    private string $genericError = 'i18n.backend.support.import.import_row_writer.the_row_could_not_be_processed';

    /**
     * @var array<string, string|null>
     */
    private array $relationTargetCache = [];

    public function __construct(
        private readonly ImportRowClassifier $classifier,
        private readonly ImportRowMapper $mapper,
        private readonly ImportRecordLocator $locator,
        private readonly CreateRecordAction $createRecordAction,
        private readonly UpdateRecordAction $updateRecordAction,
        private readonly LinkRecordsAction $linkRecordsAction,
        private readonly RecordExchangeIdentity $exchangeIdentity,
        private readonly ObjectTypeFieldLookup $fieldLookup,
    ) {}

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $mapping
     * @return 'created'|'updated'|'skipped'|'error'
     */
    public function write(
        array $row,
        ObjectType $objectType,
        array $mapping,
        ImportDuplicateMode $mode,
        string $tenantId,
        int $rowNumber,
        string $importJobId,
    ): string {
        $classification = $this->classifier->classify($row, $objectType, $mapping, $mode, $tenantId, $rowNumber);
        $status = $classification['status'];

        if ($status === 'skip') {
            return 'skipped';
        }

        if ($status === 'error') {
            ImportErrorReport::add(
                $importJobId,
                $rowNumber,
                $classification['message'] ?? __($this->genericError),
                $classification['identity'],
            );

            return 'error';
        }

        $values = $this->mapper->map($row, $objectType, $mapping);

        try {
            if ($status === 'insert') {
                $this->insert($objectType, $values, $tenantId);

                return 'created';
            }

            $this->modify($objectType, $values, $tenantId);

            return 'updated';
        } catch (AuthorizationException|ValidationException|StaleRecordException $exception) {
            ImportErrorReport::add($importJobId, $rowNumber, $this->translate($exception), $classification['identity']);

            return 'error';
        }
    }

    /**
     * @throws Throwable
     */
    private function insert(ObjectType $objectType, ImportRowValues $values, string $tenantId): void
    {
        DB::transaction(function () use ($objectType, $values, $tenantId): void {
            $input = [
                'object_type_id' => $objectType->getKey(),
                'data' => $this->withoutNulls($values->data),
            ];

            if ($values->externalReferenceId !== null) {
                $input['external_reference_id'] = $values->externalReferenceId;
            }

            $record = $this->createRecordAction->execute($input, isUserInput: true);

            $this->linkRelations($values, $tenantId, $record);
        });
    }

    /**
     * @throws Throwable
     */
    private function modify(ObjectType $objectType, ImportRowValues $values, string $tenantId): void
    {
        $match = $this->locator->locate(
            $objectType,
            $values->externalReferenceId,
            $values->recordNumber,
            $values->data,
            $tenantId,
        );

        if ($match === null || !$match->isVisible) {
            throw ValidationException::withMessages([
                'record' => __('i18n.backend.support.import.import_row_writer.the_record_to_update_was_not_found'),
            ]);
        }

        $record = $match->record;

        DB::transaction(function () use ($record, $values, $tenantId): void {
            $this->updateWithLastWriterWins($record, $values->data);

            if ($values->relations !== []) {
                $this->linkRelations($values, $tenantId, $record->refresh());
            }
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function updateWithLastWriterWins(CustomRecord $record, array $data): void
    {
        for ($attempt = 1; $attempt <= $this->maxLwwAttempts; $attempt++) {
            $record->refresh();

            try {
                $this->updateRecordAction->execute($record, [
                    'data' => $data,
                    'version' => $record->version,
                ]);

                return;
            } catch (StaleRecordException $exception) {
                if ($attempt === $this->maxLwwAttempts) {
                    throw $exception;
                }
            }
        }
    }

    private function linkRelations(ImportRowValues $values, string $tenantId, CustomRecord $from): void
    {
        foreach ($values->relations as $key => $relation) {
            $relationshipTypeId = $this->relationshipTypeId($relation['field']);

            if ($relationshipTypeId === null) {
                throw ValidationException::withMessages([
                    'relation' => __('i18n.backend.support.import.import_row_writer.relationship_the_relationship_configuration_is_incomplete', ['value1' => $key]),
                ]);
            }

            $targetTypeId = $this->relationTargetTypeId($relationshipTypeId);

            foreach ($relation['values'] as $value) {
                $target = $targetTypeId === null
                    ? null
                    : $this->exchangeIdentity->resolve($targetTypeId, $value, $tenantId);

                if (!$target instanceof Model) {
                    throw ValidationException::withMessages([
                        'relation' => __('i18n.backend.support.import.import_row_writer.relationship_the_linked_record_was_not_found', ['value1' => $key, 'value2' => $value]),
                    ]);
                }

                $this->linkRecordsAction->execute([
                    'relationship_type_id' => $relationshipTypeId,
                    'from_record_id' => $from->getKey(),
                    'to_record_id' => $target->getKey(),
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withoutNulls(array $data): array
    {
        return array_filter($data, static fn (mixed $value): bool => $value !== null);
    }

    private function relationshipTypeId(FieldDefinition $field): ?string
    {
        $config = $field->config;
        $relationshipTypeId = is_array($config) ? ($config['relationship_type_id'] ?? null) : null;

        return is_string($relationshipTypeId) && $relationshipTypeId !== '' ? $relationshipTypeId : null;
    }

    private function relationTargetTypeId(string $relationshipTypeId): ?string
    {
        if (array_key_exists($relationshipTypeId, $this->relationTargetCache)) {
            return $this->relationTargetCache[$relationshipTypeId];
        }

        return $this->relationTargetCache[$relationshipTypeId] = $this->fieldLookup->relationshipTarget($relationshipTypeId);
    }

    private function translate(AuthorizationException|ValidationException|StaleRecordException $exception): string
    {
        if ($exception instanceof ValidationException) {
            $first = collect($exception->errors())->flatten()->first();

            return is_string($first) ? $first : __($this->genericError);
        }

        if ($exception instanceof AuthorizationException) {
            return __('i18n.backend.support.import.import_row_writer.you_lack_write_permission_for_a_field_in_this');
        }

        return __('i18n.backend.support.import.import_row_writer.the_record_could_not_be_updated_version_conflict');
    }
}
