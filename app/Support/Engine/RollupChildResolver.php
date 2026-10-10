<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\DTOs\Engine\RecordTreeNode;
use App\Enums\Engine\RollupScope;
use App\Exceptions\Engine\IncompleteRollupChildSetException;
use App\Models\CustomRecord;
use App\Models\FieldDefinition;
use App\Scopes\TeamRecordAccessScope;
use App\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;

class RollupChildResolver
{
    public function __construct(
        private readonly RecordTreeQuery $recordTreeQuery,
        private readonly RecordFilterCompiler $filterCompiler,
        private readonly FilterFieldKeyCollector $filterFieldKeyCollector,
        private readonly ObjectTypeFieldLookup $fieldLookup,
    ) {}

    /**
     * @return Builder<CustomRecord>
     *
     * @throws IncompleteRollupChildSetException
     */
    public function resolve(FieldDefinition $field, CustomRecord $record): Builder
    {
        $config = is_array($field->config) ? $field->config : [];
        $relationshipTypeId = $config['relationship_type_id'] ?? null;
        $relationshipTypeId = is_string($relationshipTypeId) ? $relationshipTypeId : '';
        $tenantId = (string) $record->getAttribute('tenant_id');

        $query = CustomRecord::query()
            ->withoutGlobalScopes([TenantScope::class, TeamRecordAccessScope::class])
            ->where('custom_records.tenant_id', $tenantId);

        if ($field->rollupScope() === RollupScope::Subtree) {
            $this->restrictToSubtree($query, $tenantId, $relationshipTypeId, $record);
        } else {
            $this->restrictToDirectChildren($query, $tenantId, $relationshipTypeId, $record);
        }

        $this->applyFilter($query, $config, $relationshipTypeId);

        return $query;
    }

    /**
     * @param  Builder<CustomRecord>  $query
     */
    private function restrictToDirectChildren(Builder $query, string $tenantId, string $relationshipTypeId, CustomRecord $record): void
    {
        $query->whereIn('custom_records.id', function (QueryBuilder $links) use ($tenantId, $relationshipTypeId, $record): void {
            $links->from('record_links')
                ->select('record_links.to_record_id')
                ->where('record_links.from_record_id', $record->getKey())
                ->where('record_links.tenant_id', $tenantId);

            if ($relationshipTypeId !== '') {
                $links->where('record_links.relationship_type_id', $relationshipTypeId);
            }
        });
    }

    /**
     * @param  Builder<CustomRecord>  $query
     *
     * @throws IncompleteRollupChildSetException
     */
    private function restrictToSubtree(Builder $query, string $tenantId, string $relationshipTypeId, CustomRecord $record): void
    {
        if ($relationshipTypeId === '') {
            throw new IncompleteRollupChildSetException;
        }

        $recordId = (string) $record->getKey();
        $descendants = $this->recordTreeQuery->descendantsOf($tenantId, $relationshipTypeId, $recordId);

        if ($descendants->isTruncated) {
            throw new IncompleteRollupChildSetException;
        }

        $recordIds = array_map(
            static fn (RecordTreeNode $node): string => $node->recordId,
            $descendants->nodes,
        );

        $query->whereIn('custom_records.id', array_values(array_diff(array_unique($recordIds), [$recordId])));
    }

    /**
     * @param  Builder<CustomRecord>  $query
     * @param  array<string, mixed>  $config
     *
     * @throws IncompleteRollupChildSetException
     */
    private function applyFilter(Builder $query, array $config, string $relationshipTypeId): void
    {
        $tree = $config['filter'] ?? null;

        if (!is_array($tree) || $tree === []) {
            return;
        }

        /** @var array<string, mixed> $tree */
        $targetFields = $this->targetFields($relationshipTypeId);

        foreach ($this->filterFieldKeyCollector->collect($tree) as $key) {
            if (!$targetFields->contains('key', $key)) {
                throw new IncompleteRollupChildSetException;
            }
        }

        $this->filterCompiler->applyValidatedTree($query, $targetFields, $tree);
    }

    /**
     * @return Collection<int, FieldDefinition>
     *
     * @throws IncompleteRollupChildSetException
     */
    private function targetFields(string $relationshipTypeId): Collection
    {
        $targetObjectTypeId = $relationshipTypeId === ''
            ? null
            : $this->fieldLookup->relationshipTarget($relationshipTypeId);

        if ($targetObjectTypeId === null) {
            throw new IncompleteRollupChildSetException;
        }

        return $this->fieldLookup->fields($targetObjectTypeId);
    }
}
