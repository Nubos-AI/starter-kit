<?php

declare(strict_types=1);

use App\Actions\Sso\LinkUserIdentityAction;
use App\Actions\Sso\ProvisionIdentityUserAction;
use App\Actions\Sso\ResolveSsoUserAction;
use App\Actions\Users\ConsumeInvitationAction;
use App\Actions\Users\ReleaseUnverifiedRegistrationAction;
use App\DTOs\Sso\IdTokenClaims;
use App\DTOs\Sso\ProvisionedUserData;
use App\Enums\Sso\IdentityProvider;
use App\Enums\Sso\SsoFailure;
use App\Enums\Users\RegistrationReleaseReason;
use App\Enums\Users\UserStatus;
use App\Exceptions\Sso\IdentityLinkedToAnotherUserException;
use App\Exceptions\Sso\SsoLoginException;
use App\Exceptions\Users\InvitationNotAcceptableException;
use App\Models\Role;
use App\Models\SsoConnection;
use App\Models\SsoVerifiedDomain;
use App\Models\User;
use App\Models\UserIdentity;
use App\Support\Authorization\AuthorizationDirectory;
use App\Support\Authorization\PermissionSubsetGuard;
use App\Support\Sso\SsoUserDirectory;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\FakeAuthorizationDirectory;
use Tests\Support\Doubles\StaticAuthorityUser;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant('acme');
    $this->tenantId = (string) $this->tenant->getKey();

    $this->configurer = ModelStub::make(User::class, [
        'id' => ModelStub::ulid('sso-admin'),
        'tenant_id' => $this->tenantId,
        'email' => 'admin@acme.de',
        'status' => UserStatus::Accepted->value,
    ]);

    $this->connection = fn (array $overrides = [], ?User $configurer = null): SsoConnection => ModelStub::make(SsoConnection::class, [
        'tenant_id' => ModelStub::ulid('acme'),
        'configured_by_id' => ModelStub::ulid('sso-admin'),
        'default_role_id' => ModelStub::ulid('member-role'),
        'is_enabled' => true,
        'is_jit_enabled' => false,
        'issuer_url' => 'https://idp.acme.test',
        'allowed_domains' => ['acme.de'],
        ...$overrides,
    ], ['configurer' => $configurer ?? $this->configurer, 'verifiedDomains' => ModelStub::collection(SsoVerifiedDomain::class, [['domain' => 'acme.de']])]);

    AccessContext::grant('sso.manage');
    $this->subsetGuard = Mockery::mock(PermissionSubsetGuard::class);
    $this->subsetGuard->shouldReceive('maySteerAccount')->byDefault()->andReturnTrue();
    $this->subsetGuard->shouldReceive('mayAssignRole')->byDefault()->andReturnTrue();
    app()->instance(PermissionSubsetGuard::class, $this->subsetGuard);

    $this->defaultRole = ModelStub::make(Role::class, ['id' => ModelStub::ulid('member-role'), 'tenant_id' => $this->tenantId]);
    $this->roles = (new FakeAuthorizationDirectory)->withRoles([$this->defaultRole]);
    app()->instance(AuthorizationDirectory::class, $this->roles);

    $this->claims = static fn (array $overrides = []): IdTokenClaims => new IdTokenClaims(
        'https://idp.acme.test',
        $overrides['subject'] ?? 'subject-1',
        array_key_exists('email', $overrides) ? $overrides['email'] : 'ada@acme.de',
        $overrides['verified'] ?? true,
        'Ada',
        'Lovelace',
    );

    $this->member = fn (array $attributes = []): User => ModelStub::make(StaticAuthorityUser::class, [
        'id' => ModelStub::ulid('ada'),
        'tenant_id' => $this->tenantId,
        'email' => 'ada@acme.de',
        'status' => UserStatus::Accepted->value,
        ...$attributes,
    ]);

    $this->owner = function (array $attributes = []): User {
        $owner = ($this->member)($attributes);
        $owner->escalated = true;

        return $owner;
    };

    $this->directory = Mockery::mock(SsoUserDirectory::class);
    $this->provision = Mockery::mock(ProvisionIdentityUserAction::class);
    $this->link = Mockery::mock(LinkUserIdentityAction::class);
    $this->consumeInvitation = Mockery::mock(ConsumeInvitationAction::class);
    app()->instance(ConsumeInvitationAction::class, $this->consumeInvitation);
    app()->instance(SsoUserDirectory::class, $this->directory);
    app()->instance(ProvisionIdentityUserAction::class, $this->provision);
    app()->instance(LinkUserIdentityAction::class, $this->link);

    $this->failureOf = static function (SsoConnection $connection, IdTokenClaims $claims): ?SsoFailure {
        try {
            app(ResolveSsoUserAction::class)->execute($connection, $claims);
        } catch (SsoLoginException $exception) {
            return $exception->failure;
        }

        return null;
    };
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    AccessContext::forgetTenant();
    Mockery::close();
});

