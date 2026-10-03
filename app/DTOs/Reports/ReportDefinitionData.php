<?php

declare(strict_types=1);

namespace App\DTOs\Reports;

use App\Enums\Reports\AggregationType;
use App\Enums\Reports\GroupingBucket;
use App\Models\FieldDefinition;

readonly class ReportDefinitionData
{
    /**
     * @param  array<string, mixed>  $filterTree
     * @param  array<string, FieldDefinition>  $fields
     * @param  list<string>  $systemFieldKeys
     */
    public function __construct(
        public string $objectTypeId,
        public AggregationType $aggregation,
        public ?FieldDefinition $aggregationField,
        public ?FieldDefinition $groupByField,
        public ?GroupingBucket $groupByBucket,
        public ?FieldDefinition $seriesField,
        public array $filterTree,
        public array $fields,
        public array $systemFieldKeys,
        public ?ReportLinkedFieldBinding $groupByLink = null,
        public ?ReportLinkedFieldBinding $aggregationLink = null,
    ) {}

    public function isSystemField(string $key): bool
    {
        return in_array($key, $this->systemFieldKeys, true);
    }
}
