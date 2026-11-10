<?php

declare(strict_types=1);

use App\Actions\Api\ProvisionServiceUserAction;
use App\Actions\Users\ConsumeInvitationAction;
use App\Actions\Users\UpdateProfileAction;
use App\Actions\Users\UpdateUserAction;
use App\Enums\Users\UserStatus;
use App\Models\User;
use App\Notifications\Users\ConfirmEmailChangeNotification;
use App\Notifications\Users\EmailAddressChangedNotification;
use App\Support\Sso\SsoConnectionLookup;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
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

    $lookup = Mockery::mock(SsoConnectionLookup::class);
    $lookup->shouldReceive('forTenant')->andReturnNull();
    app()->instance(SsoConnectionLookup::class, $lookup);

    FakePresenceVerifier::holding(['users' => []]);

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
    ];

    Notification::fake();
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    AccessContext::forgetTenant();
    Mockery::close();
});

it('treats every user account as one that must verify its email address', function (): void {
    expect(new User)->toBeInstanceOf(MustVerifyEmail::class);
});

it('keeps an unverified user out of the routes behind the verified middleware and lets a verified one through', function (?string $verifiedAt, bool $isLetThrough): void {
    $user = ModelStub::make(User::class, ['email_verified_at' => $verifiedAt]);
    $request = Request::create('/dashboard');
    $request->setUserResolver(static fn (): User => $user);

    $response = (new EnsureEmailIsVerified)->handle($request, static fn (): Response => new Response('passed'));

    expect($response instanceof RedirectResponse)->toBe(!$isLetThrough);
})->with([
    'unverified' => [null, false],
    'verified' => ['2026-10-01 08:00:00', true],
]);

it('marks an invitee verified when the emailed invitation is consumed', function (): void {
    $invitee = AccessContext::user($this->tenant, ['status' => UserStatus::Invited->value], 'invitee');
    $connection = StaticQueryConnection::install(
        static fn (): array => [['id' => ModelStub::ulid('invitee'), 'status' => UserStatus::Accepted->value]],
        static fn (): int => 1,
    );

    app(ConsumeInvitationAction::class)->execute($invitee, ['first_name' => 'Grace']);

    expect($connection->writtenSqlOf('users')[0])->toContain('"email_verified_at" = ?');
});

it('refuses on the current password field to change the own address without the current password', function (array $password): void {
    $updateUser = Mockery::mock(UpdateUserAction::class);
    $updateUser->shouldNotReceive('execute');
    app()->instance(UpdateUserAction::class, $updateUser);

    try {
        app(UpdateProfileAction::class)->execute($this->user, [...$this->profile, ...$password]);
        $this->fail('The address change was not refused.');
    } catch (ValidationException $exception) {
        expect(array_keys($exception->errors()))->toBe(['current_password']);
    }

    Notification::assertNothingSent();
})->with([
    'missing' => [[]],
    'wrong' => [['current_password' => 'not-the-password']],
]);

it('keeps the own address verified while a change with the current password only waits for the confirmation of the new address', function (): void {
    app(UpdateProfileAction::class)->execute($this->user, [...$this->profile, 'current_password' => 'current-secret-password']);

    expect($this->user->email)->toBe('ada@acme.de')
        ->and($this->user->hasVerifiedEmail())->toBeTrue();

    Notification::assertNotSentTo($this->user, VerifyEmail::class);
    Notification::assertSentOnDemand(
        ConfirmEmailChangeNotification::class,
        static fn (ConfirmEmailChangeNotification $notification, array $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routes === ['mail' => 'ada.lovelace@acme.de'],
    );
    Notification::assertSentOnDemandTimes(EmailAddressChangedNotification::class, 0);
});

it('writes the notice to the old address in German without naming the new address', function (): void {
    $mail = (new EmailAddressChangedNotification)->toMail(new AnonymousNotifiable);

    expect($mail->subject)->toBe('Die E-Mail-Adresse Ihres Kontos wurde geändert')
        ->and(implode(' ', $mail->introLines))->toContain('Falls Sie das nicht veranlasst haben')
        ->and(implode(' ', $mail->introLines))->not->toContain('ada.lovelace@acme.de');
});

it('saves a profile without an address change without asking for the password and without mail', function (): void {
    app(UpdateProfileAction::class)->execute($this->user, [...$this->profile, 'email' => ' ADA@acme.de ', 'first_name' => 'Augusta']);

    expect($this->user->first_name)->toBe('Augusta')
        ->and($this->user->hasVerifiedEmail())->toBeTrue();

    Notification::assertNothingSent();
});

it('sends neither verification nor notice when the address of a still invited user changes', function (): void {
    $invitee = AccessContext::user($this->tenant, ['email' => 'grace@acme.de', 'status' => UserStatus::Invited->value], 'invitee');

    app(UpdateUserAction::class)->execute($invitee, ['first_name' => 'Grace', 'last_name' => 'Hopper', 'email' => 'grace.hopper@acme.de']);

    expect($invitee->email)->toBe('grace.hopper@acme.de');

    Notification::assertNothingSent();
});

it('words the verification mail in German', function (): void {
    $mail = (new VerifyEmail)->toMail($this->user);

    expect($mail->subject)->toBe('Bestätigen Sie Ihre E-Mail-Adresse')
        ->and($mail->actionText)->toBe('E-Mail-Adresse bestätigen')
        ->and($mail->introLines)->toBe(['Bitte klicken Sie auf die Schaltfläche, um Ihre E-Mail-Adresse zu bestätigen.']);
});

it('words the password reset mail in German', function (): void {
    $mail = (new ResetPassword('reset-token'))->toMail($this->user);

    expect($mail->subject)->toBe('Setzen Sie Ihr Passwort zurück')
        ->and($mail->actionText)->toBe('Passwort zurücksetzen')
        ->and($mail->introLines)->toBe(['Sie erhalten diese E-Mail, weil für Ihr Konto das Zurücksetzen des Passworts angefordert wurde.'])
        ->and($mail->outroLines[1])->toBe('Falls Sie das Zurücksetzen nicht angefordert haben, müssen Sie nichts weiter tun.');
});

it('provisions the API service identity with a verified address', function (): void {
    app(ProvisionServiceUserAction::class)->execute($this->tenant);

    $insert = collect($this->connection->writtenStatements)
        ->first(static fn (array $statement): bool => str_starts_with($statement['sql'], 'insert into "users"'));

    expect($insert)->not->toBeNull()
        ->and($insert['sql'])->toContain('"email_verified_at"');
});
