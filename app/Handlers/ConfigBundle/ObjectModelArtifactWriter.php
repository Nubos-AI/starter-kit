<?php

declare(strict_types=1);

namespace App\Handlers\ConfigBundle;

use App\Actions\Engine\CreateFieldDefinitionAction;
use App\Actions\Engine\CreateFieldGroupAction;
use App\Actions\Engine\CreateMergeRuleAction;
use App\Actions\Engine\CreateObjectTypeAction;
use App\Actions\Engine\CreateRelationshipTypeAction;
use App\Actions\Engine\CreateReminderTypeAction;
use App\Actions\Engine\DeleteFieldDefinitionAction;
use App\Actions\Engine\DeleteFieldGroupAction;
use App\Actions\Engine\DeleteMergeRuleAction;
use App\Actions\Engine\DeleteObjectTypeAction;
use App\Actions\Engine\DeleteRelationshipTypeAction;
use App\Actions\Engine\DeleteReminderTypeAction;
use App\Actions\Engine\PurgeObjectTypeAction;
use App\Actions\Engine\UpdateFieldDefinitionAction;
use App\Actions\Engine\UpdateFieldGroupAction;
use App\Actions\Engine\UpdateMergeRuleAction;
use App\Actions\Engine\UpdateObjectTypeAction;
use App\Actions\Engine\UpdateRelationshipTypeAction;
use App\Actions\Engine\UpdateReminderTypeAction;
use App\Contracts\ConfigBundle\ArtifactRefusalInterface;
use App\Contracts\ConfigBundle\ArtifactWriterInterface;
use App\DTOs\ConfigBundle\ArtifactRefusal;
use App\DTOs\ConfigBundle\ArtifactWriteResult;
use App\DTOs\ConfigBundle\BundleArtifact;
use App\Enums\ConfigBundle\ArtifactKind;
use App\Enums\ConfigBundle\ArtifactWriteAction;
use App\Enums\CustomFields\FieldType;
use App\Enums\Engine\RollupScope;
use App\Enums\Engine\StorageStrategy;
use App\Enums\Promotion\DiffState;
use App\Models\FieldDefinition;
use App\Models\FieldGroup;
use App\Models\MergeRule;
use App\Models\ObjectType;
use App\Models\RelationshipType;
use App\Models\ReminderType;
use App\Models\User;
use App\Support\ConfigBundle\TargetKeyResolver;
use App\Support\Engine\FilterFieldKeyCollector;
use App\Support\Engine\SystemObjectTypeGuard;
use App\Support\Tenancy\ActingUserContext;
use App\Traits\ConfigBundle\WritesConfigurationArtifacts;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Throwable;

class ObjectModelArtifactWriter implements ArtifactRefusalInterface, ArtifactWriterInterface
{
    use WritesConfigurationArtifacts;

    public function __construct(
        private readonly ActingUserContext $actingUserContext,
        private readonly CreateFieldDefinitionAction $createFieldDefinition,
        private readonly CreateFieldGroupAction $createFieldGroup,
        private readonly CreateMergeRuleAction $createMergeRule,
        private readonly CreateObjectTypeAction $createObjectType,
        private readonly CreateRelationshipTypeAction $createRelationshipType,
        private readonly CreateReminderTypeAction $createReminderType,
        private readonly DeleteFieldDefinitionAction $deleteFieldDefinition,
        private readonly DeleteFieldGroupAction $deleteFieldGroup,
        private readonly DeleteMergeRuleAction $deleteMergeRule,
        private readonly DeleteObjectTypeAction $deleteObjectType,
        private readonly DeleteRelationshipTypeAction $deleteRelationshipType,
        private readonly DeleteReminderTypeAction $deleteReminderType,
        private readonly FilterFieldKeyCollector $filterFieldKeys,
        private readonly PurgeObjectTypeAction $purgeObjectType,
        private readonly SystemObjectTypeGuard $systemObjectTypeGuard,
        private readonly TargetKeyResolver $targetKeys,
        private readonly UpdateFieldDefinitionAction $updateFieldDefinition,
        private readonly UpdateFieldGroupAction $updateFieldGroup,
        private readonly UpdateMergeRuleAction $updateMergeRule,
        private readonly UpdateObjectTypeAction $updateObjectType,
        private readonly UpdateRelationshipTypeAction $updateRelationshipType,
        private readonly UpdateReminderTypeAction $updateReminderType,
    ) {}

