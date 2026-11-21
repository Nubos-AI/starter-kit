<?php

declare(strict_types=1);

use App\Actions\Maintenance\AcquireMaintenanceLockAction;
use App\Actions\Maintenance\ReleaseMaintenanceLockAction;
use App\Enums\Authorization\RoleAuthority;
use App\Enums\Maintenance\MaintenanceLockReason;
use App\Enums\Maintenance\MaintenanceLockRelease;
use App\Enums\Maintenance\MaintenanceLockStatus;
use App\Models\MaintenanceLock;
use App\Models\User;
use App\Support\Audit\AdminArtifactAuditor;
use App\Support\Console\ActingUserResolver;
use App\Support\Maintenance\MaintenanceScheduleSuspender;
use App\Support\Tenancy\TenantBinder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\Support\WriteAttempt;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant('maintenance-tenant');
    $this->foreignTenantId = ModelStub::ulid('maintenance-foreign-tenant');

    $this->suspender = new class extends MaintenanceScheduleSuspender
    {
        /**
         * @var list<string>
         */
        public array $resumed = [];

        public array $suspended = [];

        public function __construct() {}

        public function suspendableIdsFor(string $tenantId): array
        {
            return [];
        }

        public function suspend(array $scheduleIds, string $note): void
        {
            $this->suspended[] = $note;
        }

        public function resume(array $scheduleIds, string $note): void
        {
            $this->resumed[] = $note;
        }
    };

    $this->auditor = new class extends AdminArtifactAuditor
    {
        /**
         * @var list<string>
         */
        public array $events = [];

        public function __construct() {}

        public function recordEvent(Model $auditable, string $eventKey, array $payload = [], ?string $tenantId = null): void
        {
            $this->events[] = $eventKey;
        }
    };

    $this->actingUsers = new class extends ActingUserResolver
    {
        public function resolve(?string $identifier): User
        {
            return ModelStub::make(User::class, ['id' => (string) $identifier]);
        }
    };

    $this->binderAnswering = static function (bool $carriesAuthority): TenantBinder {
        return new class($carriesAuthority) extends TenantBinder
        {
            public function __construct(private readonly bool $answer) {}

            public function runIfKnown(string $tenantId, callable $work): mixed
            {
                return $this->answer;
            }
        };
    };

    $this->releaseActionWith = fn (TenantBinder $binder): ReleaseMaintenanceLockAction => new ReleaseMaintenanceLockAction(
        $this->suspender,
        $this->auditor,
        $this->actingUsers,
        $binder,
    );

    $this->lockWith = fn (array $attributes = []): MaintenanceLock => ModelStub::make(MaintenanceLock::class, [
        'id' => ModelStub::ulid('maintenance-lock'),
        'tenant_id' => $this->tenant->getKey(),
        'status' => MaintenanceLockStatus::Active->value,
        'suspended_schedule_ids' => ['schedule-a'],
        ...$attributes,
    ]);
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('refuses to enable maintenance mode without the manage permission and never reaches the database', function (): void {
    AccessContext::grant();
    $actor = AccessContext::user($this->tenant);

    $acquire = app(AcquireMaintenanceLockAction::class);

    $attempt = QueryShape::attemptedBy(function () use ($acquire, $actor): mixed {
        try {
            return $acquire->execute($actor, (string) $this->tenant->getKey(), MaintenanceLockReason::Manual, ['note' => 'Upgrade']);
        } catch (AuthorizationException) {
            return null;
        }
    });

    expect(fn (): MaintenanceLock => $acquire->execute($actor, (string) $this->tenant->getKey(), MaintenanceLockReason::Manual, ['note' => 'Upgrade']))
        ->toThrow(AuthorizationException::class)
        ->and($attempt)->toBeNull();
});

it('accepts a lock only for a tenant that still exists and is not soft deleted', function (): void {
    AccessContext::grant('maintenance.manage');
    $actor = AccessContext::user($this->tenant);

    $acquire = app(AcquireMaintenanceLockAction::class);

    $attempt = QueryShape::attemptedBy(fn (): mixed => $acquire->execute(
        $actor,
        (string) $this->tenant->getKey(),
        MaintenanceLockReason::Manual,
        ['note' => 'Upgrade'],
    ));

    expect($attempt?->targets('tenants'))->toBeTrue()
        ->and($attempt?->hasBinding((string) $this->tenant->getKey()))->toBeTrue()
        ->and($attempt?->sql)->toContain('"deleted_at" is null');
});

