<?php

declare(strict_types=1);

use App\Contracts\Goals\GoalProgressScanWorkflowInterface;
use App\Enums\Temporal\ScheduleEgress;
use App\Support\Goals\GoalScheduleRegistrar;
use App\Support\Temporal\TemporalScheduleGateway;
use Illuminate\Support\Facades\Artisan;
use Mockery\MockInterface;
use Temporal\Client\Schedule\Policy\ScheduleOverlapPolicy;
use Temporal\Client\Schedule\Schedule;
use Temporal\Workflow\WorkflowMethod;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->captured = null;

    /** @var callable(bool):MockInterface */
    $this->gateway = function (bool $exists): MockInterface {
        $gateway = Mockery::mock(TemporalScheduleGateway::class);

        $gateway->shouldReceive('exists')->with('goal-progress')->andReturn($exists);

        $gateway->shouldReceive($exists ? 'update' : 'create')
            ->with('goal-progress', Mockery::on(function (Schedule $schedule): bool {
                $this->captured = $schedule;

                return true;
            }), ScheduleEgress::Internal);

        $gateway->shouldNotReceive($exists ? 'create' : 'update');

        app()->instance(TemporalScheduleGateway::class, $gateway);

        return $gateway;
    };

    /** @var callable(Schedule):int */
    $this->intervalSeconds = static fn (Schedule $schedule): int => (new DateTimeImmutable('@0'))
        ->add($schedule->spec->intervalList[0]->interval)
        ->getTimestamp();
});

afterEach(function (): void {
    app()->forgetInstance(TemporalScheduleGateway::class);
});

it('creates exactly one goal schedule when none exists yet', function (): void {
    $gateway = ($this->gateway)(false);

    app(GoalScheduleRegistrar::class)->register();

    $gateway->shouldHaveReceived('create')->once();

    expect($this->captured)->toBeInstanceOf(Schedule::class)
        ->and($this->captured->action->workflowId)->toBe('goal-progress-run');
});

it('updates the existing schedule on a second registration instead of duplicating it', function (): void {
    $gateway = ($this->gateway)(true);

    app(GoalScheduleRegistrar::class)->register();

    $gateway->shouldHaveReceived('update')->once();

    expect($this->captured)->toBeInstanceOf(Schedule::class)
        ->and($this->captured->action->workflowId)->toBe('goal-progress-run');
});

it('names the scan workflow, its queue and an overlap policy that skips a late run', function (): void {
    ($this->gateway)(false);

    app(GoalScheduleRegistrar::class)->register();

    $declared = (new ReflectionMethod(GoalProgressScanWorkflowInterface::class, 'run'))
        ->getAttributes(WorkflowMethod::class)[0]
        ->newInstance();

    expect($declared->name)->not->toBeNull()
        ->and($this->captured->action->workflowType->name)->toBe($declared->name)
        ->and($this->captured->action->taskQueue->name)->toBe((string) config('temporal.queue'))
        ->and($this->captured->policies->overlapPolicy)->toBe(ScheduleOverlapPolicy::Skip)
        ->and($this->captured->spec->intervalList)->toHaveCount(1);
});

it('takes the heartbeat interval from the configuration and carries no literal', function (): void {
    config(['reports.goal_progress_interval' => 60]);

    ($this->gateway)(false);

    app(GoalScheduleRegistrar::class)->register();

    expect(($this->intervalSeconds)($this->captured))->toBe(60);
});

it('drives the registrar from its own artisan command', function (): void {
    $gateway = ($this->gateway)(false);

    expect(Artisan::call('goals:register-schedules'))->toBe(0);

    $gateway->shouldHaveReceived('create')->once();

    expect($this->captured)->toBeInstanceOf(Schedule::class);
});
