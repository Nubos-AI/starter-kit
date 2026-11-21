<?php

declare(strict_types=1);

use App\Actions\Scim\CreateScimUserAction;
use App\Actions\Scim\UpdateScimUserAction;
use App\Actions\Users\UpdateProfileAction;
use App\Actions\Users\UpdateUserAction;
use App\DTOs\Scim\ScimUserChanges;
use App\Enums\Users\UserStatus;
use App\Models\ScimToken;
use App\Models\SsoConnection;
use App\Models\SsoVerifiedDomain;
use App\Models\User;
use App\Support\Scim\ScimAccessGuard;
use App\Support\Sso\SsoConnectionLookup;
use App\Support\Sso\SsoUserDirectory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\FakePresenceVerifier;
use Tests\Support\Doubles\StaticAuthorityUser;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\SchemaShape;
use Tests\Support\WithoutDatabase;
use Tests\Support\WriteAttempt;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant('acme');

    $lookup = Mockery::mock(SsoConnectionLookup::class);
    $lookup->shouldReceive('forTenant')->andReturnNull();
    app()->instance(SsoConnectionLookup::class, $lookup);

    $this->user = ModelStub::make(StaticAuthorityUser::class, [
        'id' => ModelStub::ulid('ada'),
        'tenant_id' => (string) $this->tenant->getKey(),
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'email' => 'ada@acme.de',
    ]);
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('stores every email address trimmed and lower case whichever path writes it', function (): void {
    $filled = (new User)->fill(['email' => '  Grace.Hopper@ACME.de ']);
    $forced = (new User)->forceFill(['email' => 'Grace.Hopper@Acme.DE']);

    expect($filled->email)->toBe('grace.hopper@acme.de')
        ->and($forced->email)->toBe('grace.hopper@acme.de');
});

it('refuses an invitee address that only differs in case from the address of another user', function (): void {
    FakePresenceVerifier::holding(['users' => ['grace@acme.de']]);
    $this->user->setRawAttributes([...$this->user->getAttributes(), 'status' => UserStatus::Invited->value], true);

    $payload = ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'GRACE@Acme.de'];

    expect(fn () => app(UpdateUserAction::class)->execute($this->user, $payload))
        ->toThrow(ValidationException::class);
});

it('remembers a requested profile address in its normalised form without asking whether another account holds it', function (): void {
    Notification::fake();
    $verifier = FakePresenceVerifier::holding(['users' => []]);
    $this->user->setRawAttributes([...$this->user->getAttributes(), 'password' => Hash::make('current-secret-password')], true);
    AccessContext::actAs($this->user);

    WriteAttempt::reachedTheDatabase(fn () => app(UpdateProfileAction::class)->execute($this->user, [
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'email' => ' Ada.L@Acme.DE ',
        'current_password' => 'current-secret-password',
    ]));

    expect($this->user->pending_email)->toBe('ada.l@acme.de')
        ->and($this->user->email)->toBe('ada@acme.de')
        ->and(collect($verifier->lookups)->where('table', 'users')->all())->toBe([]);
});

it('looks a single sign on address up in its normalised form', function (): void {
    $query = QueryShape::attemptedBy(fn () => (new SsoUserDirectory)->byEmail(' Ada@ACME.de '));

    expect($query?->bindings)->toContain('ada@acme.de')
        ->and($query?->bindings)->not->toContain(' Ada@ACME.de ');
});

it('looks a scim address up in its normalised form before it creates the user', function (): void {
    AccessContext::grant('sso.manage');
    $lookup = Mockery::mock(SsoConnectionLookup::class);
    $lookup->shouldReceive('forTenant')->andReturn(ModelStub::make(SsoConnection::class, ['allowed_domains' => ['acme.de']], ['verifiedDomains' => ModelStub::collection(SsoVerifiedDomain::class, [['domain' => 'acme.de']])]));
    app()->instance(SsoConnectionLookup::class, $lookup);
    $creator = ModelStub::make(User::class, ['id' => ModelStub::ulid('helpdesk'), 'tenant_id' => (string) $this->tenant->getKey(), 'status' => UserStatus::Accepted->value]);

    $query = QueryShape::attemptedBy(fn () => app(CreateScimUserAction::class)->execute(
        ModelStub::make(ScimToken::class, ['tenant_id' => (string) $this->tenant->getKey()], ['creator' => $creator]),
        new ScimUserChanges(['email' => 'Ada@ACME.de']),
    ));

    expect($query?->sql)->toContain('"email" = ?')
        ->and($query?->bindings)->toContain('ada@acme.de');
});

it('treats a scim address that only differs in case from the current one as unchanged', function (): void {
    DB::shouldReceive('transaction')->once()->andReturn($this->user);
    $guard = Mockery::mock(ScimAccessGuard::class);
    $guard->shouldReceive('assertMaySteer')->once();
    app()->instance(ScimAccessGuard::class, $guard);

    $query = QueryShape::attemptedBy(fn () => app(UpdateScimUserAction::class)->execute(
        ModelStub::make(ScimToken::class, ['tenant_id' => (string) $this->tenant->getKey()]),
        $this->user,
        new ScimUserChanges(['email' => 'ADA@acme.de']),
    ));

    expect($query)->toBeNull();
});

it('guards the address uniqueness case insensitively in the database', function (): void {
    expect(SchemaShape::ofMigration('database/migrations/0001_01_01_000000_create_users_table.php')
        ->has('CREATE UNIQUE INDEX unq_users_email_lower ON users (lower(email))'))->toBeTrue();
});
