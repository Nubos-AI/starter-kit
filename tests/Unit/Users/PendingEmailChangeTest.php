<?php

declare(strict_types=1);

use App\Actions\Users\ConfirmEmailChangeAction;
use App\Actions\Users\RequestEmailChangeAction;
use App\Actions\Users\UpdateManagedUserAction;
use App\Actions\Users\UpdateProfileAction;
use App\Enums\Users\UserStatus;
use App\Exceptions\Users\EmailChangeNotConfirmableException;
use App\Http\Controllers\Settings\ProfilesController;
use App\Http\Controllers\Users\EmailChangeConfirmationController;
use App\Models\User;
use App\Notifications\Users\ConfirmEmailChangeNotification;
use App\Notifications\Users\EmailAddressChangedNotification;
use App\Support\Authorization\AuthorizationDirectory;
use App\Support\Sso\SsoEnforcementGate;
use App\Support\Teams\TeamMembershipDirectory;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Http\Request;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\FakeAuthorizationDirectory;
use Tests\Support\Doubles\FakePresenceVerifier;
use Tests\Support\Doubles\FakeTeamMembershipDirectory;
use Tests\Support\Doubles\GateSpy;
use Tests\Support\Doubles\StaticAuthorityUser;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\RouteShape;
use Tests\Support\SchemaShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    app()->setLocale('de');
    Carbon::setTestNow(Carbon::parse('2026-11-10 09:00:00', 'UTC'));
    config(['users.email_change.ttl_hours' => 24]);

    $this->tenant = AccessContext::tenant('acme');

    $this->ssoReleases = false;
    $sso = Mockery::mock(SsoEnforcementGate::class);
    $sso->allows('releasesEnforcedEmail')->andReturnUsing(fn (): bool => $this->ssoReleases);
    app()->instance(SsoEnforcementGate::class, $sso);

    $this->presence = FakePresenceVerifier::holding(['users' => ['grace@acme.de']]);

    $this->connection = StaticQueryConnection::install(
        static fn (): array => [],
        static fn (): int => 1,
    );

    $this->user = AccessContext::actAs(ModelStub::make(StaticAuthorityUser::class, [
        'id' => ModelStub::ulid('ada'),
        'tenant_id' => (string) $this->tenant->getKey(),
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'email' => 'ada@acme.de',
        'status' => UserStatus::Accepted->value,
        'password' => Hash::make('current-secret-password'),
        'email_verified_at' => '2026-10-01 08:00:00',
    ]));

    $this->profile = [
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'email' => 'ada.lovelace@acme.de',
        'current_password' => 'current-secret-password',
    ];

    $this->pending = function (array $attributes = []): User {
        $this->user->setRawAttributes([
            ...$this->user->getAttributes(),
            'pending_email' => 'ada.lovelace@acme.de',
            'pending_email_token_hash' => hash('sha256', 'the-secret-token'),
            'pending_email_expires_at' => '2026-11-10 20:00:00',
            ...$attributes,
        ], true);

        return $this->user;
    };

    Notification::fake();
});

afterEach(function (): void {
    Carbon::setTestNow();
    StaticQueryConnection::uninstall();
    AccessContext::forgetTenant();
    Mockery::close();
});

