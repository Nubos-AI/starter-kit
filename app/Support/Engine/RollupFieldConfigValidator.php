<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\DTOs\Engine\RollupConfiguration;
use App\Enums\CustomFields\FieldType;
use App\Enums\Engine\ObjectTypeCapability;
use App\Enums\Engine\RollupAggregate;
use App\Enums\Engine\RollupScope;
use App\Exceptions\Engine\InvalidFilterTreeException;
use App\Models\FieldDefinition;
use App\Models\ObjectType;
use App\Models\User;
use App\Support\Authorization\FieldVisibilityResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class RollupFieldConfigValidator
{
    public function __construct(
        private readonly FilterTreeValidator $filterTreeValidator,
        private readonly FilterFieldKeyCollector $filterFieldKeyCollector,
        private readonly ObjectTypeCapabilityGuard $capabilities,
        private readonly ObjectTypeFieldLookup $fieldLookup,
    ) {}

    /**
     * @throws ValidationException
     */
    public function validate(FieldDefinition $field, bool $authorizeFilter = true): RollupConfiguration
    {
        $config = $field->config ?? [];

        $relationshipTypeId = $this->nonEmptyString($config['relationship_type_id'] ?? null);
        $targetObjectType = $this->targetObjectType($field, $relationshipTypeId);
        $scope = $this->scope($config);
        $aggregate = $this->aggregate($config);

        if (!$this->capabilities->supports($targetObjectType, ObjectTypeCapability::Rollups)) {
            throw ValidationException::withMessages([
                'config' => __('i18n.backend.support.engine.rollup_field_config_validator.the_object_type_cannot_be_aggregated', ['value1' => $targetObjectType->name]),
            ]);
        }

        if ($scope === RollupScope::Subtree && !$targetObjectType->hasHierarchy()) {
            throw ValidationException::withMessages([
                'config' => __('i18n.backend.support.engine.rollup_field_config_validator.the_entire_subtree_can_only_be_analysed_if_the'),
            ]);
        }

        return new RollupConfiguration(
            $scope,
            $relationshipTypeId,
            (string) $targetObjectType->getKey(),
            $this->sourceFieldKey($config, $aggregate, $targetObjectType, $authorizeFilter),
            $this->filterFieldKeys($config, $targetObjectType, $authorizeFilter),
        );
    }

    /**
     * @param  array<string, mixed>  $config
     *
     * @throws ValidationException
     */
    private function scope(array $config): RollupScope
    {
        $raw = $config['scope'] ?? null;

        if ($raw === null) {
            return RollupScope::DirectChildren;
        }

        $scope = is_string($raw) ? RollupScope::tryFrom($raw) : null;

        if ($scope === null) {
            throw ValidationException::withMessages([
                'config' => __('i18n.backend.support.engine.rollup_field_config_validator.either_direct_child_records_or_the_entire_subtree_are'),
            ]);
        }

        return $scope;
    }

    /**
     * @param  array<string, mixed>  $config
     *
     * @throws ValidationException
     */
    private function aggregate(array $config): RollupAggregate
    {
        $raw = $config['aggregate'] ?? null;

        if ($raw === null) {
            return RollupAggregate::Sum;
        }

        $aggregate = is_string($raw) ? RollupAggregate::tryFrom($raw) : null;

        if ($aggregate === null) {
            throw ValidationException::withMessages([
                'config' => __('i18n.backend.support.engine.rollup_field_config_validator.choose_sum_count_average_minimum_or_maximum'),
            ]);
        }

        return $aggregate;
    }

    /**
     * @param  array<string, mixed>  $config
     *
     * @throws ValidationException
     */
    private function sourceFieldKey(array $config, RollupAggregate $aggregate, ObjectType $targetObjectType, bool $authorizeFilter): ?string
    {
        $sourceFieldKey = $this->nonEmptyString($config['source_field_key'] ?? null);

        if (!$aggregate->readsSourceField()) {
            return $sourceFieldKey;
        }

        if ($sourceFieldKey === null) {
            $this->reject(__('i18n.backend.support.engine.rollup_field_config_validator.choose_the_field_whose_values_are_aggregated'));
        }

        $sourceField = $this->fieldLookup
            ->fields((string) $targetObjectType->getKey())
            ->first(static fn (FieldDefinition $field): bool => $field->key === $sourceFieldKey);

        if (!$sourceField instanceof FieldDefinition) {
            $this->reject(__('i18n.backend.support.engine.rollup_field_config_validator.the_field_does_not_exist_on_the_aggregated_object_type', [
                'value1' => $sourceFieldKey,
                'value2' => $targetObjectType->name,
            ]));
        }

        if (!$sourceField->usesNumericIndex() && $sourceField->field_type !== FieldType::Rollup) {
            $this->reject(__('i18n.backend.support.engine.rollup_field_config_validator.the_field_holds_no_number', ['value1' => $sourceFieldKey]));
        }

        if ((bool) $sourceField->is_encrypted) {
            $this->reject(__('i18n.backend.support.engine.rollup_field_config_validator.the_field_is_encrypted', ['value1' => $sourceFieldKey]));
        }

        if ($authorizeFilter && !$this->isReadable($sourceFieldKey, $targetObjectType)) {
            $this->reject(__('i18n.backend.support.engine.rollup_field_config_validator.you_may_not_read_the_field', ['value1' => $sourceFieldKey]));
        }

        return $sourceFieldKey;
    }

    private function isReadable(string $fieldKey, ObjectType $objectType): bool
    {
        $viewer = Auth::user();

        if (!$viewer instanceof User) {
            return false;
        }

        $forbidden = FieldVisibilityResolver::forRequest()->forbiddenReadFieldKeys($viewer, (string) $objectType->getKey());

        return !in_array($fieldKey, $forbidden, true);
    }

    /**
     * @throws ValidationException
     */
    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['config' => $message]);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<string>
     *
     * @throws ValidationException
     */
    private function filterFieldKeys(array $config, ObjectType $targetObjectType, bool $authorizeFilter): array
    {
        $filter = $config['filter'] ?? null;

        if (!is_array($filter) || $filter === []) {
            return [];
        }

        try {
            if (!$authorizeFilter) {
                $this->filterTreeValidator->validateDefinition($filter, $this->filterableFields($targetObjectType));

                return $this->filterFieldKeyCollector->collect($filter);
            }

            $this->filterTreeValidator->validate(
                $filter,
                $this->filterableFields($targetObjectType),
                FieldVisibilityResolver::forRequest(),
                $targetObjectType,
            );
        } catch (AuthorizationException|InvalidFilterTreeException $exception) {
            throw ValidationException::withMessages([
                'config' => $exception->getMessage(),
            ]);
        }

        return $this->filterFieldKeyCollector->collect($filter);
    }

    private function targetObjectType(FieldDefinition $field, ?string $relationshipTypeId): ObjectType
    {
        $targetObjectTypeId = $field->object_type_id;

        if ($relationshipTypeId !== null) {
            $targetObjectTypeId = $this->fieldLookup->relationshipTarget($relationshipTypeId) ?? $targetObjectTypeId;
        }

        return $this->fieldLookup->objectTypeOrFail((string) $targetObjectTypeId);
    }

    /**
     * @return Collection<int, FieldDefinition>
     */
    private function filterableFields(ObjectType $objectType): Collection
    {
        return $this->fieldLookup->filterableFields((string) $objectType->getKey());
    }

    private function nonEmptyString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