it('signs in the user an identity of this issuer and subject is already linked to', function (): void {
    $member = ($this->member)();
    $this->directory->shouldReceive('byIdentity')->once()->with($this->tenantId, IdentityProvider::Oidc, 'https://idp.acme.test', 'subject-1')->andReturn($member);
    $this->directory->shouldNotReceive('byEmail');
    $this->link->shouldNotReceive('execute');

    expect(app(ResolveSsoUserAction::class)->execute(($this->connection)(), ($this->claims)(['verified' => false])))->toBe($member);
});

it('links a member of the tenant on the first sign-on through the verified email address', function (): void {
    $member = ($this->member)();
    $this->directory->shouldReceive('byIdentity')->andReturnNull();
    $this->directory->shouldReceive('byEmail')->once()->with('ada@acme.de')->andReturn($member);
    $this->link->shouldReceive('execute')->once()->with($member, IdentityProvider::Oidc, 'https://idp.acme.test', 'subject-1')->andReturn(new UserIdentity);
    $this->provision->shouldNotReceive('execute');

    expect(app(ResolveSsoUserAction::class)->execute(($this->connection)(), ($this->claims)()))->toBe($member);
});

it('never matches an email the identity provider did not report as verified', function (): void {
    $this->directory->shouldReceive('byIdentity')->andReturnNull();
    $this->directory->shouldNotReceive('byEmail');
    $this->link->shouldNotReceive('execute');

    expect(($this->failureOf)(($this->connection)(), ($this->claims)(['verified' => false])))->toBe(SsoFailure::EmailNotVerified)
        ->and(($this->failureOf)(($this->connection)(), ($this->claims)(['email' => null])))->toBe(SsoFailure::EmailNotVerified);
});

it('refuses to sign in through an email address that belongs to an account of another tenant', function (): void {
    $this->directory->shouldReceive('byIdentity')->andReturnNull();
    $this->directory->shouldReceive('byEmail')->andReturn(($this->member)(['tenant_id' => ModelStub::ulid('other-tenant'), 'email_verified_at' => '2026-10-01 08:00:00']));
    $this->link->shouldNotReceive('execute');
    $this->provision->shouldNotReceive('execute');

    expect(($this->failureOf)(($this->connection)(['is_jit_enabled' => true]), ($this->claims)()))->toBe(SsoFailure::ForeignAccount);
});

it('releases an unverified self registration of another tenant holding the proven address and provisions the person instead of taking that account over', function (): void {
    $squatter = ($this->member)(['id' => ModelStub::ulid('squatter'), 'tenant_id' => ModelStub::ulid('other-tenant')]);
    $created = ($this->member)();
    StaticQueryConnection::install(static fn (): array => []);
    $this->directory->shouldReceive('byIdentity')->andReturnNull();
    $this->directory->shouldReceive('byEmail')->andReturn($squatter);
    $release = Mockery::mock(ReleaseUnverifiedRegistrationAction::class);
    $release->shouldReceive('execute')->once()->with($squatter, RegistrationReleaseReason::ClaimedBySingleSignOn)->andReturnTrue();
    app()->instance(ReleaseUnverifiedRegistrationAction::class, $release);
    $this->provision->shouldReceive('execute')->once()->andReturn($created);
    $this->link->shouldReceive('execute')->once()->with($created, IdentityProvider::Oidc, 'https://idp.acme.test', 'subject-1')->andReturn(new UserIdentity);

    expect(app(ResolveSsoUserAction::class)->execute(($this->connection)(['is_jit_enabled' => true]), ($this->claims)()))->toBe($created);
});

