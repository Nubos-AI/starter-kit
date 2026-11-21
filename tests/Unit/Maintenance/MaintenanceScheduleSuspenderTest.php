<?php

declare(strict_types=1);

use App\Support\Maintenance\MaintenanceScheduleSuspender;
use App\Support\Temporal\TemporalScheduleGateway;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('suspends only the running schedules carrying the tenant ulid', function (): void {
    $tenantId = (string) $this->tenant->getKey();
    $otherTenantId = ModelStub::ulid('other-tenant');

    $gateway = new class(['trash-purge' => false, 'goal-progress' => false, 'automation-relay' => false, 'report-digest:'.$tenantId => false, 'report-digest:'.$otherTenantId => false, 'x:'.$tenantId => true]) extends TemporalScheduleGateway
    {
        /**
         * @var list<array{string, string}>
         */
        public array $paused = [];

        /**
         * @var list<array{string, string}>
         */
        public array $unpaused = [];

        /**
         * @param  array<string, bool>  $states
         */
        public function __construct(private readonly array $states) {}

        /**
         * @return array<string, bool>
         */
        public function pauseStates(): array
        {
            return $this->states;
        }

        public function pause(string $scheduleId, string $note): void
        {
            $this->paused[] = [$scheduleId, $note];
        }

        public function unpause(string $scheduleId, string $note): void
        {
            $this->unpaused[] = [$scheduleId, $note];
        }
    };

    $suspender = new MaintenanceScheduleSuspender($gateway);

    $scheduleIds = $suspender->suspendableIdsFor($tenantId);

    expect($scheduleIds)->toBe(['report-digest:'.$tenantId]);

    $suspender->suspend($scheduleIds, 'maintenance-lock:probe');

    expect($gateway->paused)->toBe([['report-digest:'.$tenantId, 'maintenance-lock:probe']])
        ->and($gateway->unpaused)->toBe([]);

    $suspender->resume(['report-digest:'.$tenantId, 'x:'.$tenantId], 'maintenance-lock:probe');

    expect($gateway->unpaused)->toBe([
        ['report-digest:'.$tenantId, 'maintenance-lock:probe'],
        ['x:'.$tenantId, 'maintenance-lock:probe'],
    ])
        ->and($gateway->paused)->toHaveCount(1);
});
