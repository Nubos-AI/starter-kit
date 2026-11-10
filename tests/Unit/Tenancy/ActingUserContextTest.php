<?php

declare(strict_types=1);

use App\Enums\Users\UserStatus;
use App\Models\User;
use App\Support\Tenancy\ActingUserContext;
use Illuminate\Auth\Access\AuthorizationException;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenantId = ModelStub::ulid('acme');
    $this->userId = ModelStub::ulid('ada');
    $this->userRow = ['id' => $this->userId, 'tenant_id' => $this->tenantId, 'status' => UserStatus::Accepted->value, 'email' => 'ada@acme.de'];

    $this->connection = StaticQueryConnection::install(fn (string $sql): array => match (true) {
        str_contains($sql, 'from "tenants"') => [['id' => $this->tenantId, 'name' => 'Acme']],
        str_contains($sql, 'from "users"') => $this->userRow === null ? [] : [$this->userRow],
        default => [],
    });

    $this->ran = [];
    $this->run = fn () => app(ActingUserContext::class)->run($this->tenantId, $this->userId, function (User $actingUser): void {
        $this->ran[] = (string) $actingUser->getKey();
    });
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
});

it('resolves the acting user only inside the tenant the work runs for', function (): void {
    ($this->run)();

    $lookup = collect($this->connection->queries)->first(static fn (array $query): bool => str_contains($query['sql'], 'from "users"'));
    $shape = new QueryShape($lookup['sql'], $lookup['bindings']);

    expect($this->ran)->toBe([$this->userId])
        ->and($shape->isScopedToTenant('users', $this->tenantId))->toBeTrue()
        ->and($shape->isKeyedTo('users', $this->userId))->toBeTrue();
});

it('resolves only an acting user who may still sign in so blocked or pending accounts never run background work', function (): void {
    ($this->run)();

    $lookup = collect($this->connection->queries)->first(static fn (array $query): bool => str_contains($query['sql'], 'from "users"'));
    $shape = new QueryShape($lookup['sql'], $lookup['bindings']);

    expect($shape->hasConjunctiveCondition('users', 'status'))->toBeTrue()
        ->and($shape->hasBinding(UserStatus::Accepted->value))->toBeTrue()
        ->and($shape->hasBinding(UserStatus::Blocked->value))->toBeFalse();
});

it('refuses background work for an acting user of another tenant', function (): void {
    $this->userRow = null;

    expect($this->run)->toThrow(AuthorizationException::class)
        ->and($this->ran)->toBe([]);
});
