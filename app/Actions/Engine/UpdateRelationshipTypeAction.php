<?php

declare(strict_types=1);

namespace App\Actions\Engine;

use App\Models\RelationshipType;
use App\Support\Engine\RelationshipEndpointGuard;
use App\Traits\Engine\ValidatesRelationshipTypeInput;
use Illuminate\Validation\ValidationException;

class UpdateRelationshipTypeAction
{
    use ValidatesRelationshipTypeInput;

    public function __construct(private readonly RelationshipEndpointGuard $endpointGuard) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function execute(RelationshipType $relationshipType, array $input): RelationshipType
    {
        $this->guardHierarchyCarrier($relationshipType);

        $validated = $this->validatedRelationshipTypeInput($input);

        $this->endpointGuard->assertUsable($validated);

        $relationshipType->fill($this->relationshipTypeAttributes($validated));
        $relationshipType->save();

        return $relationshipType;
    }

    /**
     * @throws ValidationException
     */
    private function guardHierarchyCarrier(RelationshipType $relationshipType): void
    {
        if (!$relationshipType->is_hierarchy) {
            return;
        }

        throw ValidationException::withMessages([
            'relationship_type' => __('i18n.backend.actions.engine.update_relationship_type_action.this_relationship_type_carries_a_hierarchy_and_is_managed'),
        ]);
    }
}
