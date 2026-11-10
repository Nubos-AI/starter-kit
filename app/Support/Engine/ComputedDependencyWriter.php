<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\Contracts\Formulas\FormulaNode;
use App\Models\FieldDefinition;
use App\Models\FieldDependency;
use App\Support\Formulas\FormulaReferenceCollector;

class ComputedDependencyWriter
{
    public function __construct(private readonly FormulaReferenceCollector $collector) {}

    public function rewriteEdges(FieldDefinition $field, FormulaNode $formula): void
    {
        FieldDependency::query()
            ->where('rollup_field_id', $field->getKey())
            ->delete();

        $referencedKeys = $this->collector->collect($formula);

        if ($referencedKeys === []) {
            return;
        }

        $referencedIds = FieldDefinition::query()
            ->where('object_type_id', $field->object_type_id)
            ->whereIn('key', $referencedKeys)
            ->pluck('id', 'key');

        foreach ($referencedIds as $dependsOnFieldId) {
            FieldDependency::query()->updateOrCreate(
                [
                    'rollup_field_id' => $field->getKey(),
                    'depends_on_field_id' => $dependsOnFieldId,
                ],
                ['relationship_type_id' => null],
            );
        }
    }
}
