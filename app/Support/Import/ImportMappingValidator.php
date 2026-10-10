<?php

declare(strict_types=1);

namespace App\Support\Import;

use App\Enums\Import\ImportIdentityTarget;
use App\Models\FieldDefinition;
use App\Models\ObjectType;
use App\Models\User;
use App\Support\Authorization\FieldVisibilityResolver;
use App\Support\Engine\ObjectTypeFieldLookup;
use Illuminate\Validation\ValidationException;

class ImportMappingValidator
{
    public function __construct(private readonly ObjectTypeFieldLookup $fieldLookup) {}

    /**
     * @param  array<string, mixed>  $mapping
     *
     * @throws ValidationException
     */
    public function validate(ObjectType $objectType, User $user, array $mapping): void
    {
        $targets = $this->targetKeys($mapping);

        if ($targets === []) {
            return;
        }

        $objectTypeId = (string) $objectType->getKey();

        /** @var list<string> $definedKeys */
        $definedKeys = $this->fieldLookup->fields($objectTypeId)
            ->map(static fn (FieldDefinition $field): string => $field->key)
            ->all();

        $forbidden = FieldVisibilityResolver::forRequest()->forbiddenWriteFieldKeys($user, $objectTypeId);

        foreach ($targets as $target) {
            if (ImportIdentityTarget::tryFrom($target) !== null) {
                continue;
            }

            if (!in_array($target, $definedKeys, true)) {
                throw ValidationException::withMessages([
                    'mapping' => __('i18n.backend.support.import.import_mapping_validator.the_target_field_does_not_exist_for_this_object', ['value1' => $target]),
                ]);
            }

            if (in_array($target, $forbidden, true)) {
                throw ValidationException::withMessages([
                    'mapping' => __('i18n.backend.support.import.import_mapping_validator.you_do_not_have_write_permission_for_the_target', ['value1' => $target]),
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $mapping
     * @return list<string>
     */
    private function targetKeys(array $mapping): array
    {
        $columns = $mapping['columns'] ?? null;

        if (!is_array($columns)) {
            return [];
        }

        return array_values(array_filter(
            $columns,
            static fn (mixed $target): bool => is_string($target) && $target !== '',
        ));
    }
}
