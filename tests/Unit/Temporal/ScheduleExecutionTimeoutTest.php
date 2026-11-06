<?php

declare(strict_types=1);

use App\Enums\Temporal\ScheduleEgress;
use App\Support\Goals\GoalScheduleRegistrar;
use App\Support\Temporal\RecordProcessingScheduleRegistrar;
use App\Support\Temporal\TemporalScheduleGateway;
use App\Support\Trash\TrashScheduleRegistrar;
use Carbon\CarbonInterval;
use Temporal\Client\Schedule\Schedule;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->registered = function (bool $exists): array {
        $schedules = [];
        $gateway = Mockery::mock(TemporalScheduleGateway::class);
        $gateway->shouldReceive('exists')->andReturn($exists);
        $gateway->shouldReceive($exists ? 'update' : 'create')->andReturnUsing(function (string $scheduleId, Schedule $schedule, ScheduleEgress $egress) use (&$schedules): void {
            $schedules[$scheduleId] = $schedule;
        });
        $gateway->shouldNotReceive($exists ? 'create' : 'update');

        foreach ([GoalScheduleRegistrar::class, TrashScheduleRegistrar::class, RecordProcessingScheduleRegistrar::class] as $registrar) {
            (new $registrar($gateway))->register();
        }

        return collect($schedules)
            ->map(static fn (Schedule $schedule): int => (int) CarbonInterval::instance($schedule->action->workflowExecutionTimeout)->totalSeconds)
            ->all();
    };
});

afterEach(function (): void {
    Mockery::close();
});

it('gives a new schedule an execution timeout', function (): void {
    config(['temporal.schedules.execution_timeout_seconds' => 3600, 'temporal.schedules.execution_timeouts' => ['goal-progress' => 900]]);

    expect(($this->registered)(false))->toBe([
        'goal-progress' => 900,
        'trash-purge' => 3600,
        'automation-relay' => 3600,
        'periodic-scans' => 3600,
    ]);
});

it('gives an existing schedule an execution timeout', function (): void {
    config(['temporal.schedules.execution_timeout_seconds' => 1200, 'temporal.schedules.execution_timeouts' => ['trash-purge' => 7200]]);

    expect(($this->registered)(true))->toBe([
        'goal-progress' => 1200,
        'trash-purge' => 7200,
        'automation-relay' => 1200,
        'periodic-scans' => 1200,
    ]);
});

it('never registers a schedule without a timeout even when the configuration names none', function (): void {
    config(['temporal.schedules' => []]);

    expect(array_values(array_unique(($this->registered)(false))))->toBe([3600]);
});
