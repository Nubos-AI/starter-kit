<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\Enums\CustomFields\FieldType;
use App\Models\FieldDefinition;
use App\Models\RecordLink;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class RollupBackfillStarter
{
    private int $batchSize = 500;

    public function __construct(private readonly RollupDebounceStarter $starter) {}

    public function backfill(FieldDefinition $field): void
    {
        if ($field->field_type !== FieldType::Rollup) {
            return;
        }

        $config = is_array($field->config) ? $field->config : [];
        $relationshipTypeId = $config['relationship_type_id'] ?? null;

        if (!is_string($relationshipTypeId) || $relationshipTypeId === '') {
            return;
        }

        $objectTypeId = $field->object_type_id;

        DB::afterCommit(function () use ($relationshipTypeId, $objectTypeId): void {
            RecordLink::query()
                ->select(['tenant_id', 'from_record_id'])
                ->distinct()
                ->where('relationship_type_id', $relationshipTypeId)
                ->orderBy('from_record_id')
                ->chunk($this->batchSize, function (Collection $links) use ($objectTypeId): void {
                    foreach ($links as $link) {
                        $this->starter->startOwn(
                            (string) $link->getAttribute('tenant_id'),
                            $objectTypeId,
                            (string) $link->getAttribute('from_record_id'),
                        );
                    }
                });
        });
    }
}
