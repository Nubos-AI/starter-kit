<?php

declare(strict_types=1);

use App\Enums\Maintenance\MaintenanceLockStatus;
use App\Exceptions\Maintenance\TenantUnderMaintenanceException;
use App\Models\MaintenanceLock;
use App\Support\Maintenance\MaintenanceLockRegistry;
use Illuminate\Support\Facades\Route;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->lockedTenantId = ModelStub::ulid('locked-tenant');
    $this->freeTenantId = ModelStub::ulid('free-tenant');

    $this->maintenanceMessage = __('i18n.backend.exceptions.maintenance.tenant_under_maintenance_exception.maintenance_mode_is_active_for_this_tenant_writes_are');
});

it('states the refusal in german', function (): void {
    expect(TenantUnderMaintenanceException::writeRefused('t')->getMessage())
        ->toBe('Für diesen Mandanten ist der Wartungsmodus aktiv. Schreibzugriffe sind gesperrt, bis der Wartungsmodus beendet wird.');
});

it('refuses a write for a locked tenant and names the German reason', function (): void {
    $registry = Mockery::mock(MaintenanceLockRegistry::class)->makePartial();
    $registry->shouldReceive('activeFor')->once()->with($this->lockedTenantId)
        ->andReturn(ModelStub::make(MaintenanceLock::class, [
            'tenant_id' => $this->lockedTenantId,
            'status' => MaintenanceLockStatus::Active->value,
        ]));

    try {
        $registry->assertWritable($this->lockedTenantId, 'import');
    } catch (TenantUnderMaintenanceException $refusal) {
        expect($refusal->getStatusCode())->toBe(503)
            ->and($refusal->getMessage())->toBe($this->maintenanceMessage)
            ->and($refusal->tenantId())->toBe($this->lockedTenantId);

        return;
    }

    $this->fail('Expected the maintenance lock to refuse the write.');
});

it('lets a write of an unlocked tenant pass untouched', function (): void {
    $registry = Mockery::mock(MaintenanceLockRegistry::class)->makePartial();
    $registry->shouldReceive('activeFor')->once()->with($this->freeTenantId)->andReturnNull();

    expect(fn () => $registry->assertWritable($this->freeTenantId, 'import'))
        ->not->toThrow(TenantUnderMaintenanceException::class);
});

it('names exactly one exempt write route and only existing reading routes', function (): void {
    expect(MaintenanceLockRegistry::$exemptRouteNames)->toBe(['engine.maintenance.destroy'])
        ->and(Route::has('engine.maintenance.destroy'))->toBeTrue()
        ->and(MaintenanceLockRegistry::$nonWritingRouteNames)->toContain('engine.records.grid', 'logout')
        ->and(MaintenanceLockRegistry::$nonWritingRouteNames)->not->toContain('engine.maintenance.destroy', 'engine.maintenance.store');

    foreach (MaintenanceLockRegistry::$nonWritingRouteNames as $routeName) {
        expect(Route::has($routeName))->toBeTrue("The non-writing route [{$routeName}] does not exist.");
    }
});