    public function supports(ArtifactKind $kind): bool
    {
        return match ($kind) {
            ArtifactKind::ObjectTypes,
            ArtifactKind::FieldGroups,
            ArtifactKind::FieldDefinitions,
            ArtifactKind::RelationshipTypes,
            ArtifactKind::FieldDependencies,
            ArtifactKind::MergeRules,
            ArtifactKind::ReminderTypes => true,
            default => false,
        };
    }

    /**
     * @throws AuthorizationException
     */
    public function apply(User $actingUser, ArtifactKind $kind, BundleArtifact $artifact, DiffState $state): ArtifactWriteResult
    {
        return $this->runAs($actingUser, fn (): ArtifactWriteResult => $this->applied($kind, $artifact, $state));
    }

    /**
     * @throws AuthorizationException
     */
    public function remove(User $actingUser, ArtifactKind $kind, string $key): ArtifactWriteResult
    {
        return $this->runAs($actingUser, fn (): ArtifactWriteResult => $this->removed($kind, $key));
    }

    /**
     * @return list<ArtifactWriteResult>
     */
    public function flush(User $actingUser): array
    {
        return [];
    }

    public function refusalFor(ArtifactKind $kind, BundleArtifact $artifact, DiffState $state): ?ArtifactRefusal
    {
        return $kind === ArtifactKind::ObjectTypes ? $this->objectTypeRefusal($artifact, $state) : null;
    }

    /**
     * @throws Throwable
     */
    public function clearForOverwrite(ArtifactKind $kind, BundleArtifact $artifact): void
    {
        $holder = $kind === ArtifactKind::ObjectTypes ? $this->slugHolderOf($artifact) : null;

        if ($holder instanceof ObjectType && $holder->trashed() && $this->targetKeys->idFor($kind, $artifact->key) === null) {
            $this->purgeObjectType->execute($holder);
        }
    }

    private function applied(ArtifactKind $kind, BundleArtifact $artifact, DiffState $state): ArtifactWriteResult
    {
        if ($state !== DiffState::Added && $state !== DiffState::Modified) {
            return $this->skippedByState($kind, $artifact->key, $state);
        }

        $result = match ($kind) {
            ArtifactKind::ObjectTypes => $this->applyObjectType($artifact, $state),
            ArtifactKind::FieldGroups => $this->applyFieldGroup($artifact, $state),
            ArtifactKind::FieldDefinitions => $this->applyFieldDefinition($artifact, $state),
            ArtifactKind::RelationshipTypes => $this->applyRelationshipType($artifact, $state),
            ArtifactKind::MergeRules => $this->applyMergeRule($artifact, $state),
            ArtifactKind::ReminderTypes => $this->applyReminderType($artifact, $state),
            default => $this->skipped(
                $kind,
                $artifact->key,
                __('i18n.backend.handlers.config_bundle.object_model_artifact_writer.field_dependencies_are_derived_from_field_configuration_and_created'),
            ),
        };

        return $this->invalidated($kind, $result);
    }

    private function removed(ArtifactKind $kind, string $key): ArtifactWriteResult
    {
        $result = match ($kind) {
            ArtifactKind::ObjectTypes => $this->removeObjectType($key),
            ArtifactKind::FieldGroups => $this->removeFieldGroup($key),
            ArtifactKind::FieldDefinitions => $this->removeFieldDefinition($key),
            ArtifactKind::RelationshipTypes => $this->removeRelationshipType($key),
            ArtifactKind::MergeRules => $this->removeMergeRule($key),
            ArtifactKind::ReminderTypes => $this->removeReminderType($key),
            default => $this->skipped(
                $kind,
                $key,
                __('i18n.backend.handlers.config_bundle.object_model_artifact_writer.field_dependencies_are_derived_from_field_configuration_they_are'),
            ),
        };

        return $this->invalidated($kind, $result);
    }

