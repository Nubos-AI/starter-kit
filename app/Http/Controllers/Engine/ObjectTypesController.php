<?php

declare(strict_types=1);

namespace App\Http\Controllers\Engine;

use App\Actions\Engine\BulkDeleteObjectTypesAction;
use App\Actions\Engine\CreateObjectTypeAction;
use App\Actions\Engine\DeleteObjectTypeAction;
use App\Actions\Engine\UpdateObjectTypeAction;
use App\Enums\CustomFields\FieldType;
use App\Enums\CustomFields\ReservedFieldKey;
use App\Enums\Ui\NavIcon;
use App\Exceptions\Engine\ReservedSlugException;
use App\Http\Controllers\Abstracts\Controller;
use App\Http\Resources\Aging\AgingRuleResource;
use App\Http\Resources\Engine\MergeRuleResource;
use App\Models\AgingRule;
use App\Models\FieldDefinition;
use App\Models\MergeRule;
use App\Models\ObjectType;
use App\Models\RelationshipType;
use App\Models\User;
use App\Support\Aging\AgingRuleValidator;
use App\Support\Authorization\FieldPermissionMatrix;
use App\Support\Engine\ConditionFieldPresenter;
use App\Support\Engine\FieldGroupPresenter;
use App\Support\I18n\TranslatableValueResolver;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ObjectTypesController extends Controller
{
    public function __construct(
        private readonly CreateObjectTypeAction $createObjectType,
        private readonly UpdateObjectTypeAction $updateObjectType,
        private readonly DeleteObjectTypeAction $deleteObjectType,
        private readonly BulkDeleteObjectTypesAction $bulkDeleteObjectTypes,
        private readonly ConditionFieldPresenter $conditionFields,
        private readonly FieldGroupPresenter $fieldGroupPresenter,
    ) {}

    public function index(Request $request): Response
    {
        $user = $this->actingUser($request);

        $objectTypes = ObjectType::query()
            ->withCount('fieldDefinitions')
            ->orderBy('name')
            ->get()
            ->map(fn (ObjectType $type): array => $this->indexPayload($type, $user))
            ->all();

        return Inertia::render('objectTypes/Index', [
            'objectTypes' => $objectTypes,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('objectTypes/edit/Details', [
            'mode' => 'create',
            'objectType' => null,
            'navIcons' => NavIcon::options(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        try {
            $objectType = $this->createObjectType->execute($request->all());
        } catch (ReservedSlugException $exception) {
            throw ValidationException::withMessages(['name' => $exception->getMessage()]);
        }

        return to_route('engine.object-types.edit', ['objectType' => $objectType->slug]);
    }

    public function edit(ObjectType $objectType): Response
    {
        return Inertia::render('objectTypes/edit/Details', [
            'mode' => 'edit',
            'objectType' => $this->formPayload($objectType),
            'navIcons' => NavIcon::options(),
        ]);
    }

    public function fields(ObjectType $objectType): Response
    {
        $objectType->load('fieldDefinitions');

        return Inertia::render('objectTypes/edit/Fields', [
            'objectType' => $this->formPayload($objectType),
            'fields' => $objectType->fieldDefinitions
                ->sortBy('list_position')
                ->sortBy('id')
                ->values()
                ->map(fn (FieldDefinition $field): array => $this->fieldPayload($field))
                ->all(),
            'fieldGroups' => $this->fieldGroupPresenter->payload($objectType),
            'fieldTypes' => $this->fieldTypeOptions(),
            'objectTypeOptions' => $this->objectTypeOptions(),
            'rollupTargets' => $this->rollupTargetOptions($objectType),
        ]);
    }

    public function agingRules(Request $request, ObjectType $objectType): Response
    {
        $user = $this->actingUser($request);

        return Inertia::render('objectTypes/edit/AgingRules', [
            'objectType' => $this->formPayload($objectType),
            'agingRules' => AgingRuleResource::collection(
                AgingRule::query()
                    ->where('object_type_id', $objectType->getKey())
                    ->orderBy('created_at')
                    ->get(),
            )->resolve($request),
            'agingClockFieldOptions' => $this->agingClockFieldOptions($objectType),
            'agingConditionFields' => $this->conditionFields->forObjectType($user, $objectType),
            'agingActiveRuleLimit' => AgingRuleValidator::$activeRuleLimit,
        ]);
    }

    public function mergeRules(Request $request, ObjectType $objectType): Response
    {
        return Inertia::render('objectTypes/edit/MergeRules', [
            'objectType' => $this->formPayload($objectType),
            'mergeRules' => MergeRuleResource::collection(
                MergeRule::query()
                    ->where('object_type_id', $objectType->getKey())
                    ->orderBy('position')
                    ->orderBy('created_at')
                    ->get(),
            )->resolve($request),
        ]);
    }

    public function permissions(ObjectType $objectType): Response
    {
        return Inertia::render('objectTypes/edit/Permissions', [
            'objectType' => $this->formPayload($objectType),
            'matrix' => FieldPermissionMatrix::forObjectType($objectType),
        ]);
    }

    public function update(Request $request, ObjectType $objectType): RedirectResponse
    {
        $this->updateObjectType->execute($objectType, $request->all());

        return to_route('engine.object-types.edit', ['objectType' => $objectType->slug]);
    }

    public function destroy(ObjectType $objectType): RedirectResponse
    {
        $this->deleteObjectType->execute($objectType);

        return to_route('engine.object-types.index');
    }

    public function bulkDestroy(Request $request): RedirectResponse
    {
        $this->bulkDeleteObjectTypes->execute($this->actingUser($request), $request->all());

        return to_route('engine.object-types.index');
    }

    /**
     * @return array<string, mixed>
     */
    private function indexPayload(ObjectType $type, User $user): array
    {
        return [
            'id' => $type->id,
            'slug' => $type->slug,
            'name' => $type->name,
            'is_system' => $type->is_system,
            'storage_strategy' => $type->storage_strategy->value,
            'field_count' => $type->field_definitions_count,
            'can_update' => $user->can('update', $type),
            'can_delete' => $user->can('delete', $type),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formPayload(ObjectType $type): array
    {
        return [
            'id' => $type->id,
            'key' => $type->key,
            'slug' => $type->slug,
            'name' => $type->name,
            'business_key_prefix' => $type->business_key_prefix,
            'record_number_format' => $type->record_number_format,
            'requires_deletion_reason' => $type->requires_deletion_reason,
            'is_navigable' => $type->is_navigable,
            'nav_icon' => $type->nav_icon->value,
            'nav_position' => $type->nav_position,
            'retention_days' => $type->retention_days,
            'business_key_locked' => $type->hasRecords(),
            'is_system' => $type->is_system,
            'storage_strategy' => $type->storage_strategy->value,
            'hierarchy_relationship_type_id' => $type->hierarchy_relationship_type_id,
            ...$type->only(config('modules.object_types.attributes', [])),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rollupTargetOptions(ObjectType $objectType): array
    {
        $relationshipTypes = RelationshipType::query()
            ->where('from_object_type_id', $objectType->getKey())
            ->orderBy('name')
            ->get();

        /** @var list<string> $targetObjectTypeIds */
        $targetObjectTypeIds = $relationshipTypes
            ->pluck('to_object_type_id')
            ->unique()
            ->values()
            ->all();

        $targetObjectTypes = ObjectType::query()
            ->whereIn('id', $targetObjectTypeIds)
            ->get()
            ->keyBy('id');

        $filterableFields = FieldDefinition::query()
            ->whereIn('object_type_id', $targetObjectTypeIds)
            ->where('is_filterable', true)
            ->where('is_encrypted', false)
            ->orderBy('list_position')
            ->get()
            ->groupBy('object_type_id');

        return $relationshipTypes
            ->map(fn (RelationshipType $type): array => [
                'value' => $type->id,
                'label' => $type->name,
                'target_object_type_id' => $type->to_object_type_id,
                'has_hierarchy' => $targetObjectTypes->get($type->to_object_type_id)?->hasHierarchy() ?? false,
                'fields' => $filterableFields
                    ->get($type->to_object_type_id, new Collection)
                    ->map(fn (FieldDefinition $field): array => $this->filterFieldPayload($field))
                    ->values()
                    ->all(),
            ])
            ->all();
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function agingClockFieldOptions(ObjectType $objectType): array
    {
        $resolver = new TranslatableValueResolver;

        return FieldDefinition::query()
            ->where('object_type_id', $objectType->getKey())
            ->whereIn('field_type', [FieldType::Date, FieldType::DateTime])
            ->where('is_encrypted', false)
            ->where('is_translatable', false)
            ->orderBy('list_position')
            ->get()
            ->map(function (FieldDefinition $field) use ($resolver): array {
                $label = $resolver->resolve($field->i18n_labels);

                return [
                    'value' => $field->key,
                    'label' => is_string($label) && $label !== '' ? $label : $field->key,
                ];
            })
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function filterFieldPayload(FieldDefinition $field): array
    {
        $label = (new TranslatableValueResolver)->resolve($field->i18n_labels);

        return [
            'key' => $field->key,
            'field_type' => $field->field_type->value,
            'label' => is_string($label) && $label !== '' ? $label : $field->key,
            'is_required' => $field->is_required,
            'is_filterable' => $field->is_filterable,
            'config' => $field->config,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fieldPayload(FieldDefinition $field): array
    {
        $resolver = new TranslatableValueResolver;
        $label = $resolver->resolve($field->i18n_labels);
        $description = $resolver->resolve($field->i18n_descriptions);

        return [
            'id' => $field->id,
            'field_group_id' => $field->field_group_id,
            'key' => $field->key,
            'field_type' => $field->field_type->value,
            'is_required' => $field->is_required,
            'is_unique' => $field->is_unique,
            'is_searchable' => $field->is_searchable,
            'is_translatable' => $field->is_translatable,
            'is_encrypted' => $field->is_encrypted,
            'is_sortable' => $field->is_sortable,
            'is_filterable' => $field->is_filterable,
            'is_default_column' => $field->is_default_column,
            ...$field->only(config('modules.fields.attributes', [])),
            'is_reserved' => ReservedFieldKey::isReserved($field->key),
            'is_type_changeable' => $field->field_type->isTypeChangeable(),
            'list_position' => $field->list_position,
            'label' => is_string($label) ? $label : '',
            'description' => is_string($description) && $description !== '' ? $description : null,
            'config' => $field->config,
            'validation_rules' => $field->validation_rules,
            'default_value' => $field->default_value,
        ];
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function objectTypeOptions(): array
    {
        return ObjectType::query()
            ->where('is_system', false)
            ->orderBy('name')
            ->get()
            ->map(fn (ObjectType $type): array => [
                'value' => $type->slug,
                'label' => $type->name,
            ])
            ->all();
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function fieldTypeOptions(): array
    {
        return array_map(
            fn (FieldType $type): array => [
                'value' => $type->value,
                'label' => $type->label(),
                'description' => $type->description(),
                'category' => $type->category()->value,
                'categoryLabel' => $type->category()->label(),
                'categoryPosition' => $type->category()->position(),
            ],
            FieldType::cases(),
        );
    }
}
