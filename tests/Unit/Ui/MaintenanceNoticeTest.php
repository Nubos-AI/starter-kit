<?php

declare(strict_types=1);

use App\Enums\Maintenance\MaintenanceLockReason;
use App\Enums\Maintenance\MaintenanceLockStatus;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\MaintenanceLock;
use App\Support\Maintenance\MaintenanceLockRegistry;
use App\Support\Modules\ModuleRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->acquiredAt = CarbonImmutable::parse('2026-09-13 08:30:00', 'UTC');

    app()->instance(ModuleRegistry::class, new class extends ModuleRegistry
    {
        /**
         * @return list<string>
         */
        public function all(): array
        {
            return [];
        }
    });

    /** @var callable(array<string, MaintenanceLock>):void */
    $this->locksPerTenant = static function (array $locks): void {
        app()->instance(MaintenanceLockRegistry::class, new class($locks) extends MaintenanceLockRegistry
        {
            /**
             * @var list<string>
             */
            public array $askedFor = [];

            /**
             * @param  array<string, MaintenanceLock>  $locks
             */
            public function __construct(private readonly array $locks) {}

            public function activeFor(string $tenantId): ?MaintenanceLock
            {
                $this->askedFor[] = $tenantId;

                return $this->locks[$tenantId] ?? null;
            }
        });
    };

    /** @var callable():MaintenanceLock */
    $this->activeLock = fn (): MaintenanceLock => ModelStub::make(MaintenanceLock::class, [
        'reason' => MaintenanceLockReason::Manual,
        'status' => MaintenanceLockStatus::Active,
        'acquired_at' => $this->acquiredAt,
    ]);

    /** @var callable():?array<string, mixed> */
    $this->noticeFor = static function (): ?array {
        $shared = app(HandleInertiaRequests::class)->share(Request::create('/dashboard'));

        /** @var ?array<string, mixed> $notice */
        $notice = ($shared['maintenance'])();

        return $notice;
    };

    /** @var callable():list<string> */
    $this->maintenanceKeys = static fn (): array => array_values(array_filter(
        array_keys(app(HandleInertiaRequests::class)->share(Request::create('/dashboard'))),
        static fn (string $key): bool => Str::contains($key, 'maintenance', ignoreCase: true),
    ));
});

afterEach(function (): void {
    app()->forgetInstance(MaintenanceLockRegistry::class);
    app()->forgetInstance(ModuleRegistry::class);
    AccessContext::forgetTenant();
});

it('shares no notice while the bound tenant carries no active lock', function (): void {
    $tenant = AccessContext::tenant();
    ($this->locksPerTenant)([]);

    expect(($this->noticeFor)())->toBeNull()
        ->and((string) $tenant->getKey())->not->toBe('');
});

it('shares the tenant name, the lock start and the reason label while a lock is active', function (): void {
    $tenant = AccessContext::tenant();
    $tenant->name = 'Nubos GmbH';
    ($this->locksPerTenant)([(string) $tenant->getKey() => ($this->activeLock)()]);

    expect(($this->noticeFor)())->toBe([
        'tenantName' => 'Nubos GmbH',
        'since' => $this->acquiredAt->toIso8601String(),
        'reason' => MaintenanceLockReason::Manual->label(),
    ]);
});

it('asks for the lock of the bound tenant and of no other', function (): void {
    $tenant = AccessContext::tenant('other-tenant');
    ($this->locksPerTenant)([ModelStub::ulid('locked-tenant') => ($this->activeLock)()]);

    expect(($this->noticeFor)())->toBeNull()
        ->and(app(MaintenanceLockRegistry::class)->askedFor)->toBe([(string) $tenant->getKey()]);
});

it('shares no notice at all while no tenant is bound', function (): void {
    AccessContext::forgetTenant();
    ($this->locksPerTenant)([ModelStub::ulid('locked-tenant') => ($this->activeLock)()]);

    expect(($this->noticeFor)())->toBeNull();
});

it('shares exactly one maintenance property so no screen has to pick between two', function (): void {
    AccessContext::tenant();
    ($this->locksPerTenant)([]);

    expect(($this->maintenanceKeys)())->toBe(['maintenance']);
});