    private function applyObjectType(BundleArtifact $artifact, DiffState $state): ArtifactWriteResult
    {
        $kind = ArtifactKind::ObjectTypes;
        $payload = $artifact->payload;
        $refusal = $this->objectTypeRefusal($artifact, $state);

        if ($refusal !== null) {
            return $this->skipped($kind, $artifact->key, $refusal->reason);
        }

        $hierarchy = $this->textOf($payload, 'hierarchy_relationship_type_id');
        $hierarchyCarrierId = $hierarchy === null ? null : $this->targetKeys->idFor(ArtifactKind::RelationshipTypes, $hierarchy);
        $hierarchyCarrier = $hierarchyCarrierId === null ? null : RelationshipType::query()->whereKey($hierarchyCarrierId)->first();
        $awaitedReference = $this->awaitedHierarchyReference($hierarchy, $hierarchyCarrier);
        $notes = [];

        if ($hierarchyCarrier instanceof RelationshipType) {
            $notes[] = __('i18n.backend.handlers.config_bundle.object_model_artifact_writer.the_hierarchy_relationship_type_id_reference_is_transferred_as');
        }

        $existingId = $this->targetKeys->idFor($kind, $artifact->key);
        $action = ArtifactWriteAction::Updated;
        $slug = $this->textOf($payload, 'slug');
        $storageStrategy = $this->storageStrategyOf($payload);
        $restorableInput = array_key_exists('dedup_keys', $payload)
            ? ['dedup_keys' => $payload['dedup_keys']]
            : [];

        if ($existingId === null) {
            if ($slug === null) {
                $notes[] = __('i18n.backend.handlers.config_bundle.object_model_artifact_writer.the_artifact_has_no_slug_the_short_name_was');
            }

            $objectType = $this->createObjectType->execute(
                [
                    'key' => $artifact->key,
                    'name' => $payload['name'] ?? null,
                    'business_key_prefix' => $payload['business_key_prefix'] ?? null,
                    'record_number_format' => $payload['record_number_format'] ?? null,
                ],
                false,
                StorageStrategy::tryFrom($storageStrategy ?? '') ?? StorageStrategy::Generic,
                $slug,
                false,
            );

            $action = ArtifactWriteAction::Created;
        } else {
            $objectType = ObjectType::query()->whereKey($existingId)->firstOrFail();

            if ($state === DiffState::Added) {
                $notes[] = $this->fallbackNote(__('i18n.backend.handlers.config_bundle.object_model_artifact_writer.object_type'));
            }

            if ($slug !== null && $slug !== $objectType->slug) {
                $notes[] = __('i18n.backend.handlers.config_bundle.object_model_artifact_writer.the_slug_of_an_existing_object_type_cannot_be', ['value1' => $objectType->slug]);
            }

            if ($storageStrategy !== null) {
                $restorableInput['storage_strategy'] = $storageStrategy;
            }
        }

        $this->updateObjectType->execute($objectType, [
            'name' => $payload['name'] ?? null,
            ...$this->businessKeyInput($payload, $objectType),
            ...$restorableInput,
            ...$this->hierarchyInput($hierarchy, $hierarchyCarrier),
            'is_navigable' => $payload['is_navigable'] ?? true,
            'nav_icon' => $payload['nav_icon'] ?? null,
            'nav_position' => $payload['nav_position'] ?? 0,
            'requires_deletion_reason' => $payload['requires_deletion_reason'] ?? false,
            'retention_days' => $payload['retention_days'] ?? null,
        ], $hierarchyCarrier);

        return $this->written($kind, $artifact->key, $action, $objectType, $notes, $awaitedReference);
    }

    private function objectTypeRefusal(BundleArtifact $artifact, DiffState $state): ?ArtifactRefusal
    {
        if (($artifact->payload['is_system'] ?? false) === true) {
            return new ArtifactRefusal(__('i18n.backend.handlers.config_bundle.object_model_artifact_writer.this_artifact_describes_a_system_object_type_system_object'));
        }

        $existingId = $this->targetKeys->idFor(ArtifactKind::ObjectTypes, $artifact->key);

        if ($existingId === null) {
            if ($state === DiffState::Modified) {
                return new ArtifactRefusal($this->missingTargetReason($artifact->key));
            }

            $holder = $this->slugHolderOf($artifact);

            return $holder instanceof ObjectType ? $this->takenSlugRefusal($holder) : null;
        }

        $objectType = ObjectType::query()->whereKey($existingId)->first();

        if (!$objectType instanceof ObjectType) {
            return new ArtifactRefusal($this->missingTargetReason($artifact->key));
        }

        $reason = $this->manageabilityReason($objectType);

        return $reason === null ? null : new ArtifactRefusal($reason);
    }

