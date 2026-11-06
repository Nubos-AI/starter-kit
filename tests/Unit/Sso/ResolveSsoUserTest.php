<?php

declare(strict_types=1);

use App\Actions\Sso\LinkUserIdentityAction;
use App\Actions\Sso\ProvisionIdentityUserAction;
use App\Actions\Sso\ResolveSsoUserAction;
use App\Actions\Users\ConsumeInvitationAction;
use App\DTOs\Sso\IdTokenClaims;
use App\DTOs\Sso\ProvisionedUserData;
use App\Enums\Sso\IdentityProvider;
use App\Enums\Sso\SsoFailure;
use App\Enums\Users\UserStatus;
use App\Exceptions\Sso\SsoLoginException;
use App\Exceptions\Users\InvitationNotAcceptableException;
use App\Models\SsoConnection;
use App\Models\User;
use App\Models\UserIdentity;
use App\Support\Sso\SsoUserDirectory;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant('acme');
    $this->tenantId = (string) $this->tenant->getKey();

    $this->connection = static fn (array $overrides = []): SsoConnection => ModelStub::make(SsoConnection::class, [
        'tenant_id' => ModelStub::ulid('acme'),
        'default_role_id' => ModelStub::ulid('member-role'),
        'is_enabled' => true,
        'is_jit_enabled' => false,
        'issuer_url' => 'https://idp.acme.test',
        'allowed_domains' => ['acme.de'],
        'verified_domains' => ['acme.de'],
        ...$overrides,
    ]);

    $this->claims = static fn (array $overrides = []): IdTokenClaims => new IdTokenClaims(
        'https://idp.acme.test',
        $overrides['subject'] ?? 'subject-1',
        array_key_exists('email', $overrides) ? $overrides['email'] : 'ada@acme.de',
        $overrides['verified'] ?? true,
        'Ada',
        'Lovelace',
    );

    $this->member = fn (array $attributes = []): User => ModelStub::make(User::class, [
        'id' => ModelStub::ulid('ada'),
        'tenant_id' => $this->tenantId,
        'email' => 'ada@acme.de',
        'status' => UserStatus::Accepted->value,
        ...$attributes,
    ]);

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
    $this->directory->shouldReceive('byEmail')->andReturn(($this->member)(['tenant_id' => ModelStub::ulid('other-tenant')]));
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
