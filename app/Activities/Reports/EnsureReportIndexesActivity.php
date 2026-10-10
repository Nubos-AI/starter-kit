<?php

declare(strict_types=1);

namespace App\Activities\Reports;

use App\Contracts\Reports\EnsureReportIndexesActivityInterface;
use App\Models\FieldDefinition;
use App\Support\Engine\IndexRegistry;
use App\Support\Reports\ReportIndexPlanner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EnsureReportIndexesActivity implements EnsureReportIndexesActivityInterface
{
    public function __construct(
        private readonly ReportIndexPlanner $planner,
        private readonly IndexRegistry $indexRegistry,
    ) {}

    public function ensureReportIndex(string $objectTypeId, string $fieldKey): bool
    {
        $context = [
            'object_type_id' => $objectTypeId,
            'field_key' => $fieldKey,
        ];

        $field = FieldDefinition::withoutTenantScope()
            ->where('object_type_id', $objectTypeId)
            ->where('key', $fieldKey)
            ->first();

        if (!$field instanceof FieldDefinition) {
            Log::warning('Report expression index skipped: the field definition no longer exists.', $context);

            return false;
        }

        if (!$this->planner->isIndexable($field)) {
            Log::warning('Report expression index skipped: the field is no longer indexable.', $context);

            return false;
        }

        $indexName = $this->indexRegistry->indexNameFor($field);

        $repaired = $this->isIndexInvalid($indexName);

        if ($repaired) {
            $this->indexRegistry->dropHotFieldIndex($field);
        }

        $this->indexRegistry->ensureHotFieldIndex($field);

        return true;
    }

    private function isIndexInvalid(string $indexName): bool
    {
        return DB::selectOne(
            'SELECT 1 AS invalid
             FROM pg_index i
             JOIN pg_class c ON c.oid = i.indexrelid
             WHERE c.relname = ? AND i.indisvalid = false',
            [$indexName],
        ) !== null;
    }
}
