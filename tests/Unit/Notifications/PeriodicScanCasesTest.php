<?php

declare(strict_types=1);

use App\Actions\Notifications\DeliverRuleNotificationAction;
use App\Actions\Reminders\ListDueRemindersAction;
use App\Models\CustomRecord;
use App\Models\NotificationRule;
use App\Models\User;
use App\Support\Maintenance\MaintenanceLockRegistry;
use App\Support\Notifications\PendingChannelDelivery;
use App\Support\Notifications\RuleTriggerFieldGuard;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

afterEach(function (): void {
    Mockery::close();
});

it('never lists a due reminder of a tenant under a maintenance lock', function (): void {
    $locks = Mockery::mock(MaintenanceLockRegistry::class);
    $locks->shouldReceive('lockedTenantIds')->andReturn(['locked-tenant']);

    $config = Mockery::mock(Repository::class);
    $config->shouldReceive('get')->with('record-processing.scans.max_cases_per_run')->andReturn(500);

    $action = new ListDueRemindersAction($locks, $config);

    $shape = QueryShape::attemptedBy(fn (): array => $action->execute(CarbonImmutable::parse('2026-11-27 08:00:00')));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('reminder_tasks'))->toBeTrue()
        ->and($shape->sql)->toContain('"reminder_tasks"."tenant_id" not in (?)')
        ->and($shape->hasBinding('locked-tenant'))->toBeTrue();
});

it('withholds a rule notification from a recipient who may not read the trigger field and claims no dispatch', function (): void {
    $tenantId = ModelStub::ulid('tenant');
    $rule = ModelStub::make(NotificationRule::class, ['id' => ModelStub::ulid('rule'), 'tenant_id' => $tenantId, 'object_type_id' => ModelStub::ulid('type')]);
    $record = ModelStub::make(CustomRecord::class, ['id' => ModelStub::ulid('record'), 'tenant_id' => $tenantId, 'object_type_id' => ModelStub::ulid('type')]);
    $recipient = ModelStub::make(User::class, ['id' => ModelStub::ulid('recipient'), 'tenant_id' => $tenantId]);

    $guard = Mockery::mock(RuleTriggerFieldGuard::class);
    $guard->shouldReceive('mayRead')->once()->with($recipient, $rule)->andReturnFalse();

    $delivery = Mockery::mock(PendingChannelDelivery::class);
    $delivery->shouldNotReceive('deliver');

    $action = new DeliverRuleNotificationAction($guard, $delivery);

    $isDelivered = null;
    $attempted = QueryShape::attemptedBy(function () use ($action, $rule, $record, $recipient, &$isDelivered): void {
        $isDelivered = $action->execute($rule, $record, $recipient, '3:day');
    });

    expect($isDelivered)->toBeFalse()
        ->and($attempted)->toBeNull();
});