it('still refuses an address of another tenant whose account cannot be released', function (): void {
    $this->directory->shouldReceive('byIdentity')->andReturnNull();
    $this->directory->shouldReceive('byEmail')->andReturn(($this->member)(['tenant_id' => ModelStub::ulid('other-tenant')]));
    $release = Mockery::mock(ReleaseUnverifiedRegistrationAction::class);
    $release->shouldReceive('execute')->once()->andReturnFalse();
    app()->instance(ReleaseUnverifiedRegistrationAction::class, $release);
    $this->link->shouldNotReceive('execute');
    $this->provision->shouldNotReceive('execute');

    expect(($this->failureOf)(($this->connection)(['is_jit_enabled' => true]), ($this->claims)()))->toBe(SsoFailure::ForeignAccount);
});

it('refuses an email outside the domains the connection claims', function (): void {
    $this->directory->shouldReceive('byIdentity')->andReturnNull();
    $this->directory->shouldNotReceive('byEmail');
    $this->provision->shouldNotReceive('execute');

    expect(($this->failureOf)(($this->connection)(['is_jit_enabled' => true]), ($this->claims)(['email' => 'mallory@evil.test'])))->toBe(SsoFailure::NoAccount);
});

it('refuses an unknown person while just in time provisioning is off', function (): void {
    $this->directory->shouldReceive('byIdentity')->andReturnNull();
    $this->directory->shouldReceive('byEmail')->andReturnNull();
    $this->provision->shouldNotReceive('execute');

    expect(($this->failureOf)(($this->connection)(), ($this->claims)()))->toBe(SsoFailure::NoAccount);
});

it('provisions an unknown person with the default role and links the identity while just in time provisioning is on', function (): void {
    $created = ($this->member)();
    $connection = StaticQueryConnection::install(static fn (): array => []);
    $this->directory->shouldReceive('byIdentity')->andReturnNull();
    $this->directory->shouldReceive('byEmail')->andReturnNull();
    $this->provision->shouldReceive('execute')->once()
        ->withArgs(fn (string $tenantId, ProvisionedUserData $data): bool => $tenantId === $this->tenantId
            && $data->email === 'ada@acme.de'
            && $data->firstName === 'Ada'
            && $data->lastName === 'Lovelace'
            && $data->isActive
            && $data->roleId === ModelStub::ulid('member-role'))
        ->andReturn($created);
    $this->link->shouldReceive('execute')->once()->with($created, IdentityProvider::Oidc, 'https://idp.acme.test', 'subject-1')->andReturn(new UserIdentity);

    expect(app(ResolveSsoUserAction::class)->execute(($this->connection)(['is_jit_enabled' => true]), ($this->claims)()))->toBe($created)
        ->and($connection->openedTransactions)->toBe(1);
});

it('never provisions an unknown person just in time while the connection has no entitled configurer', function (?User $configurer, array $grants): void {
    AccessContext::grant(...$grants);
    $this->directory->shouldReceive('byIdentity')->andReturnNull();
    $this->directory->shouldReceive('byEmail')->andReturnNull();
    $this->provision->shouldNotReceive('execute');
    $this->link->shouldNotReceive('execute');

    $connection = ($this->connection)(['is_jit_enabled' => true]);
    $connection->setRelation('configurer', $configurer);

    expect(($this->failureOf)($connection, ($this->claims)()))->toBe(SsoFailure::NoAccount);
})->with([
    'no configurer' => [null, ['sso.manage']],
    'blocked configurer' => [fn (): User => ModelStub::make(User::class, ['id' => ModelStub::ulid('sso-admin'), 'tenant_id' => ModelStub::ulid('acme'), 'status' => UserStatus::Blocked->value]), ['sso.manage']],
    'removed configurer' => [fn (): User => ModelStub::make(User::class, ['id' => ModelStub::ulid('sso-admin'), 'tenant_id' => ModelStub::ulid('acme'), 'status' => UserStatus::Accepted->value, 'deleted_at' => '2026-11-01 08:00:00']), ['sso.manage']],
    'configurer of another tenant' => [fn (): User => ModelStub::make(User::class, ['id' => ModelStub::ulid('sso-admin'), 'tenant_id' => ModelStub::ulid('other-tenant'), 'status' => UserStatus::Accepted->value]), ['sso.manage']],
    'configurer lost the sso permission' => [fn (): User => ModelStub::make(User::class, ['id' => ModelStub::ulid('sso-admin'), 'tenant_id' => ModelStub::ulid('acme'), 'status' => UserStatus::Accepted->value]), ['users.view']],
]);

