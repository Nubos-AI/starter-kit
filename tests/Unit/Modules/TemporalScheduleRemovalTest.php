<?php

declare(strict_types=1);

use App\Contracts\Modules\OutboundGuardInterface;
use App\Support\Temporal\TemporalScheduleGateway;
use Google\Protobuf\Timestamp;
use Temporal\Api\Common\V1\WorkflowExecution;
use Temporal\Api\Schedule\V1\ScheduleActionResult;
use Temporal\Api\Schedule\V1\ScheduleInfo;
use Temporal\Api\Schedule\V1\ScheduleListEntry;
use Temporal\Api\Workflowservice\V1\DeleteScheduleResponse;
use Temporal\Api\Workflowservice\V1\DescribeScheduleResponse;
use Temporal\Api\Workflowservice\V1\ListSchedulesResponse;
use Temporal\Api\Workflowservice\V1\PatchScheduleResponse;
use Temporal\Client\GRPC\Context;
use Temporal\Client\GRPC\ServiceClientInterface;
use Temporal\Client\ScheduleClient;
use Tests\TestCase;

uses(TestCase::class);

it('pauses before capturing running executions and deleting the schedule through the Temporal SDK', function (): void {
    $rpc = Mockery::mock(ServiceClientInterface::class);
    $rpc->shouldReceive('getContext')->andReturn(Context::default());
    $rpc->shouldReceive('withContext')->andReturnSelf();
    $rpc->shouldReceive('ListSchedules')->once()->ordered()->andReturn(new ListSchedulesResponse([
        'schedules' => [new ScheduleListEntry(['schedule_id' => 'automation-time-triggers'])],
    ]));
    $rpc->shouldReceive('PatchSchedule')->once()->ordered()->with(Mockery::on(
        fn ($request): bool => $request->getScheduleId() === 'automation-time-triggers' && $request->getPatch()->getPause() !== '',
    ))->andReturn(new PatchScheduleResponse);
    $rpc->shouldReceive('DescribeSchedule')->once()->ordered()->andReturn(new DescribeScheduleResponse([
        'info' => new ScheduleInfo([
            'create_time' => new Timestamp(['seconds' => 1]),
            'running_workflows' => [new WorkflowExecution(['workflow_id' => 'running', 'run_id' => 'run-1'])],
            'recent_actions' => [new ScheduleActionResult([
                'schedule_time' => new Timestamp(['seconds' => 1]),
                'actual_time' => new Timestamp(['seconds' => 1]),
                'start_workflow_result' => new WorkflowExecution(['workflow_id' => 'recent', 'run_id' => 'run-2']),
            ])],
        ]),
    ]));
    $rpc->shouldReceive('DeleteSchedule')->once()->ordered()->with(Mockery::on(
        fn ($request): bool => $request->getScheduleId() === 'automation-time-triggers',
    ))->andReturn(new DeleteScheduleResponse);
    $gateway = new TemporalScheduleGateway(new ScheduleClient($rpc), Mockery::mock(OutboundGuardInterface::class));
    $executions = $gateway->remove('automation-time-triggers');
    expect(array_map(fn ($execution): string => $execution->getID(), $executions))->toBe(['running', 'recent']);
});
