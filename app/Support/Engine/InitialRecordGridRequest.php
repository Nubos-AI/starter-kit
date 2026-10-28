<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\Models\ObjectType;
use Illuminate\Http\Request;

class InitialRecordGridRequest
{
    /**
     * @param  array<string, mixed>  $preference
     * @return array<string, mixed>|null
     */
    public function resolve(Request $request, ObjectType $objectType, array $preference, mixed $pageSize, ?string $defaultSegmentId = null): ?array
    {
        if (in_array($preference['viewMode'] ?? null, ['kanban', 'forecast'], true)) {
            return null;
        }

        if ($request->hasAny(['filter', 'report', 'dashboard', 'widget', 'group', 'series'])) {
            return null;
        }

        $segment = $request->query('segment', $preference['lastSegmentId'] ?? null);

        if (($segment !== null && !is_string($segment)) || ($segment === null && ($preference['lastFilter'] ?? null) !== null)) {
            return null;
        }

        $segment ??= $defaultSegmentId;

        return [
            'startRow' => 0,
            'endRow' => min(1000, max(1, is_numeric($pageSize) ? (int) $pageSize : 100)),
            'sortModel' => $this->sortModel($preference['columnState'] ?? null),
            'filterModel' => [],
            'segment' => $segment,
            'search' => null,
            'hierarchy' => $objectType->hasHierarchy() && ($preference['hierarchyOrder'] ?? null) !== false,
            'report' => null,
            'dashboard' => null,
            'widget' => null,
            'group' => null,
            'series' => null,
        ];
    }

    /**
     * @return list<array{colId: string, sort: 'asc'|'desc'}>
     */
    private function sortModel(mixed $columnState): array
    {
        if (!is_array($columnState)) {
            return [];
        }

        $columns = collect($columnState)
            ->filter(fn (mixed $column): bool => is_array($column)
                && is_string($column['colId'] ?? null)
                && in_array($column['sort'] ?? null, ['asc', 'desc'], true))
            ->sortBy(fn (array $column): int => is_numeric($column['sortIndex'] ?? null) ? (int) $column['sortIndex'] : PHP_INT_MAX);
        $sortModel = [];

        foreach ($columns as $column) {
            $sortModel[] = ['colId' => $column['colId'], 'sort' => $column['sort'] === 'desc' ? 'desc' : 'asc'];
        }

        return $sortModel;
    }
}
