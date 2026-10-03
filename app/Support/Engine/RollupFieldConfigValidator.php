<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\DTOs\Engine\RollupConfiguration;
use App\Enums\Engine\ObjectTypeCapability;
use App\Enums\Engine\RollupScope;
use App\Exceptions\Engine\InvalidFilterTreeException;
use App\Models\FieldDefinition;
use App\Models\ObjectType;
use App\Support\Authorization\FieldVisibilityResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
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
    public function validate(FieldDefinition $field): RollupConfiguration
    {
        $config = $field->config ?? [];

        $relationshipTypeId = $this->nonEmptyString($config['relationship_type_id'] ?? null);
        $targetObjectType = $this->targetObjectType($field, $relationshipTypeId);
        $scope = $this->scope($config);

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
            $this->nonEmptyString($config['source_field_key'] ?? null),
            $this->filterFieldKeys($config, $targetObjectType),
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
     * @return list<string>
     *
     * @throws ValidationException
     */
    private function filterFieldKeys(array $config, ObjectType $targetObjectType): array
    {
        $filter = $config['filter'] ?? null;

        if (!is_array($filter) || $filter === []) {
            return [];
        }

        try {
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