it('keeps the own address until the new one is confirmed and only remembers the request with a hashed token and an expiry', function (): void {
    app(UpdateProfileAction::class)->execute($this->user, $this->profile);

    expect($this->user->email)->toBe('ada@acme.de')
        ->and($this->user->hasVerifiedEmail())->toBeTrue()
        ->and($this->user->pending_email)->toBe('ada.lovelace@acme.de')
        ->and($this->user->pending_email_token_hash)->toHaveLength(64)
        ->and($this->user->pending_email_expires_at?->toDateTimeString())->toBe('2026-11-11 09:00:00');

    Notification::assertSentOnDemand(
        ConfirmEmailChangeNotification::class,
        fn (ConfirmEmailChangeNotification $notification, array $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routes === ['mail' => 'ada.lovelace@acme.de']
            && hash('sha256', $notification->token) === $this->user->pending_email_token_hash,
    );
    Notification::assertNotSentTo($this->user, ConfirmEmailChangeNotification::class);
    Notification::assertSentOnDemandTimes(EmailAddressChangedNotification::class, 0);
});

it('answers a requested address another account holds exactly like a free one so the request reveals nothing', function (): void {
    app(UpdateProfileAction::class)->execute($this->user, [...$this->profile, 'email' => ' GRACE@acme.de ']);

    expect($this->user->email)->toBe('ada@acme.de')
        ->and($this->user->pending_email)->toBe('grace@acme.de')
        ->and(collect($this->presence->lookups)->where('table', 'users')->all())->toBe([]);

    Notification::assertSentOnDemandTimes(ConfirmEmailChangeNotification::class, 1);
});

it('still refuses on the email field a requested address that leaves the enforced single sign-on domains and sends nothing', function (): void {
    $this->ssoReleases = true;

    try {
        app(UpdateProfileAction::class)->execute($this->user, $this->profile);
        $this->fail('The address change was not refused.');
    } catch (ValidationException $exception) {
        expect(array_keys($exception->errors()))->toBe(['email']);
    }

    expect($this->user->pending_email)->toBeNull();

    Notification::assertNothingSent();
});

it('refuses a requested address on the reserved service domain', function (): void {
    expect(fn () => app(RequestEmailChangeAction::class)->execute($this->user, 'svc-1@service.invalid'))
        ->toThrow(ValidationException::class);

    Notification::assertNothingSent();
});

it('sends the confirmation of an address an administrator sets for another account to that address and keeps the old one meanwhile', function (): void {
    app()->instance(AuthorizationDirectory::class, new FakeAuthorizationDirectory);
    app()->instance(TeamMembershipDirectory::class, new FakeTeamMembershipDirectory);
    AccessContext::grant();
    GateSpy::allowing('updatePassword');
    $actor = AccessContext::actAs(AccessContext::user($this->tenant, ['email' => 'chefin@acme.de'], 'actor'));

    app(UpdateManagedUserAction::class)->execute($actor, $this->user, ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'ada.lovelace@acme.de']);

    expect($this->user->email)->toBe('ada@acme.de')
        ->and($this->user->pending_email)->toBe('ada.lovelace@acme.de');

    Notification::assertSentOnDemand(
        ConfirmEmailChangeNotification::class,
        static fn (ConfirmEmailChangeNotification $notification, array $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routes === ['mail' => 'ada.lovelace@acme.de'],
    );
});

it('corrects the address of a still invited account directly without a confirmation', function (): void {
    app()->instance(AuthorizationDirectory::class, new FakeAuthorizationDirectory);
    app()->instance(TeamMembershipDirectory::class, new FakeTeamMembershipDirectory);
    AccessContext::grant();
    GateSpy::allowing('updatePassword');
    $actor = AccessContext::actAs(AccessContext::user($this->tenant, ['email' => 'chefin@acme.de'], 'actor'));
    $invitee = AccessContext::user($this->tenant, ['email' => 'grce@acme.de', 'status' => UserStatus::Invited->value], 'invitee');

    app(UpdateManagedUserAction::class)->execute($actor, $invitee, ['first_name' => 'Grace', 'last_name' => 'Hopper', 'email' => 'grace.hopper@acme.de']);

    expect($invitee->email)->toBe('grace.hopper@acme.de')
        ->and($invitee->pending_email)->toBeNull();

    Notification::assertNothingSent();
});

it('takes the confirmed address over as verified, clears the request and tells the old address', function (): void {
    ($this->pending)();

    app(ConfirmEmailChangeAction::class)->execute($this->user, 'the-secret-token');

    expect($this->user->email)->toBe('ada.lovelace@acme.de')
        ->and($this->user->email_verified_at?->toDateTimeString())->toBe('2026-11-10 09:00:00')
        ->and($this->user->pending_email)->toBeNull()
        ->and($this->user->pending_email_token_hash)->toBeNull()
        ->and($this->user->pending_email_expires_at)->toBeNull()
        ->and(collect($this->presence->lookups)->where('table', 'users')->pluck('value')->all())->toBe(['ada.lovelace@acme.de']);

    Notification::assertSentOnDemand(
        EmailAddressChangedNotification::class,
        static fn (EmailAddressChangedNotification $notification, array $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routes === ['mail' => 'ada@acme.de'],
    );
});

it('refuses to confirm a wrong, expired or missing request and changes nothing', function (array $attributes, string $token): void {
    ($this->pending)($attributes);

    expect(fn () => app(ConfirmEmailChangeAction::class)->execute($this->user, $token))
        ->toThrow(EmailChangeNotConfirmableException::class)
        ->and($this->user->email)->toBe('ada@acme.de')
        ->and($this->connection->writtenStatements)->toBe([]);

    Notification::assertNothingSent();
})->with([
    'wrong token' => [[], 'another-token'],
    'expired' => [['pending_email_expires_at' => '2026-11-10 08:59:59'], 'the-secret-token'],
    'no request' => [['pending_email' => null, 'pending_email_token_hash' => null, 'pending_email_expires_at' => null], 'the-secret-token'],
]);

it('refuses on the email field to confirm an address that another account took meanwhile', function (): void {
    ($this->pending)(['pending_email' => 'grace@acme.de']);

    try {
        app(ConfirmEmailChangeAction::class)->execute($this->user, 'the-secret-token');
        $this->fail('The confirmation was not refused.');
    } catch (ValidationException $exception) {
        expect(array_keys($exception->errors()))->toBe(['email']);
    }

    expect($this->user->email)->toBe('ada@acme.de')
        ->and($this->connection->writtenStatements)->toBe([]);

    Notification::assertNothingSent();
});

it('refuses on the email field to confirm an address the enforced single sign-on no longer allows', function (): void {
    ($this->pending)();
    $this->ssoReleases = true;

    expect(fn () => app(ConfirmEmailChangeAction::class)->execute($this->user, 'the-secret-token'))
        ->toThrow(ValidationException::class)
        ->and($this->user->email)->toBe('ada@acme.de');
});

it('words the confirmation mail in German and links the token', function (): void {
    $mail = (new ConfirmEmailChangeNotification('the-secret-token', Carbon::parse('2026-11-11 09:00:00')))->toMail(new AnonymousNotifiable);

    expect($mail->subject)->toBe('Bestätigen Sie Ihre neue E-Mail-Adresse')
        ->and($mail->actionText)->toBe('Neue E-Mail-Adresse bestätigen')
        ->and($mail->actionUrl)->toEndWith('/email-change/the-secret-token')
        ->and(implode(' ', $mail->introLines))->toContain('Sie oder Ihre Administration')
        ->and(implode(' ', $mail->outroLines))->toContain('11.11.2026');
});

it('lets only a signed in account open or submit the confirmation', function (string $route): void {
    expect(RouteShape::named($route)->hasDeclaredMiddleware('auth'))->toBeTrue()
        ->and(RouteShape::named($route)->hasDeclaredMiddleware('verified'))->toBeFalse()
        ->and(RouteShape::named($route)->resolvedMiddleware())->toContain(Authenticate::class);
})->with(['email-change.show', 'email-change.confirm']);

it('throttles the confirmation submission', function (): void {
    expect(RouteShape::named('email-change.confirm')->hasDeclaredMiddleware('throttle:6,1'))->toBeTrue();
});

it('answers 404 when the signed in account holds no open request for the token', function (): void {
    ($this->pending)();
    $request = Request::create('/email-change/another-token');
    $request->setUserResolver(fn (): User => $this->user);

    expect(fn () => app(EmailChangeConfirmationController::class)->show($request, 'another-token'))
        ->toThrow(NotFoundHttpException::class);
});

it('shows the requested address on the confirmation page of the signed in account', function (): void {
    ($this->pending)();
    $request = Request::create('/email-change/the-secret-token');
    $request->setUserResolver(fn (): User => $this->user);

    $response = app(EmailChangeConfirmationController::class)->show($request, 'the-secret-token');

    expect($response)->toBeInstanceOf(InertiaResponse::class)
        ->and((new ReflectionProperty(InertiaResponse::class, 'component'))->getValue($response))->toBe('auth/ConfirmEmailChange')
        ->and((new ReflectionProperty(InertiaResponse::class, 'props'))->getValue($response))->toMatchArray(['token' => 'the-secret-token', 'email' => 'ada.lovelace@acme.de']);
});

it('hands the open request to the profile page and leaves an expired one out', function (string $expiresAt, ?string $expected): void {
    ($this->pending)(['pending_email_expires_at' => $expiresAt]);
    $request = Request::create('/settings/profile');
    $request->setUserResolver(fn (): User => $this->user);
    $request->setLaravelSession(app('session.store'));

    $response = app(ProfilesController::class)->edit($request);

    expect((new ReflectionProperty(InertiaResponse::class, 'props'))->getValue($response)['pendingEmail'])->toBe($expected);
})->with([
    'open' => ['2026-11-10 20:00:00', 'ada.lovelace@acme.de'],
    'expired' => ['2026-11-10 08:00:00', null],
]);

it('adds the pending address columns to the users table behind the invitation columns and indexes the token hash', function (): void {
    StaticQueryConnection::uninstall();
    $schema = SchemaShape::ofMigration('database/migrations/0001_01_01_000000_create_users_table.php');
    $columns = $schema->columnsOf('users');
    $acceptedAt = array_search('invitation_accepted_at', $columns, true);

    expect(array_slice($columns, (int) $acceptedAt + 1, 3))->toBe(['pending_email', 'pending_email_token_hash', 'pending_email_expires_at'])
        ->and($schema->columnDefinition('users', 'pending_email'))->toContain('varchar(255) null')
        ->and($schema->columnDefinition('users', 'pending_email_expires_at'))->toContain('timestamp(0) without time zone null')
        ->and($schema->has('create index "users_pending_email_token_hash_index" on "users" ("pending_email_token_hash")'))->toBeTrue();
});

it('keeps the token hash of a pending address out of every serialised user', function (): void {
    expect(($this->pending)()->toArray())->not->toHaveKey('pending_email_token_hash');
});
