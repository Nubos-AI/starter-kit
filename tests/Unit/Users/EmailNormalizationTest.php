<?php

declare(strict_types=1);

use App\Actions\Scim\CreateScimUserAction;
use App\Actions\Scim\UpdateScimUserAction;
use App\Actions\Users\UpdateProfileAction;
use App\Actions\Users\UpdateUserAction;
use App\DTOs\Scim\ScimUserChanges;
use App\Models\User;
use App\Support\Sso\SsoUserDirectory;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\FakePresenceVerifier;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\SchemaShape;
use Tests\Support\WithoutDatabase;
use Tests\Support\WriteAttempt;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant('acme');

    $this->user = ModelStub::make(User::class, [
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

it('refuses a profile address that only differs in case from the address of another user', function (string $actionClass): void {
    FakePresenceVerifier::holding(['users' => ['grace@acme.de']]);

    $payload = ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'GRACE@Acme.de'];

    expect(fn () => app($actionClass)->execute($this->user, $payload))
        ->toThrow(ValidationException::class);
})->with([
    'own profile' => UpdateProfileAction::class,
    'managed user' => UpdateUserAction::class,
]);

it('checks the uniqueness of a profile address against its normalised form', function (): void {
    $verifier = FakePresenceVerifier::holding(['users' => []]);

    WriteAttempt::reachedTheDatabase(fn () => app(UpdateProfileAction::class)->execute($this->user, [
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'email' => ' Ada.L@Acme.DE ',
    ]));

    expect(collect($verifier->lookups)->where('table', 'users')->pluck('value')->unique()->values()->all())
        ->toBe(['ada.l@acme.de']);
});

it('looks a single sign on address up in its normalised form', function (): void {
    $query = QueryShape::attemptedBy(fn () => (new SsoUserDirectory)->byEmail(' Ada@ACME.de '));

    expect($query?->bindings)->toContain('ada@acme.de')
        ->and($query?->bindings)->not->toContain(' Ada@ACME.de ');
});

it('looks a scim address up in its normalised form before it creates the user', function (): void {
    $query = QueryShape::attemptedBy(fn () => app(CreateScimUserAction::class)->execute(
        (string) $this->tenant->getKey(),
        new ScimUserChanges(['email' => 'Ada@ACME.de']),
    ));

    expect($query?->sql)->toContain('"email" = ?')
        ->and($query?->bindings)->toContain('ada@acme.de');
});

it('treats a scim address that only differs in case from the current one as unchanged', function (): void {
    DB::shouldReceive('transaction')->once()->andReturn($this->user);

    $query = QueryShape::attemptedBy(fn () => app(UpdateScimUserAction::class)->execute(
        $this->user,
        new ScimUserChanges(['email' => 'ADA@acme.de']),
    ));

    expect($query)->toBeNull();
});

it('guards the address uniqueness case insensitively in the database', function (): void {
    expect(SchemaShape::ofMigration('database/migrations/0001_01_01_000000_create_users_table.php')
        ->has('CREATE UNIQUE INDEX unq_users_email_lower ON users (lower(email))'))->toBeTrue();
});
