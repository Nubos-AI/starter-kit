<?php

declare(strict_types=1);

use App\Actions\Maintenance\AcquireMaintenanceLockAction;
use App\Actions\Maintenance\ReleaseMaintenanceLockAction;
use App\Enums\Maintenance\MaintenanceLockReason;
use App\Enums\Maintenance\MaintenanceLockRelease;
use App\Enums\Maintenance\MaintenanceLockStatus;
use App\Http\Controllers\Maintenance\MaintenanceLockController;
use App\Models\MaintenanceLock;
use App\Models\User;
use App\Support\Maintenance\MaintenanceLockRegistry;
use App\Support\Teams\TeamSegment;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Inertia\Response;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant('maintenance-controller-tenant');
    URL::defaults([TeamSegment::key() => 'core']);

    $this->lock = ModelStub::make(MaintenanceLock::class, [
        'id' => ModelStub::ulid('maintenance-controller-lock'),
        'tenant_id' => $this->tenant->getKey(),
        'status' => MaintenanceLockStatus::Active->value,
    ]);

    $this->acquired = [];
    $this->released = [];

    $this->controllerWith = function (?MaintenanceLock $active): MaintenanceLockController {
        $registry = new class($active) extends MaintenanceLockRegistry
        {
            public function __construct(private readonly ?MaintenanceLock $active) {}

            public function activeFor(string $tenantId): ?MaintenanceLock
            {
                return $this->active;
            }
        };

        $acquire = new class($this) extends AcquireMaintenanceLockAction
        {
            public function __construct(private object $probe) {}

            public function execute(User $actor, string $tenantId, MaintenanceLockReason $reason, array $input): MaintenanceLock
            {
                $this->probe->acquired[] = ['tenant' => $tenantId, 'reason' => $reason, 'input' => $input];

                return $this->probe->lock;
            }
        };

        $release = new class($this) extends ReleaseMaintenanceLockAction
        {
            public function __construct(private object $probe) {}

            public function execute(User $actor, MaintenanceLock $lock, MaintenanceLockRelease $mode, ?string $note = null): MaintenanceLock
            {
                $this->probe->released[] = $mode;

                return $lock;
            }
        };

        return new MaintenanceLockController($registry, $acquire, $release);
    };

    $this->requestBy = static function (User $user, array $payload = []): Request {
        $request = Request::create('/engine/maintenance', 'POST', $payload);
        $request->setUserResolver(static fn (): User => $user);

        return $request;
    };
});

afterEach(function (): void {
    URL::defaults([TeamSegment::key() => null]);
    AccessContext::forgetTenant();
});

it('refuses every maintenance screen and action without the manage permission', function (): void {
    AccessContext::grant('object-types.view');
    $user = AccessContext::user($this->tenant);
    $controller = ($this->controllerWith)($this->lock);

    expect(fn (): Response => $controller->show(($this->requestBy)($user)))
        ->toThrow(AuthorizationException::class)
        ->and(fn (): RedirectResponse => $controller->store(($this->requestBy)($user, ['note' => 'Upgrade'])))
        ->toThrow(AuthorizationException::class)
        ->and(fn (): RedirectResponse => $controller->destroy(($this->requestBy)($user)))
        ->toThrow(AuthorizationException::class)
        ->and($this->acquired)->toBe([])
        ->and($this->released)->toBe([]);
});

it('enables maintenance mode for the tenant of the acting user and hands the note to the action', function (): void {
    AccessContext::grant('maintenance.manage');
    $user = AccessContext::user($this->tenant);

    $response = ($this->controllerWith)(null)->store(($this->requestBy)($user, ['note' => 'Upgrade']));

    expect($this->acquired)->toBe([[
        'tenant' => (string) $this->tenant->getKey(),
        'reason' => MaintenanceLockReason::Manual,
        'input' => ['note' => 'Upgrade'],
    ]])
        ->and($response->getTargetUrl())->toContain('maintenance');
});

it('releases the active lock manually', function (): void {
    AccessContext::grant('maintenance.manage');
    $user = AccessContext::user($this->tenant);

    ($this->controllerWith)($this->lock)->destroy(($this->requestBy)($user));

    expect($this->released)->toBe([MaintenanceLockRelease::Manual]);
});

it('stays silent when there is no active lock to release', function (): void {
    AccessContext::grant('maintenance.manage');
    $user = AccessContext::user($this->tenant);

    $response = ($this->controllerWith)(null)->destroy(($this->requestBy)($user));

    expect($this->released)->toBe([])
        ->and($response->getTargetUrl())->toContain('maintenance');
});
