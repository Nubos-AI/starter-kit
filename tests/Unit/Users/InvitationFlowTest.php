<?php

declare(strict_types=1);

use App\Actions\Users\AcceptInvitationAction;
use App\Actions\Users\IssueInvitationAction;
use App\Enums\Users\Salutation;
use App\Enums\Users\UserStatus;
use App\Support\Users\PendingInvitationResolver;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\QueryShape;
use Tests\Support\RouteShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->token = str_repeat('a', 64);
    $this->hash = hash('sha256', $this->token);

    $this->invited = AccessContext::user($this->tenant, [
        'email' => 'invited@example.test',
        'status' => UserStatus::Invited->value,
    ], 'invited-user');

    /** @var array<string, mixed> */
    $this->acceptance = [
        'salutation' => Salutation::Mix->value,
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'password' => 'Str0ng-Passphrase!42',
        'password_confirmation' => 'Str0ng-Passphrase!42',
    ];
});

afterEach(function (): void {
    Str::createRandomStringsNormally();
    AccessContext::forgetTenant();
});

it('looks an invitation up by the hash of the token and never by the token itself', function (): void {
    $shape = QueryShape::attemptedBy(fn () => app(PendingInvitationResolver::class)->resolveOrFail($this->token));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('users'))->toBeTrue()
        ->and($shape->sql)->toContain('"invitation_token_hash" = ?')
        ->and($shape->hasBinding($this->hash))->toBeTrue()
        ->and($shape->hasBinding($this->token))->toBeFalse();
});

it('only accepts a lookup for a user who is still invited', function (): void {
    $shape = QueryShape::attemptedBy(fn () => app(PendingInvitationResolver::class)->resolveOrFail($this->token));

    expect($shape->hasBinding(UserStatus::Invited->value))->toBeTrue();
});

it('stores only the sha256 hash of the mailed token', function (): void {
    Str::createRandomStringsUsing(fn (int $length): string => str_repeat('a', $length));

    $shape = QueryShape::attemptedBy(fn () => app(IssueInvitationAction::class)->execute($this->invited, $this->invited));

    expect($shape)->not->toBeNull()
        ->and($shape->sql)->toContain('update "users"')
        ->and($shape->sql)->toContain('"invitation_token_hash"')
        ->and($shape->hasBinding($this->hash))->toBeTrue()
        ->and($shape->hasBinding($this->token))->toBeFalse();
});

it('gives the invitation the configured lifetime', function (): void {
    Str::createRandomStringsUsing(fn (int $length): string => str_repeat('a', $length));

    $expected = now()->addDays((int) config('users.invitation.ttl_days'));

    $shape = QueryShape::attemptedBy(fn () => app(IssueInvitationAction::class)->execute($this->invited, $this->invited));

    $dates = array_values(array_filter(
        $shape->bindings,
        static fn (mixed $binding): bool => is_string($binding) && str_starts_with($binding, $expected->format('Y-m-d')),
    ));

    expect($dates)->not->toBeEmpty();
});

it('consumes the invitation with a single conditional update', function (): void {
    $shape = QueryShape::attemptedBy(
        fn () => app(AcceptInvitationAction::class)->execute($this->invited, $this->acceptance),
    );

    expect($shape)->not->toBeNull()
        ->and($shape->sql)->toContain('update "users"')
        ->and($shape->isKeyedTo('users', (string) $this->invited->getKey()))->toBeTrue()
        ->and($shape->sql)->toContain('"invitation_token_hash" is not null')
        ->and($shape->sql)->toContain('"invitation_expires_at" >')
        ->and($shape->hasBinding(UserStatus::Invited->value))->toBeTrue();
});

it('clears the token hash while accepting so the link cannot be replayed', function (): void {
    $shape = QueryShape::attemptedBy(
        fn () => app(AcceptInvitationAction::class)->execute($this->invited, $this->acceptance),
    );

    $assignments = substr($shape->sql, 0, (int) strpos($shape->sql, ' where '));

    expect($assignments)->toContain('"invitation_token_hash" = ?')
        ->and($shape->bindings)->toContain(null)
        ->and($shape->hasBinding(UserStatus::Accepted->value))->toBeTrue();
});

it('refuses an acceptance that does not confirm the password', function (): void {
    expect(fn () => app(AcceptInvitationAction::class)->execute($this->invited, [
        ...$this->acceptance,
        'password_confirmation' => 'something-else',
    ]))->toThrow(ValidationException::class);
});

it('ignores an address smuggled into the acceptance payload', function (): void {
    $shape = QueryShape::attemptedBy(fn () => app(AcceptInvitationAction::class)->execute($this->invited, [
        ...$this->acceptance,
        'email' => 'attacker@example.test',
        'tenant_id' => 'a-foreign-tenant',
        'is_service' => true,
    ]));

    expect($shape->hasBinding('attacker@example.test'))->toBeFalse()
        ->and($shape->hasBinding('a-foreign-tenant'))->toBeFalse()
        ->and($shape->sql)->not->toContain('"email" = ?')
        ->and($shape->sql)->not->toContain('"tenant_id" = ?')
        ->and($shape->sql)->not->toContain('"is_service"');
});

it('keeps the acceptance on the invited row alone', function (): void {
    $stranger = AccessContext::user($this->tenant, ['email' => 'stranger@example.test'], 'stranger-user');

    $shape = QueryShape::attemptedBy(
        fn () => app(AcceptInvitationAction::class)->execute($this->invited, $this->acceptance),
    );

    expect($shape->hasBinding((string) $this->invited->getKey()))->toBeTrue()
        ->and($shape->hasBinding((string) $stranger->getKey()))->toBeFalse();
});

it('never hands the raw password to the database', function (): void {
    $shape = QueryShape::attemptedBy(
        fn () => app(AcceptInvitationAction::class)->execute($this->invited, $this->acceptance),
    );

    expect($shape->hasBinding('Str0ng-Passphrase!42'))->toBeFalse();
});

it('rate limits the public invitation acceptance route', function (): void {
    expect(RouteShape::named('invitations.accept')->hasDeclaredMiddleware('throttle:6,1'))->toBeTrue();
});

it('keeps the acceptance route free of a tenant guard so the token alone decides', function (): void {
    $declared = RouteShape::named('invitations.accept')->declaredMiddleware();

    expect(array_filter($declared, static fn (string $entry): bool => str_starts_with($entry, 'permission:')))->toBeEmpty()
        ->and(in_array('auth', $declared, true))->toBeFalse();
});
