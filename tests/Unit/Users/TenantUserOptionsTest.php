<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\Users\TenantUserOptions;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\RouteShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->userOptions = app(TenantUserOptions::class);
    $this->actor = AccessContext::user($this->tenant, ['email' => 'actor@example.test'], 'options-actor');
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('offers only the users of the tenant the acting user belongs to', function (): void {
    $foreignTenantId = ModelStub::ulid('other-tenant');

    $shape = QueryShape::attemptedBy(fn () => $this->userOptions->forUser($this->actor));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('users'))->toBeTrue()
        ->and($shape->hasBinding((string) $this->tenant->getKey()))->toBeTrue()
        ->and($shape->hasBinding($foreignTenantId))->toBeFalse()
        ->and($shape->sql)->toContain('"tenant_id" = ?')
        ->and($shape->hidesSoftDeleted('users'))->toBeTrue();
});

it('leaves the service accounts out of the options', function (): void {
    $shape = QueryShape::attemptedBy(fn () => $this->userOptions->forUser($this->actor));

    expect($shape->sql)->toContain('"is_service" = ?')
        ->and($shape->hasBinding(0))->toBeTrue();
});

it('matches a search term against the name and the address and nothing else', function (): void {
    $shape = QueryShape::attemptedBy(fn () => $this->userOptions->forUser($this->actor, 'lovelace'));

    expect($shape->sql)->toContain('"first_name"::text ILIKE ?')
        ->and($shape->sql)->toContain('"last_name"::text ILIKE ?')
        ->and($shape->sql)->toContain('"email"::text ILIKE ?')
        ->and($shape->hasBinding('%lovelace%'))->toBeTrue()
        ->and(substr_count($shape->sql, 'ILIKE'))->toBe(3);
});

it('asks for no term at all when none was given', function (): void {
    $shape = QueryShape::attemptedBy(fn () => $this->userOptions->forUser($this->actor));

    expect($shape->sql)->not->toContain('ILIKE');
});

it('caps the option list and sorts it by last and first name', function (): void {
    $shape = QueryShape::attemptedBy(fn () => $this->userOptions->forUser($this->actor));

    expect($shape->sql)->toContain('order by "last_name" asc, "first_name" asc')
        ->and($shape->sql)->toContain('limit 50');
});

it('drops the cap when the whole tenant is asked for', function (): void {
    $shape = QueryShape::attemptedBy(fn () => $this->userOptions->allForUser($this->actor));

    expect($shape->sql)->not->toContain('limit');
});

it('asks the database nothing for a user without a tenant', function (): void {
    $homeless = ModelStub::make(User::class, [
        'id' => ModelStub::ulid('homeless-user'),
        'tenant_id' => null,
        'email' => 'homeless@example.test',
    ]);

    $shape = QueryShape::attemptedBy(fn () => expect($this->userOptions->allForUser($homeless))->toBe([]));

    expect($shape)->toBeNull();
});

it('presents a user with value label description and avatar', function (): void {
    $member = AccessContext::user($this->tenant, [
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'email' => 'ada@example.test',
    ], 'options-member');

    expect($this->userOptions->present(new EloquentCollection([$member])))->toBe([[
        'value' => (string) $member->getKey(),
        'label' => 'Ada Lovelace',
        'description' => 'ada@example.test',
        'avatar' => ['name' => 'Ada Lovelace'],
    ]]);
});

it('falls back to the address when a user carries no name yet', function (): void {
    $invitee = AccessContext::user($this->tenant, [
        'first_name' => null,
        'last_name' => null,
        'email' => 'invitee@example.test',
    ], 'options-invitee');

    expect($this->userOptions->present(new EloquentCollection([$invitee]))[0]['label'])
        ->toBe('invitee@example.test');
});

it('guards the option endpoint with the member view permission', function (): void {
    expect(RouteShape::named('engine.users.options')->hasDeclaredMiddleware('permission:members.view'))->toBeTrue();
});
