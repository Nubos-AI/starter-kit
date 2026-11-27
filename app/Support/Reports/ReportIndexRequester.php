<?php

declare(strict_types=1);

namespace App\Support\Reports;

use App\Contracts\Outbox\OutboxWriter;
use App\DTOs\Outbox\OutboxSubject;
use App\DTOs\Reports\ReportDefinitionData;
use App\Enums\Outbox\OutboxTopic;
use App\Models\Report;
use App\Support\Engine\IndexRegistry;
use Illuminate\Contracts\Config\Repository;

class ReportIndexRequester
{
    public function __construct(
        private readonly OutboxWriter $outbox,
        private readonly ReportIndexPlanner $planner,
        private readonly IndexRegistry $indexRegistry,
        private readonly Repository $config,
    ) {}

    public function request(ReportDefinitionData $definition, Report $report): void
    {
        if (!$this->config->get('reports.index_maintenance.enabled')) {
            return;
        }

        $indexes = [];

        foreach ($this->planner->plan($definition) as $field) {
            $indexes[] = ['field_key' => $field->key, 'index_name' => $this->indexRegistry->indexNameFor($field)];
        }

        if ($indexes === []) {
            return;
        }

        $this->outbox->record(
            OutboxTopic::ReportIndexesRequested->value,
            $report->tenant_id,
            OutboxSubject::of($report),
            ['object_type_id' => $definition->objectTypeId, 'indexes' => $indexes],
        );
    }
}
