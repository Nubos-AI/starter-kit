<?php

declare(strict_types=1);

use App\Actions\Users\AcceptInvitationAction;
use App\Actions\Users\ConsumeInvitationAction;
use App\Actions\Users\RequestEmailChangeAction;
use App\Actions\Users\ResetUserPasswordAction;
use App\Actions\Users\UpdateProfileAction;
use App\Actions\Users\UpdateUserAction;
use App\Enums\Users\UserStatus;
use App\Models\SsoConnection;
use App\Models\SsoVerifiedDomain;
use App\Support\Sso\SsoConnectionLookup;
use App\Support\Tenancy\TenantBinder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\FakePresenceVerifier;
use Tests\Support\Doubles\StaticAuthorityUser;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    app()->setLocale('de');

    $this->tenant = AccessContext::tenant('acme');
    $this->isEnforced = true;

    $lookup = Mockery::mock(SsoConnectionLookup::class);
    $lookup->shouldReceive('forTenant')->andReturnUsing(fn (): SsoConnection => ModelStub::make(SsoConnection::class, [
        'tenant_id' => (string) $this->tenant->getKey(),
        'is_enabled' => true,
        'is_enforced' => $this->isEnforced,
        'allowed_domains' => ['acme.de', 'acme.com'],
    ], ['verifiedDomains' => ModelStub::collection(SsoVerifiedDomain::class, [['domain' => 'acme.de'], ['domain' => 'acme.com']])]));
    app()->instance(SsoConnectionLookup::class, $lookup);

    $binder = Mockery::mock(TenantBinder::class);
    $binder->shouldReceive('runIfKnown')->andReturnUsing(fn (string $tenantId, callable $work): mixed => $work($this->tenant));
    app()->instance(TenantBinder::class, $binder);

    $this->user = ModelStub::make(StaticAuthorityUser::class, [
        'id' => ModelStub::ulid('ada'),
        'tenant_id' => (string) $this->tenant->getKey(),
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'email' => 'ada@acme.de',
        'status' => UserStatus::Accepted->value,
        'password' => Hash::make('old-secret-password'),
        'remember_token' => 'remembered',
    ]);

    $this->connection = StaticQueryConnection::install(
        static fn (): array => [],
        static fn (): int => 1,
    );

    $this->enforcedMessage = __('i18n.backend.enums.sso.sso_failure.local_login_enforced');
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    AccessContext::forgetTenant();
    Mockery::close();
});

it('refuses on the email field to move the own address of an sso enforced member out of the proven domains', function (): void {
    FakePresenceVerifier::holding(['users' => []]);
    $updateUser = Mockery::mock(UpdateUserAction::class);
    $updateUser->shouldNotReceive('execute');
    app()->instance(UpdateUserAction::class, $updateUser);

    try {
        app(UpdateProfileAction::class)->execute($this->user, ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'ada@partner.de']);
        $this->fail('The profile update was not refused.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toBe(['email' => [__('i18n.backend.actions.users.update_profile_action.email_must_stay_within_the_sso_domains')]]);
    }
});

it('lets the own address change while it stays in a proven domain, the enforcement is off or the member is the emergency access', function (string $email, bool $isEnforced, bool $isEscalated): void {
    FakePresenceVerifier::holding(['users' => []]);
    $this->isEnforced = $isEnforced;
    $this->user->escalated = $isEscalated;
    $updateUser = Mockery::mock(UpdateUserAction::class);
    $updateUser->shouldReceive('execute')->once()->withArgs(fn ($user, array $validated): bool => !array_key_exists('email', $validated))->andReturn($this->user);
    app()->instance(UpdateUserAction::class, $updateUser);
    $requestEmailChange = Mockery::mock(RequestEmailChangeAction::class);
    $requestEmailChange->shouldReceive('execute')->once()->with($this->user, $email);
    app()->instance(RequestEmailChangeAction::class, $requestEmailChange);
    AccessContext::actAs($this->user);

    app(UpdateProfileAction::class)->execute($this->user, ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => $email, 'current_password' => 'old-secret-password']);
})->with([
    'another proven domain' => ['ada@acme.com', true, false],
    'enforcement off' => ['ada@partner.de', false, false],
    'emergency access' => ['ada@partner.de', true, true],
]);

it('refuses on the email field to reset the password of a member the sso enforcement locks out of the local login', function (): void {
    expect(fn () => app(ResetUserPasswordAction::class)->reset($this->user, ['password' => 'new-secret-password', 'password_confirmation' => 'new-secret-password']))
        ->toThrow(ValidationException::class, $this->enforcedMessage)
        ->and($this->connection->writtenStatements)->toBe([]);
});

it('resets the password of a member outside the enforcement and ends every session and the remembered login', function (): void {
    $this->isEnforced = false;

    app(ResetUserPasswordAction::class)->reset($this->user, ['password' => 'new-secret-password', 'password_confirmation' => 'new-secret-password']);

    expect($this->connection->writtenSqlOf('sessions'))->toBe(['delete from "sessions" where "user_id" = ?'])
        ->and($this->user->remember_token)->toBeNull()
        ->and(Hash::check('new-secret-password', $this->user->password))->toBeTrue();
});

it('refuses on the email field to accept an invitation with a password while the sso enforcement covers the invitee', function (): void {
    $this->user->setRawAttributes([...$this->user->getAttributes(), 'status' => UserStatus::Invited->value]);
    $consume = Mockery::mock(ConsumeInvitationAction::class);
    $consume->shouldNotReceive('execute');
    app()->instance(ConsumeInvitationAction::class, $consume);

    try {
        app(AcceptInvitationAction::class)->execute($this->user, ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'password' => 'Str0ng-Passphrase!42', 'password_confirmation' => 'Str0ng-Passphrase!42']);
        $this->fail('The invitation acceptance was not refused.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toBe(['email' => [__('i18n.backend.actions.users.accept_invitation_action.sign_in_with_sso_to_accept')]]);
    }
});

it('accepts an invitation with a password while the sso enforcement does not cover the invitee', function (): void {
    $this->isEnforced = false;
    $consume = Mockery::mock(ConsumeInvitationAction::class);
    $consume->shouldReceive('execute')->once()->withArgs(fn ($user, array $attributes): bool => $user === $this->user && Hash::check('Str0ng-Passphrase!42', $attributes['password']));
    app()->instance(ConsumeInvitationAction::class, $consume);

    app(AcceptInvitationAction::class)->execute($this->user, ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'password' => 'Str0ng-Passphrase!42', 'password_confirmation' => 'Str0ng-Passphrase!42']);
});
