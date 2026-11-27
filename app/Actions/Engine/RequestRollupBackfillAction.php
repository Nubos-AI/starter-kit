<?php

declare(strict_types=1);

namespace App\Actions\Engine;

use App\Enums\CustomFields\FieldType;
use App\Models\FieldDefinition;
use App\Models\RecordLink;
use App\Support\Engine\RollupDebounceStarter;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

class RequestRollupBackfillAction
{
    public function __construct(
        private readonly RollupDebounceStarter $starter,
        private readonly Repository $config,
    ) {}

    /**
     * @throws Throwable
     */
    public function execute(FieldDefinition $field): int
    {
        $relationshipTypeId = $this->relationshipTypeOf($field);

        if ($relationshipTypeId === null) {
            return 0;
        }

        $tenantId = $field->tenant_id;
        $objectTypeId = $field->object_type_id;
        $requested = 0;

        RecordLink::query()
            ->withoutGlobalScopes()
            ->select(['tenant_id', 'from_record_id'])
            ->distinct()
            ->where('record_links.tenant_id', $tenantId)
            ->where('record_links.relationship_type_id', $relationshipTypeId)
            ->orderBy('from_record_id')
            ->chunk(max(1, (int) $this->config->get('engine.field_maintenance.backfill_batch_size')), function (Collection $links) use ($tenantId, $objectTypeId, &$requested): void {
                $this->starter->collect(function () use ($links, $tenantId, $objectTypeId, &$requested): void {
                    foreach ($links as $link) {
                        $this->starter->startOwn($tenantId, $objectTypeId, (string) $link->getAttribute('from_record_id'));
                        $requested++;
                    }
                });
            });

        return $requested;
    }

    private function relationshipTypeOf(FieldDefinition $field): ?string
    {
        if ($field->field_type !== FieldType::Rollup) {
            return null;
        }

        $config = is_array($field->config) ? $field->config : [];
        $relationshipTypeId = $config['relationship_type_id'] ?? null;

        return is_string($relationshipTypeId) && $relationshipTypeId !== '' ? $relationshipTypeId : null;
    }
}
