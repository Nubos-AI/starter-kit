<?php

declare(strict_types=1);

namespace App\Traits\Reports;

use App\Models\Report;
use App\Models\User;
use App\Support\Authorization\FieldVisibilityResolver;

trait RedactsForbiddenFilterConditions
{
    /**
     * @return array<string, mixed>
     */
    private function visibleFilterDefinition(Report $report, mixed $viewer): array
    {
        if (!$viewer instanceof User) {
            return [];
        }

        $forbidden = FieldVisibilityResolver::forRequest()->forbiddenReadFieldKeys($viewer, $report->object_type_id);

        return $this->withoutForbiddenConditions($report->filter_definition ?? [], $forbidden);
    }

    /**
     * @param  array<string, mixed>  $tree
     * @param  list<string>  $forbidden
     * @return array<string, mixed>
     */
    private function withoutForbiddenConditions(array $tree, array $forbidden): array
    {
        if (!is_array($tree['conditions'] ?? null)) {
            return $tree;
        }

        $conditions = [];

        foreach ($tree['conditions'] as $child) {
            if (is_array($child) && array_key_exists('conditions', $child)) {
                $conditions[] = $this->withoutForbiddenConditions($child, $forbidden);

                continue;
            }

            if (!is_array($child) || !in_array($child['field'] ?? null, $forbidden, true)) {
                $conditions[] = $child;
            }
        }

        $tree['conditions'] = $conditions;

        return $tree;
    }
}
