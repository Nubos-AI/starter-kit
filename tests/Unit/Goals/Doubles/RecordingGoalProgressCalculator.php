<?php

declare(strict_types=1);

namespace Tests\Unit\Goals\Doubles;

use App\Enums\Reports\ReportNotExecutableReason;
use App\Exceptions\Reports\ReportNotExecutableException;
use App\Models\Goal;
use App\Models\Report;
use App\Models\User;
use App\Support\Goals\GoalProgressCalculator;
use Carbon\CarbonImmutable;

class RecordingGoalProgressCalculator extends GoalProgressCalculator
{
    /**
     * @var list<array{goalId: string, actingUserId: string, isAnonymised: bool, start: string, end: string}>
     */
    public array $calls = [];

    /**
     * @var list<string>
     */
    public array $goalLookups = [];

    private ?Goal $goal = null;

    private ?Report $report = null;

    private ?string $value = '42';

    public function __construct() {}

    public function withGoal(?Goal $goal): self
    {
        $this->goal = $goal;

        return $this;
    }

    public function withReport(?Report $report): self
    {
        $this->report = $report;

        return $this;
    }

    public function withValue(?string $value): self
    {
        $this->value = $value;

        return $this;
    }

    public function goalFor(string $goalId): ?Goal
    {
        $this->goalLookups[] = $goalId;

        return $this->goal;
    }

    public function sourceReport(Goal $goal): Report
    {
        return $this->report instanceof Report
            ? $this->report
            : throw new ReportNotExecutableException(ReportNotExecutableReason::SourceNotVisible);
    }

    /**
     * @param  array{start: CarbonImmutable, end: CarbonImmutable}  $periodBounds
     */
    public function progressFor(Goal $goal, Report $report, User $actingUser, array $periodBounds, bool $isAnonymised): ?string
    {
        $this->calls[] = [
            'goalId' => (string) $goal->getKey(),
            'actingUserId' => (string) $actingUser->getKey(),
            'isAnonymised' => $isAnonymised,
            'start' => $periodBounds['start']->toIso8601String(),
            'end' => $periodBounds['end']->toIso8601String(),
        ];

        return $this->value;
    }
}
