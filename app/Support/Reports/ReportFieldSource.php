<?php

declare(strict_types=1);

namespace App\Support\Reports;

use App\Models\FieldDefinition;
use Illuminate\Database\Eloquent\Collection;

class ReportFieldSource
{
    /**
     * @return Collection<int, FieldDefinition>
     */
    public function persistedFields(string $objectTypeId): Collection
    {
        /** @var Collection<int, FieldDefinition> $fields */
        $fields = FieldDefinition::query()
            ->where('object_type_id', $objectTypeId)
            ->get();

        return $fields;
    }
}
