<?php

declare(strict_types=1);

namespace App\Http\Controllers\Engine;

use App\Actions\Engine\BulkDeleteRelationshipTypesAction;
use App\Actions\Engine\CreateRelationshipTypeAction;
use App\Actions\Engine\DeleteRelationshipTypeAction;
use App\Actions\Engine\UpdateRelationshipTypeAction;
use App\Enums\Engine\CascadeBehavior;
use App\Enums\Engine\RelationCardinality;
use App\Http\Controllers\Abstracts\Controller;
use App\Models\ObjectType;
use App\Models\RelationshipType;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class RelationshipTypesController extends Controller
{
    public function __construct(
        private readonly CreateRelationshipTypeAction $createRelationshipType,
        private readonly UpdateRelationshipTypeAction $updateRelationshipType,
        private readonly DeleteRelationshipTypeAction $deleteRelationshipType,
        private readonly BulkDeleteRelationshipTypesAction $bulkDeleteRelationshipTypes,
    ) {}

    public function index(Request $request): Response
    {
        $user = $this->actingUser($request);

        $names = $this->objectTypeNames();

        $relationshipTypes = RelationshipType::query()
            ->where('is_hierarchy', false)
            ->orderBy('name')
            ->get()
            ->map(fn (RelationshipType $type): array => $this->indexPayload($type, $names, $user))
            ->all();

        return Inertia::render('relationshipTypes/Index', [
            'relationshipTypes' => $relationshipTypes,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('relationshipTypes/Form', [
            'mode' => 'create',
            'relationshipType' => null,
            'objectTypeOptions' => $this->objectTypeOptions(),
            'cardinalities' => $this->enumOptions(RelationCardinality::cases()),
            'cascadeBehaviors' => $this->enumOptions(CascadeBehavior::cases()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $type = $this->createRelationshipType->execute($request->all());

        return to_route('engine.relationship-types.edit', ['relationshipType' => $type->getKey()]);
    }

    public function edit(RelationshipType $relationshipType): Response
    {
        $this->guardHierarchyCarrier($relationshipType);

        return Inertia::render('relationshipTypes/Form', [
            'mode' => 'edit',
            'relationshipType' => $this->formPayload($relationshipType),
            'objectTypeOptions' => $this->objectTypeOptions(),
            'cardinalities' => $this->enumOptions(RelationCardinality::cases()),
            'cascadeBehaviors' => $this->enumOptions(CascadeBehavior::cases()),
        ]);
    }

    public function update(Request $request, RelationshipType $relationshipType): RedirectResponse
    {
        $this->guardHierarchyCarrier($relationshipType);

        $this->updateRelationshipType->execute($relationshipType, $request->all());

        return to_route('engine.relationship-types.edit', ['relationshipType' => $relationshipType->getKey()]);
    }

    public function destroy(RelationshipType $relationshipType): RedirectResponse
    {
        $this->guardHierarchyCarrier($relationshipType);

        $this->deleteRelationshipType->execute($relationshipType);

        return to_route('engine.relationship-types.index');
    }

    public function bulkDestroy(Request $request): RedirectResponse
    {
        $this->bulkDeleteRelationshipTypes->execute($this->actingUser($request), $request->all());

        return to_route('engine.relationship-types.index');
    }

    /**
     * @param  Collection<string, string>  $names
     * @return array<string, mixed>
     */
    private function indexPayload(RelationshipType $type, $names, User $user): array
    {
        return [
            'id' => $type->id,
            'name' => $type->name,
            'inverse_name' => $type->inverse_name,
            'from_object_type' => $names[$type->from_object_type_id] ?? $type->from_object_type_id,
            'to_object_type' => $names[$type->to_object_type_id] ?? $type->to_object_type_id,
            'cardinality' => $type->cardinality->value,
            'can_update' => $user->can('update', $type),
            'can_delete' => $user->can('delete', $type),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formPayload(RelationshipType $type): array
    {
        return [
            'id' => $type->id,
            'key' => $type->key,
            'name' => $type->name,
            'inverse_name' => $type->inverse_name,
            'from_object_type_id' => $type->from_object_type_id,
            'to_object_type_id' => $type->to_object_type_id,
            'cardinality' => $type->cardinality->value,
            'cascade_behavior' => $type->cascade_behavior->value,
            'is_required' => $type->is_required,
        ];
    }

    private function guardHierarchyCarrier(RelationshipType $relationshipType): void
    {
        if ($relationshipType->is_hierarchy) {
            throw new ModelNotFoundException;
        }
    }

    /**
     * @return Collection<string, string>
     */
    private function objectTypeNames()
    {
        return ObjectType::query()->orderBy('name')->pluck('name', 'id');
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function objectTypeOptions(): array
    {
        return $this->objectTypeNames()
            ->map(fn (string $name, string $id): array => ['value' => $id, 'label' => $name])
            ->values()
            ->all();
    }

    /**
     * @param  array<int, RelationCardinality|CascadeBehavior>  $cases
     * @return array<int, array{value: string, label: string}>
     */
    private function enumOptions(array $cases): array
    {
        return array_map(
            fn (RelationCardinality|CascadeBehavior $case): array => [
                'value' => $case->value,
                'label' => $case->label(),
            ],
            $cases,
        );
    }
}
