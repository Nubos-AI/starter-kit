<?php

declare(strict_types=1);

namespace App\Support\Goals;

use App\Models\FieldDefinition;
use App\Models\Report;
use App\Models\Team;
use App\Models\User;
use App\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

class GoalDefinitionSource
{
    public function report(?string $tenantId, string $reportId): ?Report
    {
        return Report::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($reportId)
            ->first();
    }

    /**
     * @return EloquentCollection<int, Report>
     */
    public function tenantReports(?string $tenantId): EloquentCollection
    {
        /** @var EloquentCollection<int, Report> $reports */
        $reports = Report::query()
            ->with('objectType')
            ->where('tenant_id', $tenantId)
            ->orderBy('name')
            ->get();

        return $reports;
    }

    public function field(string $objectTypeId, string $key): ?FieldDefinition
    {
        return FieldDefinition::query()
            ->where('object_type_id', $objectTypeId)
            ->where('key', $key)
            ->first();
    }

    /**
     * @param  list<string>  $objectTypeIds
     * @return Collection<string, EloquentCollection<int, FieldDefinition>>
     */
    public function filterableFieldsByObjectType(array $objectTypeIds): Collection
    {
        /** @var Collection<string, EloquentCollection<int, FieldDefinition>> $grouped */
        $grouped = FieldDefinition::query()
            ->whereIn('object_type_id', $objectTypeIds)
            ->where('is_filterable', true)
            ->orderBy('key')
            ->get()
            ->groupBy('object_type_id');

        return $grouped;
    }

    public function hasUser(?string $tenantId, string $userId): bool
    {
        return User::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($userId)
            ->exists();
    }

    public function hasTeam(?string $tenantId, string $teamId): bool
    {
        return Team::query()
            ->withoutGlobalScopes([TenantScope::class])
            ->where('tenant_id', $tenantId)
            ->whereKey($teamId)
            ->exists();
    }
}
