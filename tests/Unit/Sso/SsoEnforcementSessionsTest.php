<?php

declare(strict_types=1);

use App\Actions\Sso\RevokeLocalSessionsAction;
use App\Models\SsoConnection;
use App\Models\SsoVerifiedDomain;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Sso\SsoEnforcementGate;
use App\Support\Tenancy\TenantBinder;
use App\Support\Users\UserSessionInvalidator;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = ModelStub::make(Tenant::class, ['id' => ModelStub::ulid('acme')]);
    $this->tenantId = (string) $this->tenant->getKey();

    $this->enforced = fn (array $attributes = []): SsoConnection => ModelStub::make(
        SsoConnection::class,
        ['id' => ModelStub::ulid('connection'), 'tenant_id' => $this->tenantId, 'is_enabled' => true, 'is_enforced' => true, 'allowed_domains' => ['acme.de', 'acme.com'], ...$attributes],
        ['verifiedDomains' => ModelStub::collection(SsoVerifiedDomain::class, [['domain' => 'acme.de'], ['domain' => 'acme.com']])],
    );

    $this->userRows = [
        ['id' => ModelStub::ulid('ada'), 'tenant_id' => $this->tenantId, 'email' => 'ada@acme.de', 'is_service' => false],
        ['id' => ModelStub::ulid('owner'), 'tenant_id' => $this->tenantId, 'email' => 'owner@acme.com', 'is_service' => false],
    ];
    $this->connection = StaticQueryConnection::install(fn (string $sql): array => str_contains($sql, 'from "users"') ? $this->userRows : [], static fn (): int => 1);

    $this->gate = Mockery::mock(SsoEnforcementGate::class);
    $this->gate->shouldReceive('blocksLocalLoginThrough')->andReturnUsing(static fn (SsoConnection $connection, User $user): bool => $user->email !== 'owner@acme.com')->byDefault();
    app()->instance(SsoEnforcementGate::class, $this->gate);

    $this->invalidated = new ArrayObject;
    $invalidator = Mockery::mock(UserSessionInvalidator::class);
    $invalidator->shouldReceive('endInteractiveSessions')->andReturnUsing(fn (User $user, ?string $kept = null) => $this->invalidated->append([(string) $user->getKey(), $kept]));
    app()->instance(UserSessionInvalidator::class, $invalidator);
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    AccessContext::forgetTenant();
    Mockery::close();
});

it('ends the sessions and remember tokens of every human user of the tenant the enforcement now locks out of the password login', function (): void {
    $acting = ModelStub::make(User::class, ['id' => ModelStub::ulid('admin'), 'tenant_id' => $this->tenantId, 'email' => 'admin@acme.de']);

    $count = app(RevokeLocalSessionsAction::class)->execute(($this->enforced)(), ['acme.de', 'acme.com'], $acting);
    $query = $this->connection->queries[0];

    expect($count)->toBe(1)
        ->and($this->invalidated->getArrayCopy())->toBe([[ModelStub::ulid('ada'), null]])
        ->and($query['sql'])->toContain('"users"."tenant_id" = ?')
        ->and($query['sql'])->toContain('"users"."is_service" = ?')
        ->and($query['sql'])->toContain('("users"."email"::text ilike ? or "users"."email"::text ilike ?)')
        ->and($query['sql'])->toContain('"users"."id" != ?')
        ->and($query['sql'])->toContain('"users"."deleted_at" is null')
        ->and($query['bindings'])->toContain($this->tenantId)
        ->and($query['bindings'])->toContain('%@acme.de')
        ->and($query['bindings'])->toContain('%@acme.com')
        ->and($query['bindings'])->toContain(ModelStub::ulid('admin'));
});

it('ends no session while no domain is affected', function (): void {
    $count = app(RevokeLocalSessionsAction::class)->execute(($this->enforced)(), []);

    expect($count)->toBe(0)
        ->and($this->connection->queries)->toBe([])
        ->and($this->invalidated->getArrayCopy())->toBe([]);
});

it('locks the password login through a given connection only for a claimed, unprivileged member and asks the escalation in the tenant of the connection', function (bool $isEnforced, bool $isEnabled, string $email, bool $isEscalated, bool $isBlocked): void {
    $binder = Mockery::mock(TenantBinder::class);
    $binder->shouldReceive('runIfKnown')->andReturnUsing(function (string $tenantId, callable $work): mixed {
        expect($tenantId)->toBe($this->tenantId);

        return $work($this->tenant);
    });
    app()->instance(TenantBinder::class, $binder);
    app()->forgetInstance(SsoEnforcementGate::class);

    $user = Mockery::mock(User::class)->makePartial();
    $user->setRawAttributes(['id' => ModelStub::ulid('ada'), 'tenant_id' => ModelStub::ulid('elsewhere'), 'email' => $email]);
    $user->shouldReceive('isEscalatedAuthority')->andReturn($isEscalated);

    expect(app(SsoEnforcementGate::class)->blocksLocalLoginThrough(($this->enforced)(['is_enforced' => $isEnforced, 'is_enabled' => $isEnabled]), $user))->toBe($isBlocked);
})->with([
    'enforced member' => [true, true, 'ada@acme.de', false, true],
    'enforced emergency admin' => [true, true, 'ada@acme.de', true, false],
    'not enforced' => [false, true, 'ada@acme.de', false, false],
    'disabled' => [true, false, 'ada@acme.de', false, false],
    'foreign domain' => [true, true, 'ada@partner.de', false, false],
]);