    private function slugHolderOf(BundleArtifact $artifact): ?ObjectType
    {
        $slug = $this->textOf($artifact->payload, 'slug');

        return $slug === null ? null : ObjectType::withTrashed()->where('slug', $slug)->first();
    }

    private function takenSlugRefusal(ObjectType $holder): ArtifactRefusal
    {
        if (!$holder->trashed()) {
            return new ArtifactRefusal(__('i18n.backend.handlers.config_bundle.object_model_artifact_writer.the_active_object_type_already_uses_the_slug_under', ['value1' => $holder->name, 'value2' => $holder->slug]));
        }

        $holderId = (string) $holder->getKey();
        $fieldCount = DB::table('field_definitions')->where('object_type_id', $holderId)->count();
        $recordCount = DB::table('custom_records')->where('object_type_id', $holderId)->count();

        return new ArtifactRefusal(
            __('i18n.backend.handlers.config_bundle.object_model_artifact_writer.the_target_tenant_already_has_a_deleted_object_type', ['value1' => $holder->name, 'value2' => $holder->slug]),
            __('i18n.backend.handlers.config_bundle.object_model_artifact_writer.overwriting_permanently_removes_the_deleted_object_type_with_and', ['value1' => $holder->name, 'value2' => $this->countOf($fieldCount, __('i18n.backend.handlers.config_bundle.object_model_artifact_writer.field'), __('i18n.backend.handlers.config_bundle.object_model_artifact_writer.fields')), 'value3' => $this->countOf($recordCount, __('i18n.backend.handlers.config_bundle.object_model_artifact_writer.record'), __('i18n.backend.handlers.config_bundle.object_model_artifact_writer.records'))]),
        );
    }

    private function countOf(int $count, string $singular, string $plural): string
    {
        return $count === 1 ? "1 {$singular}" : "{$count} {$plural}";
    }

    private function removeObjectType(string $key): ArtifactWriteResult
    {
        $kind = ArtifactKind::ObjectTypes;
        $id = $this->targetKeys->idFor($kind, $key);
        $objectType = $id === null ? null : ObjectType::query()->whereKey($id)->first();

        if (!$objectType instanceof ObjectType) {
            return $this->missingTarget($kind, $key);
        }

        $reason = $this->manageabilityReason($objectType);

        if ($reason !== null) {
            return $this->skipped($kind, $key, $reason);
        }

        $this->deleteObjectType->execute($objectType);

        return $this->written($kind, $key, ArtifactWriteAction::Removed, $objectType);
    }

    private function applyFieldGroup(BundleArtifact $artifact, DiffState $state): ArtifactWriteResult
    {
        $kind = ArtifactKind::FieldGroups;
        $payload = $artifact->payload;
        $objectType = $this->objectTypeOf($kind, $artifact->key);

        if (!$objectType instanceof ObjectType) {
            return $this->unresolvable($kind, $artifact->key, 'object_type_id', $artifact->key);
        }

        $reason = $this->manageabilityReason($objectType);

        if ($reason !== null) {
            return $this->skipped($kind, $artifact->key, $reason);
        }

        $input = [
            'position' => $payload['position'] ?? 0,
            'i18n_labels' => $payload['i18n_labels'] ?? null,
            'i18n_descriptions' => $payload['i18n_descriptions'] ?? null,
        ];

        $existingId = $this->targetKeys->idFor($kind, $artifact->key);

        if ($existingId === null) {
            if ($state === DiffState::Modified) {
                return $this->missingTarget($kind, $artifact->key);
            }

            $group = $this->createFieldGroup->execute($objectType, [
                'key' => $this->localNameOf($artifact->key),
                ...$input,
            ]);

            return $this->written($kind, $artifact->key, ArtifactWriteAction::Created, $group);
        }

        $group = FieldGroup::query()->whereKey($existingId)->first();

        if (!$group instanceof FieldGroup) {
            return $this->missingTarget($kind, $artifact->key);
        }

        $this->updateFieldGroup->execute($group, $input);

        return $this->written($kind, $artifact->key, ArtifactWriteAction::Updated, $group, $this->fallbackNotes($state, __('i18n.backend.handlers.config_bundle.object_model_artifact_writer.field_group')));
    }

