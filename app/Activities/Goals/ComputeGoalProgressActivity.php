<?php

declare(strict_types=1);

namespace App\Activities\Goals;

use App\Actions\Goals\RecordGoalProgressAction;
use App\Contracts\Goals\ComputeGoalProgressActivityInterface;
use App\Enums\Reports\ReportExecutionMode;
use App\Enums\Reports\ReportNotExecutableReason;
use App\Exceptions\Reports\ReportNotExecutableException;
use App\Models\Goal;
use App\Models\Report;
use App\Models\User;
use App\Support\Goals\GoalPeriodCalculator;
use App\Support\Goals\GoalProgressCalculator;
use App\Support\Goals\GoalThresholdEvaluator;
use App\Support\Maintenance\MaintenanceLockRegistry;
use App\Support\Reports\ReportExecutionContext;
use App\Support\Reports\ReportExecutionModeResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ComputeGoalProgressActivity implements ComputeGoalProgressActivityInterface
{
    public function __construct(
        private readonly GoalPeriodCalculator $periodCalculator,
        private readonly GoalProgressCalculator $calculator,
        private readonly ReportExecutionContext $context,
        private readonly RecordGoalProgressAction $action,
        private readonly GoalThresholdEvaluator $thresholds,
        private readonly MaintenanceLockRegistry $maintenanceLocks,
        private readonly ReportExecutionModeResolver $modeResolver,
    ) {}

    /**
     * @return list<string>
     */
    public function dueGoalIds(): array
    {
        return $this->calculator->dueGoalIds(CarbonImmutable::now());
    }

    public function computeGoalProgress(string $goalId): bool
    {
        try {
            $periodStart = $this->record($goalId);
        } catch (Throwable $exception) {
            return $this->refuse($goalId, $this->reasonOf($exception));
        }

        if ($periodStart === null) {
            return $this->refuse($goalId, 'value-not-available');
        }

        return true;
    }

    /**
     * @throws Throwable
     */
    private function record(string $goalId): ?string
    {
        $goal = $this->calculator->goalFor($goalId);

        if (!$goal instanceof Goal) {
            throw new ReportNotExecutableException(ReportNotExecutableReason::SourceNotVisible);
        }

        $this->maintenanceLocks->assertWritable($goal->tenant_id, 'goal_progress', [
            'goal_id' => $goalId,
        ]);

        $report = $this->calculator->sourceReport($goal);
        $now = CarbonImmutable::now();
        $bounds = $this->periodCalculator->boundsFor($goal->period_type, $now);

        $isDefinerRun = $this->modeResolver->effectiveMode($report) === ReportExecutionMode::Definer;

        $recorded = $this->context->runAsViewer(
            $goal->tenant_id,
            $goal->owner_id,
            function (User $owner) use ($goal, $report, $bounds, $now, $isDefinerRun): bool {
                if ($owner->cannot('view', $report)) {
                    throw new ReportNotExecutableException(ReportNotExecutableReason::SourceNotVisible);
                }

                if (!$isDefinerRun) {
                    return $this->recordProgress($goal, $report, $owner, $bounds, $now, false);
                }

                return $this->context->runAsDefiner(
                    $goal->tenant_id,
                    $report->owner_id,
                    fn (User $definer): bool => $this->recordProgress($goal, $report, $definer, $bounds, $now, true),
                );
            },
        );

        return $recorded === true ? $bounds['start']->toIso8601String() : null;
    }

    /**
     * @param  array{start: CarbonImmutable, end: CarbonImmutable}  $bounds
     *
     * @throws ReportNotExecutableException
     */
    private function recordProgress(
        Goal $goal,
        Report $report,
        User $actingUser,
        array $bounds,
        CarbonImmutable $now,
        bool $isAnonymised,
    ): bool {
        $value = $this->calculator->progressFor($goal, $report, $actingUser, $bounds, $isAnonymised);

        if ($value === null) {
            return false;
        }

        $decision = $this->thresholds->evaluate($goal, $bounds, $value);
        $period = $this->action->execute($goal, $bounds, $value, $now, $decision['thresholds']);

        $this->thresholds->startRuns($goal, $period, $decision['fire']);

        return true;
    }

    private function refuse(string $goalId, string $reason): bool
    {
        Log::error('Goal progress could not be computed.', [
            'goal_id' => $goalId,
            'reason' => $reason,
        ]);

        return false;
    }

    private function reasonOf(Throwable $exception): string
    {
        return $exception instanceof ReportNotExecutableException
            ? $exception->reason->value
            : $exception::class;
    }
}