it('never provisions an unknown person just in time with a default role beyond the rights of the configurer', function (): void {
    $this->directory->shouldReceive('byIdentity')->andReturnNull();
    $this->directory->shouldReceive('byEmail')->andReturnNull();
    $this->subsetGuard->shouldReceive('mayAssignRole')->once()->with($this->configurer, $this->defaultRole)->andReturnFalse();
    $this->provision->shouldNotReceive('execute');
    $this->link->shouldNotReceive('execute');

    expect(($this->failureOf)(($this->connection)(['is_jit_enabled' => true]), ($this->claims)()))->toBe(SsoFailure::NoAccount);
});

it('refuses a blocked or removed account of the tenant and never accepts its invitation', function (array $attributes): void {
    $this->directory->shouldReceive('byIdentity')->andReturnNull();
    $this->directory->shouldReceive('byEmail')->andReturn(($this->member)($attributes));
    $this->link->shouldNotReceive('execute');
    $this->consumeInvitation->shouldNotReceive('execute');

    expect(($this->failureOf)(($this->connection)(), ($this->claims)()))->toBe(SsoFailure::AccountInactive);
})->with([
    'blocked' => [['status' => UserStatus::Blocked->value]],
    'removed' => [['deleted_at' => '2026-10-01 10:00:00']],
    'removed while invited' => [['status' => UserStatus::Invited->value, 'deleted_at' => '2026-10-01 10:00:00']],
]);

it('accepts the pending invitation of a member who signs on with the verified invited email and links the identity', function (): void {
    $invited = ($this->member)(['status' => UserStatus::Invited->value]);
    $connection = StaticQueryConnection::install(static fn (): array => []);
    $this->directory->shouldReceive('byIdentity')->andReturnNull();
    $this->directory->shouldReceive('byEmail')->once()->with('ada@acme.de')->andReturn($invited);
    $this->consumeInvitation->shouldReceive('execute')->once()
        ->with($invited, ['first_name' => 'Ada', 'last_name' => 'Lovelace'])
        ->andReturnUsing(static function (User $user): void {
            $user->setRawAttributes([...$user->getAttributes(), 'status' => UserStatus::Accepted->value]);
        });
    $this->link->shouldReceive('execute')->once()->with($invited, IdentityProvider::Oidc, 'https://idp.acme.test', 'subject-1')->andReturn(new UserIdentity);
    $this->provision->shouldNotReceive('execute');

    expect(app(ResolveSsoUserAction::class)->execute(($this->connection)(), ($this->claims)()))->toBe($invited)
        ->and($invited->status)->toBe(UserStatus::Accepted)
        ->and($connection->openedTransactions)->toBe(1);
});

it('refuses the member whose invitation is no longer pending without linking the identity', function (): void {
    StaticQueryConnection::install(static fn (): array => []);
    $this->directory->shouldReceive('byIdentity')->andReturnNull();
    $this->directory->shouldReceive('byEmail')->andReturn(($this->member)(['status' => UserStatus::Invited->value]));
    $this->consumeInvitation->shouldReceive('execute')->once()->andThrow(InvitationNotAcceptableException::noLongerPending());
    $this->link->shouldNotReceive('execute');

    expect(($this->failureOf)(($this->connection)(), ($this->claims)()))->toBe(SsoFailure::AccountInactive);
});

it('refuses a linked identity whose account was blocked since', function (): void {
    $this->directory->shouldReceive('byIdentity')->andReturn(($this->member)(['status' => UserStatus::Blocked->value]));

    expect(($this->failureOf)(($this->connection)(), ($this->claims)()))->toBe(SsoFailure::AccountInactive);
});

