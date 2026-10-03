<?php

declare(strict_types=1);

namespace App\Support\Import;

use App\Enums\Import\ImportDuplicateMode;
use App\Enums\Import\ImportMissingOptionMode;
use App\Models\CustomRecord;
use App\Models\FieldDefinition;
use App\Models\ObjectType;
use App\Models\User;
use App\Support\Authorization\FieldVisibilityResolver;
use App\Support\Engine\ObjectTypeFieldLookup;
use App\Support\Engine\ObjectTypeRegistry;
use App\Support\Engine\RecordExchangeIdentity;
use App\Support\Engine\RecordValidator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class ImportRowClassifier
{
    /**
     * @var array<string, Collection<array-key, FieldDefinition>>
     */
    private array $fieldCache = [];

    /**
     * @var array<string, string|null>
     */
    private array $relationTargetCache = [];

    public function __construct(
        private readonly RecordValidator $recordValidator,
        private readonly ImportRowMapper $mapper,
        private readonly ImportRecordLocator $locator,
        private readonly MissingOptionResolver $missingOptionResolver,
        private readonly RecordExchangeIdentity $exchangeIdentity,
        private readonly ObjectTypeRegistry $objectTypes,
        private readonly ObjectTypeFieldLookup $fieldLookup,
    ) {}

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $mapping
     * @return array{status: string, row: int, message: string|null, identity: string|null}
     */
    public function classify(
        array $row,
        ObjectType $objectType,
        array $mapping,
        ImportDuplicateMode $mode,
        string $tenantId,
        int $rowNumber,
        ImportMissingOptionMode $missingOptionMode = ImportMissingOptionMode::Error,
    ): array {
        $values = $this->mapper->map($row, $objectType, $mapping);
        $identity = $values->externalReferenceId ?? $values->recordNumber;
        $user = Auth::user();

        if (!$user instanceof User) {
            return $this->outcome('error', $rowNumber, $identity, __('i18n.backend.support.import.import_row_classifier.you_may_not_import_records_of_this_type'));
        }

        foreach ($values->relations as $relation) {
            $relationFailure = $this->relationFailure($user, $objectType, $relation['field'], $relation['values'], $tenantId);

            if ($relationFailure !== null) {
                return $this->outcome('error', $rowNumber, $identity, $relationFailure);
            }
        }

        $match = $this->locator->locate(
            $objectType,
            $values->externalReferenceId,
            $values->recordNumber,
            $values->data,
            $tenantId,
        );

        if ($match !== null && !$match->isVisible) {
            return $this->outcome('error', $rowNumber, $identity, __('i18n.backend.support.import.import_row_classifier.the_record_was_not_found'));
        }

        if ($match === null) {
            return $this->insertOutcome($user, $objectType, $values->data, $tenantId, $rowNumber, $identity, $missingOptionMode);
        }

        if (!$match->viaIdentityColumn) {
            if ($mode === ImportDuplicateMode::Skip) {
                return $this->outcome('skip', $rowNumber, $identity);
            }

            if ($mode === ImportDuplicateMode::Insert) {
                if ($match->uniqueField instanceof FieldDefinition) {
                    return $this->outcome(
                        'error',
                        $rowNumber,
                        $identity,
                        __('i18n.backend.support.import.import_row_classifier.the_field_is_marked_as_unique_the_row_cannot', ['value1' => $match->uniqueField->key]),
                    );
                }

                return $this->insertOutcome($user, $objectType, $values->data, $tenantId, $rowNumber, $identity, $missingOptionMode);
            }
        }

        if (!$user->hasPermission("{$objectType->slug}.update")) {
            return $this->outcome('error', $rowNumber, $identity, __('i18n.backend.support.import.import_row_classifier.you_may_not_update_records_of_this_type'));
        }

        return $this->outcome(
            'update',
            $rowNumber,
            $identity,
            $this->validationFailure($objectType, $values->data, $tenantId, $match->record, $missingOptionMode),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{status: string, row: int, message: string|null, identity: string|null}
     */
    private function insertOutcome(
        User $user,
        ObjectType $objectType,
        array $data,
        string $tenantId,
        int $rowNumber,
        ?string $identity,
        ImportMissingOptionMode $missingOptionMode,
    ): array {
        if (!$user->hasPermission("{$objectType->slug}.create")) {
            return $this->outcome('error', $rowNumber, $identity, __('i18n.backend.support.import.import_row_classifier.you_may_not_create_records_of_this_type'));
        }

        return $this->outcome(
            'insert',
            $rowNumber,
            $identity,
            $this->validationFailure($objectType, $data, $tenantId, null, $missingOptionMode),
        );
    }

    /**
     * @param  list<string>  $values
     */
    private function relationFailure(User $user, ObjectType $objectType, FieldDefinition $field, array $values, string $tenantId): ?string
    {
        $forbidden = FieldVisibilityResolver::forRequest()->forbiddenWriteFieldKeys($user, (string) $objectType->getKey());

        if (in_array($field->key, $forbidden, true)) {
            return __('i18n.backend.support.import.import_row_classifier.you_lack_write_permission_for_the_field', ['value1' => $field->key]);
        }

        $targetTypeId = $this->relationTargetTypeId($field);
        $targetType = $targetTypeId === null ? null : $this->objectTypes->find($targetTypeId);

        foreach ($values as $value) {
            $isVisible = $targetType instanceof ObjectType
                && $user->hasPermission("{$targetType->slug}.view")
                && $this->exchangeIdentity->resolve($targetType, $value, $tenantId) !== null;

            if (!$isVisible) {
                return __('i18n.backend.support.import.import_row_classifier.relationship_the_linked_record_was_not_found', ['value1' => $field->key, 'value2' => $value]);
            }
        }

        return null;
    }

    private function relationTargetTypeId(FieldDefinition $field): ?string
    {
        $config = $field->config;
        $relationshipTypeId = is_array($config) ? ($config['relationship_type_id'] ?? null) : null;

        if (!is_string($relationshipTypeId) || $relationshipTypeId === '') {
            return null;
        }

        if (array_key_exists($relationshipTypeId, $this->relationTargetCache)) {
            return $this->relationTargetCache[$relationshipTypeId];
        }

        return $this->relationTargetCache[$relationshipTypeId] = $this->fieldLookup->relationshipTarget($relationshipTypeId);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function validationFailure(
        ObjectType $objectType,
        array $data,
        string $tenantId,
        ?CustomRecord $ignore,
        ImportMissingOptionMode $missingOptionMode,
    ): ?string {
        try {
            $this->recordValidator->validate(
                $objectType,
                $this->dataForValidation($data, $this->fieldsFor($objectType), $missingOptionMode),
                $tenantId,
                $ignore,
            );
        } catch (ValidationException $exception) {
            return $this->firstMessage($exception);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  Collection<array-key, FieldDefinition>  $fields
     * @return array<string, mixed>
     */
    private function dataForValidation(array $data, Collection $fields, ImportMissingOptionMode $missingOptionMode): array
    {
        if ($missingOptionMode !== ImportMissingOptionMode::Create) {
            return $data;
        }

        foreach ($data as $key => $value) {
            $field = $fields->get($key);

            if ($field instanceof FieldDefinition && $this->missingOptionResolver->missingValues($field, $value) !== []) {
                unset($data[$key]);
            }
        }

        return $data;
    }

    /**
     * @return Collection<array-key, FieldDefinition>
     */
    private function fieldsFor(ObjectType $objectType): Collection
    {
        $key = (string) $objectType->getKey();

        if (isset($this->fieldCache[$key])) {
            return $this->fieldCache[$key];
        }

        /** @var Collection<array-key, FieldDefinition> $fields */
        $fields = $this->fieldLookup->fields($key)->keyBy('key');

        return $this->fieldCache[$key] = $fields;
    }

    private function firstMessage(ValidationException $exception): string
    {
        $first = collect($exception->errors())->flatten()->first();

        return is_string($first) ? $first : __('i18n.backend.support.import.import_row_classifier.the_row_could_not_be_validated');
    }

    /**
     * @return array{status: string, row: int, message: string|null, identity: string|null}
     */
    private function outcome(string $status, int $rowNumber, ?string $identity, ?string $message = null): array
    {
        return [
            'status' => $message === null ? $status : 'error',
            'row' => $rowNumber,
            'message' => $message,
            'identity' => $identity,
        ];
    }
}
