<?php

declare(strict_types=1);

namespace Tests\Unit\Goals\Doubles;

use App\Models\FieldDefinition;
use App\Models\Report;
use App\Support\Goals\GoalDefinitionSource;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

class StaticGoalDefinitionSource extends GoalDefinitionSource
{
    /**
     * @var list<array{tenantId: string|null, reportId: string}>
     */
    public array $reportLookups = [];

    /**
     * @var array<string, Report>
     */
    private array $reports = [];

    /**
     * @var array<string, FieldDefinition>
     */
    private array $fields = [];

    /**
     * @var list<string>
     */
    private array $userIds = [];

    /**
     * @var list<string>
     */
    private array $teamIds = [];

    public function withReport(Report $report): self
    {
        $this->reports[(string) $report->getKey()] = $report;

        return $this;
    }

    public function withField(string $objectTypeId, FieldDefinition $field): self
    {
        $this->fields[$objectTypeId.'|'.$field->key] = $field;

        return $this;
    }

    public function withUser(string $userId): self
    {
        $this->userIds[] = $userId;

        return $this;
    }

    public function withTeam(string $teamId): self
    {
        $this->teamIds[] = $teamId;

        return $this;
    }

    public function report(?string $tenantId, string $reportId): ?Report
    {
        $this->reportLookups[] = ['tenantId' => $tenantId, 'reportId' => $reportId];

        $report = $this->reports[$reportId] ?? null;

        return $report instanceof Report && $report->tenant_id === $tenantId ? $report : null;
    }

    /**
     * @return EloquentCollection<int, Report>
     */
    public function tenantReports(?string $tenantId): EloquentCollection
    {
        /** @var EloquentCollection<int, Report> $reports */
        $reports = new EloquentCollection(array_values(array_filter(
            $this->reports,
            static fn (Report $report): bool => $report->tenant_id === $tenantId,
        )));

        return $reports;
    }

    public function field(string $objectTypeId, string $key): ?FieldDefinition
    {
        return $this->fields[$objectTypeId.'|'.$key] ?? null;
    }

    /**
     * @param  list<string>  $objectTypeIds
     * @return Collection<string, EloquentCollection<int, FieldDefinition>>
     */
    public function filterableFieldsByObjectType(array $objectTypeIds): Collection
    {
        $matching = [];

        foreach ($this->fields as $composite => $field) {
            $objectTypeId = explode('|', $composite)[0];

            if (in_array($objectTypeId, $objectTypeIds, true) && $field->is_filterable) {
                $matching[] = $field;
            }
        }

        usort($matching, static fn (FieldDefinition $a, FieldDefinition $b): int => strcmp($a->key, $b->key));

        /** @var Collection<string, EloquentCollection<int, FieldDefinition>> $grouped */
        $grouped = (new EloquentCollection($matching))->groupBy('object_type_id');

        return $grouped;
    }

    public function hasUser(?string $tenantId, string $userId): bool
    {
        return in_array($userId, $this->userIds, true);
    }

    public function hasTeam(?string $tenantId, string $teamId): bool
    {
        return in_array($teamId, $this->teamIds, true);
    }
}