it('never signs in an owner or super admin account through single sign-on, whether linked, matched by email or invited', function (string $path, array $attributes): void {
    $owner = ($this->owner)($attributes);
    $this->directory->shouldReceive('byIdentity')->andReturn($path === 'linked' ? $owner : null);
    $this->directory->shouldReceive('byEmail')->andReturn($owner);
    $this->link->shouldNotReceive('execute');
    $this->consumeInvitation->shouldNotReceive('execute');
    $this->provision->shouldNotReceive('execute');

    expect(($this->failureOf)(($this->connection)(['is_jit_enabled' => true]), ($this->claims)()))->toBe(SsoFailure::PrivilegedAccount);
})->with([
    'linked identity' => ['linked', []],
    'matched by email' => ['email', []],
    'pending invitation' => ['email', ['status' => UserStatus::Invited->value]],
]);

it('tells an owner refused at single sign-on to sign in with email and password in german', function (): void {
    app()->setLocale('de');

    expect(SsoFailure::PrivilegedAccount->label())->toBe('Konten mit Inhaber- oder Super-Admin-Rolle melden sich mit E-Mail-Adresse und Passwort an, nicht per Single Sign-on.');
});

it('never links an account by email that the person who configured the identity provider may not steer', function (string $status): void {
    $target = ($this->member)(['status' => $status]);
    $this->directory->shouldReceive('byIdentity')->andReturnNull();
    $this->directory->shouldReceive('byEmail')->andReturn($target);
    $this->subsetGuard->shouldReceive('maySteerAccount')->once()->with($this->configurer, $target)->andReturnFalse();
    $this->link->shouldNotReceive('execute');
    $this->consumeInvitation->shouldNotReceive('execute');
    $this->provision->shouldNotReceive('execute');

    expect(($this->failureOf)(($this->connection)(['is_jit_enabled' => true]), ($this->claims)()))->toBe(SsoFailure::UnsteerableAccount);
})->with([
    'active member' => [UserStatus::Accepted->value],
    'pending invitation' => [UserStatus::Invited->value],
]);

it('never links an account by email while the connection has no entitled configurer', function (?User $configurer, array $grants): void {
    AccessContext::grant(...$grants);
    $this->directory->shouldReceive('byIdentity')->andReturnNull();
    $this->directory->shouldReceive('byEmail')->andReturn(($this->member)());
    $this->subsetGuard->shouldNotReceive('maySteerAccount');
    $this->link->shouldNotReceive('execute');

    $connection = ($this->connection)();
    $connection->setRelation('configurer', $configurer);

    expect(($this->failureOf)($connection, ($this->claims)()))->toBe(SsoFailure::UnsteerableAccount);
})->with([
    'no configurer' => [null, ['sso.manage']],
    'blocked configurer' => [fn (): User => ModelStub::make(User::class, ['id' => ModelStub::ulid('sso-admin'), 'tenant_id' => ModelStub::ulid('acme'), 'status' => UserStatus::Blocked->value]), ['sso.manage']],
    'configurer of another tenant' => [fn (): User => ModelStub::make(User::class, ['id' => ModelStub::ulid('sso-admin'), 'tenant_id' => ModelStub::ulid('other-tenant'), 'status' => UserStatus::Accepted->value]), ['sso.manage']],
    'configurer lost the sso permission' => [fn (): User => ModelStub::make(User::class, ['id' => ModelStub::ulid('sso-admin'), 'tenant_id' => ModelStub::ulid('acme'), 'status' => UserStatus::Accepted->value]), ['users.view']],
]);

it('tells a person refused at single sign-on for an unsteerable account to sign in with email and password in german', function (): void {
    app()->setLocale('de');

    expect(SsoFailure::UnsteerableAccount->label())->toBe('Dieses Konto lässt sich nicht automatisch mit der Single-Sign-on-Anmeldung verknüpfen. Bitte melden Sie sich mit E-Mail-Adresse und Passwort an oder wenden Sie sich an Ihre Administration.');
});

it('refuses the sign-on with a typed failure when the identity is already linked to another user', function (): void {
    $member = ($this->member)();
    $this->directory->shouldReceive('byIdentity')->andReturnNull();
    $this->directory->shouldReceive('byEmail')->once()->with('ada@acme.de')->andReturn($member);
    $this->link->shouldReceive('execute')->once()->andThrow(new IdentityLinkedToAnotherUserException(IdentityProvider::Oidc));

    expect(($this->failureOf)(($this->connection)(), ($this->claims)()))->toBe(SsoFailure::IdentityLinkedElsewhere)
        ->and(SsoFailure::IdentityLinkedElsewhere->label())->not->toStartWith('i18n.');
});
