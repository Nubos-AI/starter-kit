<?php

declare(strict_types=1);

namespace App\Actions\Engine;

use App\Models\ObjectType;
use App\Models\RelationshipType;
use Illuminate\Validation\ValidationException;

class DeleteRelationshipTypeAction
{
    /**
     * @throws ValidationException
     */
    public function execute(RelationshipType $relationshipType): void
    {
        $this->guardReferencedCarrier($relationshipType);

        $relationshipType->delete();
    }

    /**
     * @throws ValidationException
     */
    private function guardReferencedCarrier(RelationshipType $relationshipType): void
    {
        $isReferenced = ObjectType::query()
            ->where('hierarchy_relationship_type_id', $relationshipType->getKey())
            ->exists();

        if ($isReferenced) {
            throw ValidationException::withMessages([
                'relationship_type' => __('i18n.backend.actions.engine.delete_relationship_type_action.this_relationship_type_carries_an_active_hierarchy_disable_the'),
            ]);
        }
    }
}
