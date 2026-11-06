<?php

declare(strict_types=1);

use App\Actions\Tenancy\DeleteTenantAction;
use App\Actions\Tenancy\PurgeTenantAction;
use App\Contracts\Modules\TenantDeletionGuardInterface;
use App\DTOs\Tenancy\PurgeReport;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Maintenance\MaintenanceLockRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = ModelStub::make(Tenant::class, [
        'id' => ModelStub::ulid('deleted-tenant'),
        'name' => 'Deleted',
        'slug' => 'deleted',
    ]);

    $this->purge = Mockery::mock(PurgeTenantAction::class);
    $this->guard = Mockery::mock(TenantDeletionGuardInterface::class);
    $this->locks = Mockery::mock(MaintenanceLockRegistry::class);

    app()->instance(PurgeTenantAction::class, $this->purge);
    app()->instance(TenantDeletionGuardInterface::class, $this->guard);
    app()->instance(MaintenanceLockRegistry::class, $this->locks);

    /** @var callable(bool):User */
    $this->actor = static function (bool $isEscalated): User {
        $actor = Mockery::mock(User::class)->makePartial();
        $actor->shouldReceive('isEscalatedAuthority')->andReturn($isEscalated);
        $actor->shouldReceive('getKey')->andReturn(ModelStub::ulid('deleting-actor'));

        return $actor;
    };
});

afterEach(function (): void {
    foreach ([PurgeTenantAction::class, TenantDeletionGuardInterface::class, MaintenanceLockRegistry::class] as $abstract) {
        app()->forgetInstance($abstract);
    }

    Mockery::close();
});

it('purges a deletable tenant through the tenant purge once every guard passed', function (): void {
    $report = new PurgeReport([], 0, 0, []);

    $this->guard->shouldReceive('assertDeletable')->once()->with($this->tenant);
    $this->locks->shouldReceive('assertWritable')->once()->with(ModelStub::ulid('deleted-tenant'), 'tenant_deletion', Mockery::type('array'));
    $this->purge->shouldReceive('execute')->once()->with($this->tenant)->andReturn($report);

    expect(app(DeleteTenantAction::class)->execute($this->tenant, ($this->actor)(true)))->toBe($report);
});

it('refuses an actor without escalated authority and never purges', function (): void {
    $this->guard->shouldNotReceive('assertDeletable');
    $this->purge->shouldNotReceive('execute');

    expect(fn (): PurgeReport => app(DeleteTenantAction::class)->execute($this->tenant, ($this->actor)(false)))
        ->toThrow(AuthorizationException::class);
});