    private function removeFieldGroup(string $key): ArtifactWriteResult
    {
        $kind = ArtifactKind::FieldGroups;
        $id = $this->targetKeys->idFor($kind, $key);
        $group = $id === null ? null : FieldGroup::query()->whereKey($id)->first();

        if (!$group instanceof FieldGroup) {
            return $this->missingTarget($kind, $key);
        }

        $reason = $this->manageabilityReason($group->objectType);

        if ($reason !== null) {
            return $this->skipped($kind, $key, $reason);
        }

        $this->deleteFieldGroup->execute($group);

        return $this->written($kind, $key, ArtifactWriteAction::Removed, $group);
    }

    private function applyFieldDefinition(BundleArtifact $artifact, DiffState $state): ArtifactWriteResult
    {
        $kind = ArtifactKind::FieldDefinitions;
        $payload = $artifact->payload;
        $objectType = $this->objectTypeOf($kind, $artifact->key);

        if (!$objectType instanceof ObjectType) {
            return $this->unresolvable($kind, $artifact->key, 'object_type_id', $artifact->key);
        }

        $reason = $this->manageabilityReason($objectType);

        if ($reason !== null) {
            return $this->skipped($kind, $artifact->key, $reason);
        }

        $groupKey = $this->textOf($payload, 'field_group_id');
        $groupId = $groupKey === null ? null : $this->targetKeys->idFor(ArtifactKind::FieldGroups, $groupKey);

        if ($groupKey !== null && $groupId === null) {
            return $this->unresolvable($kind, $artifact->key, 'field_group_id', $groupKey);
        }

        $config = $payload['config'] ?? null;
        $relationshipKey = is_array($config) ? $this->textOf($config, 'relationship_type_id') : null;

        if ($relationshipKey !== null) {
            $relationshipId = $this->targetKeys->idFor(ArtifactKind::RelationshipTypes, $relationshipKey);

            if ($relationshipId === null) {
                return $this->unresolvable($kind, $artifact->key, 'config.relationship_type_id', $relationshipKey);
            }

            $config['relationship_type_id'] = $relationshipId;
        }

        $rollupReference = $this->textOf($payload, 'field_type') === FieldType::Rollup->value && is_array($config)
            ? $this->awaitedRollupReference($kind, $artifact->key, $objectType, $config)
            : null;

        if ($rollupReference !== null) {
            return $rollupReference;
        }

        $notes = [];

        if (($payload['merge_strategy'] ?? null) !== null) {
            $notes[] = __('i18n.backend.handlers.config_bundle.object_model_artifact_writer.field_management_does_not_accept_merge_strategy_its_existing');
        }

        $input = [
            'field_group_id' => $groupId,
            'is_required' => $payload['is_required'] ?? false,
            'is_unique' => $payload['is_unique'] ?? false,
            'is_searchable' => $payload['is_searchable'] ?? false,
            'is_translatable' => $payload['is_translatable'] ?? false,
            'is_encrypted' => $payload['is_encrypted'] ?? false,
            'is_sortable' => $payload['is_sortable'] ?? false,
            'is_filterable' => $payload['is_filterable'] ?? false,
            'is_default_column' => $payload['is_default_column'] ?? false,
            ...array_replace(config('modules.fields.defaults', []), Arr::only($payload, config('modules.fields.attributes', []))),
            'list_position' => $payload['list_position'] ?? null,
            'validation_rules' => $payload['validation_rules'] ?? null,
            'default_value' => $payload['default_value'] ?? null,
            'i18n_labels' => $payload['i18n_labels'] ?? null,
            'i18n_descriptions' => $payload['i18n_descriptions'] ?? null,
        ];

        $existingId = $this->targetKeys->idFor($kind, $artifact->key);

        if ($existingId === null) {
            if ($state === DiffState::Modified) {
                return $this->missingTarget($kind, $artifact->key);
            }

            $field = $this->createFieldDefinition->execute([
                'object_type_id' => $objectType->getKey(),
                'key' => $this->localNameOf($artifact->key),
                'field_type' => $payload['field_type'] ?? null,
                'config' => $config,
                ...$input,
            ]);

            return $this->written($kind, $artifact->key, ArtifactWriteAction::Created, $field, $notes);
        }

        $field = FieldDefinition::query()->whereKey($existingId)->first();

        if (!$field instanceof FieldDefinition) {
            return $this->missingTarget($kind, $artifact->key);
        }

        if ($this->textOf($payload, 'field_type') !== $field->field_type->value) {
            $notes[] = __('i18n.backend.handlers.config_bundle.object_model_artifact_writer.the_key_and_field_type_of_an_existing_field');
        }

        if ($config !== null) {
            $input['config'] = $config;
        }

        $this->updateFieldDefinition->execute($field, $input);

        return $this->written($kind, $artifact->key, ArtifactWriteAction::Updated, $field, [
            ...$notes,
            ...$this->fallbackNotes($state, __('i18n.backend.handlers.config_bundle.object_model_artifact_writer.field')),
        ]);
    }

