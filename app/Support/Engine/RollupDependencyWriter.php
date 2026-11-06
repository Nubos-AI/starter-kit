<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\DTOs\Engine\RollupConfiguration;
use App\Models\FieldDefinition;
use App\Models\FieldDependency;

class RollupDependencyWriter
{
    public function rewriteEdges(FieldDefinition $field, RollupConfiguration $configuration): void
    {
        FieldDependency::query()
            ->where('rollup_field_id', $field->getKey())
            ->delete();

        $dependsOnKeys = $configuration->dependsOnFieldKeys();

        if ($dependsOnKeys === []) {
            return;
        }

        $dependsOnIds = FieldDefinition::query()
            ->where('object_type_id', $configuration->targetObjectTypeId)
            ->whereIn('key', $dependsOnKeys)
            ->pluck('id');

        foreach ($dependsOnIds as $dependsOnFieldId) {
            FieldDependency::query()->updateOrCreate(
                [
                    'rollup_field_id' => $field->getKey(),
                    'depends_on_field_id' => $dependsOnFieldId,
                ],
                ['relationship_type_id' => $configuration->relationshipTypeId],
            );
        }
    }
}
