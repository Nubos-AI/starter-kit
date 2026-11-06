<?php

declare(strict_types=1);

use App\Enums\Sso\IdentityProvider;
use App\Models\SsoConnection;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Sso\SsoConnectionLookup;
use App\Support\Sso\SsoEnforcementGate;
use App\Support\Sso\SsoUserDirectory;
use App\Support\Tenancy\TenantBinder;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = ModelStub::make(Tenant::class, ['id' => ModelStub::ulid('acme')]);
});

afterEach(function (): void {
    AccessContext::forgetTenant();
    Mockery::close();
});

it('finds the enabled connection that claims a domain across every tenant without the tenant scope', function (): void {
    $query = QueryShape::attemptedBy(fn () => app(SsoConnectionLookup::class)->enabledForDomain('acme.de'));

    expect($query->targets('sso_connections'))->toBeTrue()
        ->and($query->sql)->toContain('"sso_connections"."is_enabled" = ?')
        ->and($query->sql)->toContain('("sso_connections"."allowed_domains")::jsonb @> ?')
        ->and($query->sql)->toContain('("sso_connections"."verified_domains")::jsonb @> ?')
        ->and($query->hasBinding('"acme.de"'))->toBeTrue()
        ->and($query->hidesSoftDeleted('sso_connections'))->toBeTrue()
        ->and($query->blocksEveryRow())->toBeFalse();
});

it('reads the connection of a tenant only within that tenant', function (): void {
    $query = QueryShape::attemptedBy(fn () => app(SsoConnectionLookup::class)->forTenant('tenant-a'));

    expect($query->isScopedToTenant('sso_connections', 'tenant-a'))->toBeTrue()
        ->and($query->hidesSoftDeleted('sso_connections'))->toBeTrue();
});

it('looks up a linked identity only among the human users of the same tenant', function (): void {
    $query = QueryShape::attemptedBy(fn () => app(SsoUserDirectory::class)->byIdentity('tenant-a', IdentityProvider::Oidc, 'https://idp.acme.test', 'subject-1'));

    expect($query->targets('users'))->toBeTrue()
        ->and($query->isScopedToTenant('users', 'tenant-a'))->toBeTrue()
        ->and($query->isScopedToTenant('user_identities', 'tenant-a'))->toBeTrue()
        ->and($query->sql)->toContain('"users"."is_service" = ?')
        ->and($query->hasBinding('oidc'))->toBeTrue()
        ->and($query->hasBinding('https://idp.acme.test'))->toBeTrue()
        ->and($query->hasBinding('subject-1'))->toBeTrue()
        ->and($query->hidesSoftDeleted('user_identities'))->toBeTrue();
});

it('looks up an email among every human user including the removed ones so a foreign account is recognised', function (): void {
    $query = QueryShape::attemptedBy(fn () => app(SsoUserDirectory::class)->byEmail('ada@acme.de'));

    expect($query->targets('users'))->toBeTrue()
        ->and($query->hasBinding('ada@acme.de'))->toBeTrue()
        ->and($query->sql)->toContain('"is_service" = ?')
        ->and($query->hidesSoftDeleted('users'))->toBeFalse();
});

it('locks the password login of a member of an enforced domain while an escalated authority keeps it as the emergency access', function (bool $isEnforced, bool $isEnabled, string $email, bool $isEscalated, bool $isBlocked): void {
    $connection = ModelStub::make(SsoConnection::class, ['tenant_id' => $this->tenant->getKey(), 'is_enabled' => $isEnabled, 'is_enforced' => $isEnforced, 'allowed_domains' => ['acme.de'], 'verified_domains' => ['acme.de']]);
    $lookup = Mockery::mock(SsoConnectionLookup::class);
    $lookup->shouldReceive('forTenant')->with($this->tenant->getKey())->andReturn($connection);
    app()->instance(SsoConnectionLookup::class, $lookup);

    $binder = Mockery::mock(TenantBinder::class);
    $binder->shouldReceive('runIfKnown')->andReturnUsing(fn (string $tenantId, callable $work): mixed => $work($this->tenant));
    app()->instance(TenantBinder::class, $binder);

    $user = Mockery::mock(User::class)->makePartial();
    $user->setRawAttributes(['id' => ModelStub::ulid('ada'), 'tenant_id' => $this->tenant->getKey(), 'email' => $email]);
    $user->shouldReceive('isEscalatedAuthority')->andReturn($isEscalated);

    expect(app(SsoEnforcementGate::class)->blocksLocalLogin($user))->toBe($isBlocked);
})->with([
    'enforced member' => [true, true, 'ada@acme.de', false, true],
    'enforced emergency admin' => [true, true, 'ada@acme.de', true, false],
    'not enforced' => [false, true, 'ada@acme.de', false, false],
    'enforcement of a disabled connection' => [true, false, 'ada@acme.de', false, false],
    'foreign domain' => [true, true, 'ada@partner.de', false, false],
]);

it('never locks the password login of a user whose tenant has no connection', function (): void {
    $lookup = Mockery::mock(SsoConnectionLookup::class);
    $lookup->shouldReceive('forTenant')->andReturnNull();
    app()->instance(SsoConnectionLookup::class, $lookup);

    $user = ModelStub::make(User::class, ['tenant_id' => $this->tenant->getKey(), 'email' => 'ada@acme.de']);

    expect(app(SsoEnforcementGate::class)->blocksLocalLogin($user))->toBeFalse()
        ->and(app(SsoEnforcementGate::class)->blocksLocalLogin(ModelStub::make(User::class, ['tenant_id' => null, 'email' => 'root@nubos.de'])))->toBeFalse();
});
