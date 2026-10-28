<?php

declare(strict_types=1);

namespace App\Support\Reports;

use App\Models\ObjectType;
use App\Models\Report;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class ReportOptions
{
    /**
     * @return list<array{value: string, label: string, description: string}>
     */
    public function forUser(User $user): array
    {
        $reports = Report::query()
            ->where('tenant_id', $user->tenant_id)
            ->with('objectType')
            ->orderBy('name')
            ->get();

        /** @var array<string, bool> $reportable */
        $reportable = [];

        $options = [];

        foreach ($reports as $report) {
            $objectType = $report->objectType;

            if (!$objectType instanceof ObjectType || $user->cannot('view', $report)) {
                continue;
            }

            $objectTypeId = (string) $objectType->getKey();

            $reportable[$objectTypeId] ??= Gate::forUser($user)->allows('create', [Report::class, $objectType]);

            if (!$reportable[$objectTypeId]) {
                continue;
            }

            $options[] = [
                'value' => (string) $report->getKey(),
                'label' => $report->name,
                'description' => $objectType->name,
            ];
        }

        return $options;
    }
}
