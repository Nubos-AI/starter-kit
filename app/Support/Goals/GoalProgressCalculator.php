<?php

declare(strict_types=1);

namespace App\Support\Goals;

use App\Enums\Reports\ReportNotExecutableReason;
use App\Exceptions\Reports\ReportNotExecutableException;
use App\Models\Goal;
use App\Models\GoalPeriod;
use App\Models\ObjectType;
use App\Models\Report;
use App\Models\User;
use App\Scopes\TenantScope;
use App\Support\Maintenance\MaintenanceLockRegistry;
use App\Support\Reports\ReportDefinitionValidator;
use App\Support\Reports\ReportInputRules;
use App\Support\Reports\ReportRunner;
use Carbon\CarbonImmutable;

class GoalProgressCalculator
{
    public function __construct(
        private readonly GoalPeriodCalculator $periodCalculator,
        private readonly GoalScopeCompiler $scopeCompiler,
        private readonly ReportDefinitionValidator $definitionValidator,
        private readonly ReportRunner $runner,
        private readonly MaintenanceLockRegistry $maintenanceLocks,
    ) {}

    /**
     * @return list<string>
     */
    public function dueGoalIds(CarbonImmutable $now): array
    {
        $staleBefore = $now->subSeconds((int) config('reports.goal_progress_interval'));

        $lockedTenantIds = array_flip($this->maintenanceLocks->lockedTenantIds());

        /** @var list<string> $due */
        $due = [];

        foreach (Goal::withoutTenantScope()->cursor() as $goal) {
            if (isset($lockedTenantIds[$goal->tenant_id])) {

                continue;
            }

            if ($this->isDue($goal, $now, $staleBefore)) {
                $due[] = (string) $goal->getKey();
            }
        }

        return $due;
    }

    public function goalFor(string $goalId): ?Goal
    {
        return Goal::withoutTenantScope()->whereKey($goalId)->first();
    }

    /**
     * @throws ReportNotExecutableException
     */
    public function sourceReport(Goal $goal): Report
    {
        $report = Report::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $goal->tenant_id)
            ->whereKey($goal->report_id)
            ->first();

        return $report instanceof Report
            ? $report
            : throw new ReportNotExecutableException(ReportNotExecutableReason::SourceNotVisible);
    }

    /**
     * @param  array{start: CarbonImmutable, end: CarbonImmutable}  $periodBounds
     *
     * @throws ReportNotExecutableException
     */
    public function progressFor(Goal $goal, Report $report, User $actingUser, array $periodBounds, bool $isAnonymised): ?string
    {
        $objectType = $report->objectType;

        if (!$objectType instanceof ObjectType) {
            throw new ReportNotExecutableException(ReportNotExecutableReason::SourceNotVisible);
        }

        $definition = $this->scopeCompiler->scopedDefinition(
            $report->only(ReportInputRules::definitionKeys()),
            $goal,
            $periodBounds,
        );

        $result = $this->runner->run($this->definitionValidator->validate($definition, $objectType, $actingUser));

        if ($isAnonymised && $result->recordCount < (int) config('reports.k_anonymity_threshold')) {
            return null;
        }

        return $result->total;
    }

    private function isDue(Goal $goal, CarbonImmutable $now, CarbonImmutable $staleBefore): bool
    {
        $bounds = $this->periodCalculator->boundsFor($goal->period_type, $now);

        $period = $goal->periods()
            ->withoutGlobalScope(TenantScope::class)
            ->where('period_start', $bounds['start'])
            ->first();

        if (!$period instanceof GoalPeriod) {
            return true;
        }

        return $period->calculated_at === null || $period->calculated_at->lessThan($staleBefore);
    }
}