    private function removeFieldDefinition(string $key): ArtifactWriteResult
    {
        $kind = ArtifactKind::FieldDefinitions;
        $id = $this->targetKeys->idFor($kind, $key);
        $field = $id === null ? null : FieldDefinition::query()->whereKey($id)->first();

        if (!$field instanceof FieldDefinition) {
            return $this->missingTarget($kind, $key);
        }

        $reason = $this->manageabilityReason($field->objectType);

        if ($reason !== null) {
            return $this->skipped($kind, $key, $reason);
        }

        $this->deleteFieldDefinition->execute($field);

        return $this->written($kind, $key, ArtifactWriteAction::Removed, $field);
    }

    private function applyRelationshipType(BundleArtifact $artifact, DiffState $state): ArtifactWriteResult
    {
        $kind = ArtifactKind::RelationshipTypes;
        $payload = $artifact->payload;
        $endpoints = [];

        foreach (['from_object_type_id', 'to_object_type_id'] as $column) {
            $endpointKey = $this->textOf($payload, $column);
            $endpointId = $endpointKey === null ? null : $this->targetKeys->idFor(ArtifactKind::ObjectTypes, $endpointKey);

            if ($endpointId === null) {
                return $this->unresolvable($kind, $artifact->key, $column, $endpointKey ?? '');
            }

            $endpoints[$column] = $endpointId;
        }

        $input = [
            'name' => $payload['name'] ?? null,
            'inverse_name' => $payload['inverse_name'] ?? null,
            'cardinality' => $payload['cardinality'] ?? null,
            'cascade_behavior' => $payload['cascade_behavior'] ?? null,
            'is_required' => $payload['is_required'] ?? false,
            ...$endpoints,
        ];

        $existingId = $this->targetKeys->idFor($kind, $artifact->key);
        $notes = [];

        if (($payload['is_hierarchy'] ?? false) === true) {
            $notes[] = __('i18n.backend.handlers.config_bundle.object_model_artifact_writer.relationship_management_does_not_accept_is_hierarchy_hierarchies_are');
        }

        if ($existingId === null) {
            if ($state === DiffState::Modified) {
                return $this->missingTarget($kind, $artifact->key);
            }

            $relationshipType = $this->createRelationshipType->execute($input);

            return $this->written($kind, $artifact->key, ArtifactWriteAction::Created, $relationshipType, [
                ...$notes,
                ...$this->relationshipKeyNotes($payload, $relationshipType),
            ]);
        }

        $relationshipType = RelationshipType::query()->whereKey($existingId)->first();

        if (!$relationshipType instanceof RelationshipType) {
            return $this->missingTarget($kind, $artifact->key);
        }

        $this->updateRelationshipType->execute($relationshipType, $input);

        return $this->written($kind, $artifact->key, ArtifactWriteAction::Updated, $relationshipType, [
            ...$notes,
            ...$this->relationshipKeyNotes($payload, $relationshipType),
            ...$this->fallbackNotes($state, __('i18n.backend.handlers.config_bundle.object_model_artifact_writer.relationship_type')),
        ]);
    }

