<?php

declare(strict_types=1);

use App\Http\Controllers\Settings\AbsencesController;
use App\Models\AbsenceDelegation;
use App\Models\User;
use App\Policies\Governance\AbsenceDelegationPolicy;
use App\Support\Authorization\PermissionSubsetGuard;
use Illuminate\Http\Request;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\FakeManagedUserResolver;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->actor = AccessContext::actAs(AccessContext::user($this->tenant, ['first_name' => 'Ada', 'last_name' => 'Manager', 'email' => 'ada@example.test'], 'absence-manager'));
    $this->managed = AccessContext::user($this->tenant, ['first_name' => 'Ben', 'last_name' => 'Managed', 'email' => 'ben@example.test'], 'managed-colleague');
    $this->outsider = AccessContext::user($this->tenant, ['first_name' => 'Cleo', 'last_name' => 'Outside', 'email' => 'cleo@example.test'], 'outside-reach');

    AccessContext::grant('absences.manage');

    StaticQueryConnection::install(
        fn (string $sql, array $bindings): array => match (true) {
            str_contains($sql, 'from "users"') && in_array((string) $this->outsider->getKey(), $bindings, true) => [$this->outsider->getAttributes()],
            str_contains($sql, 'from "users"') && in_array((string) $this->managed->getKey(), $bindings, true) => [$this->managed->getAttributes()],
            str_contains($sql, 'from "users"') => [$this->actor->getAttributes(), $this->managed->getAttributes(), $this->outsider->getAttributes()],
            default => [],
        },
    );

    $guard = Mockery::mock(PermissionSubsetGuard::class);
    $guard->shouldReceive('maySteerAccount')->andReturn(true);
    app()->instance(PermissionSubsetGuard::class, $guard);

    FakeManagedUserResolver::reachingTeams([])->managing([(string) $this->managed->getKey()]);

    $this->delegationOf = fn (User $subject): AbsenceDelegation => ModelStub::make(AbsenceDelegation::class, [
        'id' => ModelStub::ulid("delegation-{$subject->getKey()}"),
        'tenant_id' => (string) $this->tenant->getKey(),
        'user_id' => (string) $subject->getKey(),
        'delegate_id' => ModelStub::ulid('some-delegate'),
        'starts_at' => '2026-04-01 00:00:00',
        'ends_at' => '2026-04-08 00:00:00',
    ], ['user' => $subject]);

    $this->requestFor = function (array $query = []): Request {
        $request = Request::create('/settings/absences', 'GET', $query);
        $request->setUserResolver(fn (): User => $this->actor);

        return $request;
    };
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    AccessContext::forgetTenant();
    Mockery::close();
});

it('refuses to show, change or delete the absence of a person outside the management reach', function (): void {
    $policy = app(AbsenceDelegationPolicy::class);
    $delegation = ($this->delegationOf)($this->outsider);

    expect($policy->view($this->actor, $delegation))->toBeFalse()
        ->and($policy->update($this->actor, $delegation))->toBeFalse()
        ->and($policy->delete($this->actor, $delegation))->toBeFalse();
});

it('lets a manager show, change and delete the absence of a person within the management reach', function (): void {
    $policy = app(AbsenceDelegationPolicy::class);
    $delegation = ($this->delegationOf)($this->managed);

    expect($policy->view($this->actor, $delegation))->toBeTrue()
        ->and($policy->update($this->actor, $delegation))->toBeTrue()
        ->and($policy->delete($this->actor, $delegation))->toBeTrue();
});

it('lets every person manage their own absence without any management reach', function (): void {
    AccessContext::grant();

    expect(app(AbsenceDelegationPolicy::class)->delete($this->actor, ($this->delegationOf)($this->actor)))->toBeTrue();
});

it('refuses to open the absence page of a person outside the management reach', function (): void {
    $controller = app(AbsencesController::class);

    expect(fn (): Response => $controller->index(($this->requestFor)(['user' => (string) $this->outsider->getKey()])))
        ->toThrow(NotFoundHttpException::class);
});

it('offers only persons within the management reach as absent persons', function (): void {
    $controller = app(AbsencesController::class);

    $props = (fn (): array => $this->props)->call($controller->index(($this->requestFor)()));

    expect(collect($props['manageableUserOptions'])->pluck('value')->all())
        ->toBe([(string) $this->actor->getKey(), (string) $this->managed->getKey()]);
});
