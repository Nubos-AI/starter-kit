<?php

declare(strict_types=1);

use App\Actions\Authorization\SyncUserRolesAction;
use App\Actions\Sso\LinkUserIdentityAction;
use App\Actions\Sso\ProvisionIdentityUserAction;
use App\DTOs\Sso\ProvisionedUserData;
use App\Enums\Sso\IdentityProvider;
use App\Enums\Users\UserStatus;
use App\Exceptions\Sso\IdentityLinkedToAnotherUserException;
use App\Models\User;
use Illuminate\Support\Carbon;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-30 12:00:00', 'UTC'));
    $this->tenant = AccessContext::tenant('acme');
    $this->connection = StaticQueryConnection::install(static fn (): array => [], static fn (): int => 1);
    $this->roles = Mockery::mock(SyncUserRolesAction::class);
    app()->instance(SyncUserRolesAction::class, $this->roles);

    $this->insertInto = fn (string $table): ?array => collect($this->connection->writtenStatements)
        ->first(static fn (array $statement): bool => str_starts_with($statement['sql'], "insert into \"{$table}\""));
});

afterEach(function (): void {
    Carbon::setTestNow();
    StaticQueryConnection::uninstall();
    AccessContext::forgetTenant();
    Mockery::close();
});

it('creates an active, verified member of the tenant with the default role and audits the provisioning', function (): void {
    $this->roles->shouldReceive('execute')->once()->withArgs(static fn (User $user, array $roleIds): bool => $user->email === 'ada@acme.de' && $roleIds === ['role-1']);

    $user = app(ProvisionIdentityUserAction::class)->execute((string) $this->tenant->getKey(), new ProvisionedUserData('ada@acme.de', 'Ada', 'Lovelace', true, 'role-1'));
    $insert = ($this->insertInto)('users');

    expect($user->tenant_id)->toBe((string) $this->tenant->getKey())
        ->and($user->status)->toBe(UserStatus::Accepted)
        ->and($user->email_verified_at?->toIso8601String())->toBe('2026-10-30T12:00:00+00:00')
        ->and($user->password)->toBeNull()
        ->and($user->is_service)->toBeFalsy()
        ->and($insert['sql'])->toContain('"email_verified_at"')
        ->and($insert['bindings'])->toContain('ada@acme.de', 'accepted', (string) $this->tenant->getKey())
        ->and(($this->insertInto)('audit_entries')['bindings'])->toContain('identity.provisioned')
        ->and($this->connection->openedTransactions)->toBeGreaterThanOrEqual(1);
});

it('creates a deactivated member without a role when the provider says so', function (): void {
    $this->roles->shouldNotReceive('execute');

    $user = app(ProvisionIdentityUserAction::class)->execute((string) $this->tenant->getKey(), new ProvisionedUserData('bob@acme.de', null, null, false, null));

    expect($user->status)->toBe(UserStatus::Blocked);
});

it('links an external identity within the tenant of the user and reuses an existing link', function (): void {
    $user = ModelStub::make(User::class, ['id' => ModelStub::ulid('ada'), 'tenant_id' => $this->tenant->getKey()]);

    app(LinkUserIdentityAction::class)->execute($user, IdentityProvider::Oidc, 'https://idp.acme.test', 'subject-1');

    $lookup = collect($this->connection->queries)->first(static fn (array $query): bool => str_contains($query['sql'], 'from "user_identities"'));
    $insert = ($this->insertInto)('user_identities');

    expect($lookup['sql'])->toContain('"user_identities"."tenant_id" = ?')
        ->and($lookup['bindings'])->toContain('oidc', 'https://idp.acme.test', 'subject-1')
        ->and($insert['bindings'])->toContain((string) $user->getKey(), (string) $this->tenant->getKey(), 'oidc', 'subject-1');
});

it('refuses to hand an identity linked to another user of the tenant to the requesting user and writes nothing', function (): void {
    $connection = StaticQueryConnection::install(fn (string $sql): array => str_contains($sql, 'from "user_identities"') ? [[
        'id' => ModelStub::ulid('identity'),
        'tenant_id' => (string) $this->tenant->getKey(),
        'user_id' => ModelStub::ulid('grace'),
        'provider' => 'scim',
        'issuer' => 'scim',
        'subject' => '00u-grace',
    ]] : [], static fn (): int => 1);
    $user = ModelStub::make(User::class, ['id' => ModelStub::ulid('ada'), 'tenant_id' => $this->tenant->getKey()]);

    expect(fn () => app(LinkUserIdentityAction::class)->execute($user, IdentityProvider::Scim, 'scim', '00u-grace'))
        ->toThrow(IdentityLinkedToAnotherUserException::class)
        ->and($connection->writtenStatements)->toBe([]);
});

it('returns the identity the requesting user already holds without writing a second one', function (): void {
    $connection = StaticQueryConnection::install(fn (string $sql): array => str_contains($sql, 'from "user_identities"') ? [[
        'id' => ModelStub::ulid('identity'),
        'tenant_id' => (string) $this->tenant->getKey(),
        'user_id' => ModelStub::ulid('ada'),
        'provider' => 'scim',
        'issuer' => 'scim',
        'subject' => '00u-ada',
    ]] : [], static fn (): int => 1);
    $user = ModelStub::make(User::class, ['id' => ModelStub::ulid('ada'), 'tenant_id' => $this->tenant->getKey()]);

    $identity = app(LinkUserIdentityAction::class)->execute($user, IdentityProvider::Scim, 'scim', '00u-ada');

    expect($identity->getKey())->toBe(ModelStub::ulid('identity'))
        ->and($connection->writtenStatements)->toBe([]);
});