    private function removeRelationshipType(string $key): ArtifactWriteResult
    {
        $kind = ArtifactKind::RelationshipTypes;
        $id = $this->targetKeys->idFor($kind, $key);
        $relationshipType = $id === null ? null : RelationshipType::query()->whereKey($id)->first();

        if (!$relationshipType instanceof RelationshipType) {
            return $this->missingTarget($kind, $key);
        }

        $this->deleteRelationshipType->execute($relationshipType);

        return $this->written($kind, $key, ArtifactWriteAction::Removed, $relationshipType);
    }

    private function applyMergeRule(BundleArtifact $artifact, DiffState $state): ArtifactWriteResult
    {
        $kind = ArtifactKind::MergeRules;
        $payload = $artifact->payload;
        $objectType = $this->objectTypeOf($kind, $artifact->key);

        if (!$objectType instanceof ObjectType) {
            return $this->unresolvable($kind, $artifact->key, 'object_type_id', $artifact->key);
        }

        $input = [
            'name' => $this->localNameOf($artifact->key),
            'mode' => $payload['mode'] ?? null,
            'position' => $payload['position'] ?? 0,
            'is_active' => $payload['is_active'] ?? false,
            'deny_reason' => $payload['deny_reason'] ?? null,
            'condition' => $payload['condition'] ?? null,
            'field_strategies' => $payload['field_strategies'] ?? [],
            'transfer_policy' => $payload['transfer_policy'] ?? [],
            'options' => $payload['options'] ?? [],
        ];

        $existingId = $this->targetKeys->idFor($kind, $artifact->key);

        if ($existingId === null) {
            if ($state === DiffState::Modified) {
                return $this->missingTarget($kind, $artifact->key);
            }

            $rule = $this->createMergeRule->execute($objectType, $input);

            return $this->written($kind, $artifact->key, ArtifactWriteAction::Created, $rule);
        }

        $rule = MergeRule::query()->whereKey($existingId)->first();

        if (!$rule instanceof MergeRule) {
            return $this->missingTarget($kind, $artifact->key);
        }

        $this->updateMergeRule->execute($rule, $input);

        return $this->written($kind, $artifact->key, ArtifactWriteAction::Updated, $rule, $this->fallbackNotes($state, __('i18n.backend.handlers.config_bundle.object_model_artifact_writer.merge_rule')));
    }

    private function removeMergeRule(string $key): ArtifactWriteResult
    {
        $kind = ArtifactKind::MergeRules;
        $id = $this->targetKeys->idFor($kind, $key);
        $rule = $id === null ? null : MergeRule::query()->whereKey($id)->first();

        if (!$rule instanceof MergeRule) {
            return $this->missingTarget($kind, $key);
        }

        $this->deleteMergeRule->execute($rule);

        return $this->written($kind, $key, ArtifactWriteAction::Removed, $rule);
    }

    private function applyReminderType(BundleArtifact $artifact, DiffState $state): ArtifactWriteResult
    {
        $kind = ArtifactKind::ReminderTypes;
        $existingId = $this->targetKeys->idFor($kind, $artifact->key);

        if ($existingId === null) {
            if ($state === DiffState::Modified) {
                return $this->missingTarget($kind, $artifact->key);
            }

            $reminderType = $this->createReminderType->execute(['name' => $artifact->key]);

            return $this->written($kind, $artifact->key, ArtifactWriteAction::Created, $reminderType);
        }

        $reminderType = ReminderType::query()->whereKey($existingId)->first();

        if (!$reminderType instanceof ReminderType) {
            return $this->missingTarget($kind, $artifact->key);
        }

        $this->updateReminderType->execute($reminderType, ['name' => $artifact->key]);

        return $this->written($kind, $artifact->key, ArtifactWriteAction::Updated, $reminderType, $this->fallbackNotes($state, __('i18n.backend.handlers.config_bundle.object_model_artifact_writer.reminder_type')));
    }