it('leaves a lock that is already released untouched', function (): void {
    AccessContext::grant('maintenance.manage');
    $actor = AccessContext::user($this->tenant);
    $lock = ($this->lockWith)(['status' => MaintenanceLockStatus::Released->value]);

    $released = ($this->releaseActionWith)(($this->binderAnswering)(true))
        ->execute($actor, $lock, MaintenanceLockRelease::Manual);

    expect($released)->toBe($lock)
        ->and($this->suspender->resumed)->toBe([])
        ->and($this->auditor->events)->toBe([]);
});

it('refuses a manual release without the manage permission', function (): void {
    AccessContext::grant();
    $actor = AccessContext::user($this->tenant);

    expect(fn (): MaintenanceLock => ($this->releaseActionWith)(($this->binderAnswering)(true))
        ->execute($actor, ($this->lockWith)(), MaintenanceLockRelease::Manual))
        ->toThrow(AuthorizationException::class)
        ->and($this->suspender->resumed)->toBe([]);
});

it('refuses a manual release of a lock that belongs to another tenant', function (): void {
    AccessContext::grant('maintenance.manage');
    $actor = AccessContext::user($this->tenant);

    expect(fn (): MaintenanceLock => ($this->releaseActionWith)(($this->binderAnswering)(true))
        ->execute($actor, ($this->lockWith)(['tenant_id' => $this->foreignTenantId]), MaintenanceLockRelease::Manual))
        ->toThrow(AuthorizationException::class)
        ->and($this->suspender->resumed)->toBe([]);
});

it('refuses the emergency exit to an actor without super admin authority', function (): void {
    AccessContext::grant('maintenance.manage');
    $actor = AccessContext::user($this->tenant);

    expect(fn (): MaintenanceLock => ($this->releaseActionWith)(($this->binderAnswering)(false))
        ->execute($actor, ($this->lockWith)(), MaintenanceLockRelease::Emergency, 'Locked out'))
        ->toThrow(AuthorizationException::class)
        ->and($this->suspender->resumed)->toBe([]);
});

it('demands a written reason for the emergency exit', function (): void {
    AccessContext::grant('maintenance.manage');
    $actor = AccessContext::user($this->tenant, ['tenant_id' => $this->foreignTenantId], 'maintenance-foreign-actor');

    expect(fn (): MaintenanceLock => ($this->releaseActionWith)(($this->binderAnswering)(true))
        ->execute($actor, ($this->lockWith)(), MaintenanceLockRelease::Emergency, null))
        ->toThrow(ValidationException::class)
        ->and($this->suspender->resumed)->toBe([]);
});

it('looks for a super admin outside the locked tenant before an insider may break the lock', function (): void {
    AccessContext::grant('maintenance.manage');
    $actor = AccessContext::user($this->tenant);

    $attempt = QueryShape::attemptedBy(fn (): mixed => ($this->releaseActionWith)(($this->binderAnswering)(true))
        ->execute($actor, ($this->lockWith)(), MaintenanceLockRelease::Emergency, 'Locked out'));

    expect($attempt?->targets('roles'))->toBeTrue()
        ->and($attempt?->hasBinding(RoleAuthority::SuperAdmin->value))->toBeTrue()
        ->and($attempt?->hasBinding((string) $this->tenant->getKey()))->toBeTrue()
        ->and($attempt?->sql)->toContain('"tenant_id" !=')
        ->and($attempt?->isScopedToTenant('roles', (string) $this->tenant->getKey()))->toBeFalse();
});

it('resumes the suspended schedules before it marks the lock released', function (): void {
    AccessContext::grant('maintenance.manage');
    $actor = AccessContext::user($this->tenant);
    $lock = ($this->lockWith)();

    $reached = WriteAttempt::reachedTheDatabase(fn (): mixed => ($this->releaseActionWith)(($this->binderAnswering)(true))
        ->execute($actor, $lock, MaintenanceLockRelease::Manual));

    expect($this->suspender->resumed)->toBe(['maintenance-lock:'.$lock->getKey()])
        ->and($reached)->toBeTrue();
});