    private function removeReminderType(string $key): ArtifactWriteResult
    {
        $kind = ArtifactKind::ReminderTypes;
        $id = $this->targetKeys->idFor($kind, $key);
        $reminderType = $id === null ? null : ReminderType::query()->whereKey($id)->first();

        if (!$reminderType instanceof ReminderType) {
            return $this->missingTarget($kind, $key);
        }

        $this->deleteReminderType->execute($reminderType);

        return $this->written($kind, $key, ArtifactWriteAction::Removed, $reminderType);
    }

    /**
     * @return array{}|array{hierarchy_enabled: bool}
     */
    private function hierarchyInput(?string $hierarchy, ?RelationshipType $hierarchyCarrier): array
    {
        return match (true) {
            $hierarchy === null => ['hierarchy_enabled' => false],
            $hierarchyCarrier instanceof RelationshipType => ['hierarchy_enabled' => true],
            default => [],
        };
    }

    private function awaitedHierarchyReference(?string $hierarchy, ?RelationshipType $hierarchyCarrier): ?string
    {
        if ($hierarchy === null || $hierarchyCarrier instanceof RelationshipType) {
            return null;
        }

        return __('i18n.backend.handlers.config_bundle.object_model_artifact_writer.the_reference_hierarchy_relationship_type_id_to_cannot_be', ['value1' => $hierarchy]);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function awaitedRollupReference(ArtifactKind $kind, string $key, ObjectType $objectType, array $config): ?ArtifactWriteResult
    {
        $relationshipId = $this->textOf($config, 'relationship_type_id');
        $relationship = $relationshipId === null ? null : RelationshipType::query()->whereKey($relationshipId)->first();
        $evaluated = $relationship instanceof RelationshipType
            ? ObjectType::query()->whereKey($relationship->to_object_type_id)->first()
            : $objectType;

        if (!$evaluated instanceof ObjectType) {
            return null;
        }

        if ($this->textOf($config, 'scope') === RollupScope::Subtree->value && !$evaluated->hasHierarchy()) {
            return $this->unresolvable($kind, $key, 'config.scope', "{$evaluated->key}:hierarchy_relationship_type_id");
        }

        $sourceFieldKey = $this->textOf($config, 'source_field_key');
        $filter = $config['filter'] ?? null;
        $expected = array_values(array_unique(array_filter([
            $sourceFieldKey,
            ...(is_array($filter) ? $this->filterFieldKeys->collect($filter) : []),
        ])));

        $present = FieldDefinition::query()
            ->where('object_type_id', $evaluated->getKey())
            ->whereIn('key', $expected)
            ->pluck('key')
            ->all();

        $missing = array_values(array_diff($expected, $present));

        if ($missing === []) {
            return null;
        }

        $column = in_array($sourceFieldKey, $missing, true) ? 'config.source_field_key' : 'config.filter';

        return $this->unresolvable($kind, $key, $column, "{$evaluated->key}:".implode(', ', $missing));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function relationshipKeyNotes(array $payload, RelationshipType $relationshipType): array
    {
        $expected = [
            'key' => $relationshipType->key,
            'inverse_key' => $relationshipType->inverse_key,
        ];

        $diverging = [];

        foreach ($expected as $column => $written) {
            if ($this->textOf($payload, $column) !== $written) {
                $diverging[] = $column;
            }
        }

        return $diverging === [] ? [] : [
            __('i18n.backend.handlers.config_bundle.object_model_artifact_writer.the_values').implode(' und ', $diverging).__('i18n.backend.handlers.config_bundle.object_model_artifact_writer.are_derived_from_labels_and_differ_from_the_payload'),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{}|array{business_key_prefix: string|null, record_number_format: string|null}
     */
    private function businessKeyInput(array $payload, ObjectType $objectType): array
    {
        if (!array_key_exists('business_key_prefix', $payload)) {
            return [];
        }

        $prefix = $this->textOf($payload, 'business_key_prefix');
        $format = $this->textOf($payload, 'record_number_format');

        if ($prefix === $objectType->business_key_prefix && $format === $objectType->record_number_format) {
            return [];
        }

        return [
            'business_key_prefix' => $prefix,
            'record_number_format' => $format,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function storageStrategyOf(array $payload): ?string
    {
        $value = $payload['storage_strategy'] ?? null;

        if ($value instanceof StorageStrategy) {
            return $value->value;
        }

        return is_string($value) ? $value : null;
    }
}
