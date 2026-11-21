<?php

declare(strict_types=1);

use App\Actions\Api\RotateApiTokenAction;
use App\Support\Engine\ObjectTypeRegistry;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\NewAccessToken;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\FakeFieldVisibilityResolver;
use Tests\Support\Doubles\StaticObjectTypeRegistry;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->serviceUser = AccessContext::user($this->tenant, ['is_service' => true], 'service-user');
    $this->actor = AccessContext::user($this->tenant, [], 'token-admin');
    $this->tokenId = '42';
    $this->expiresAt = now()->addMonth()->startOfSecond();

    $this->tokenRow = [
        'id' => 42,
        'tokenable_type' => $this->serviceUser->getMorphClass(),
        'tokenable_id' => (string) $this->serviceUser->getKey(),
        'name' => 'nightly-import',
        'token' => str_repeat('c', 64),
        'abilities' => '["companies:read","companies:write"]',
        'expires_at' => $this->expiresAt->toDateTimeString(),
    ];

    /** @var callable(list<array<string, mixed>>):StaticQueryConnection */
    $this->answering = static fn (array $rows): StaticQueryConnection => StaticQueryConnection::install(
        static fn (string $sql): array => str_contains($sql, 'returning') ? [['id' => 43]] : $rows,
        static fn (): int => 1,
    );

    app()->instance(ObjectTypeRegistry::class, new StaticObjectTypeRegistry([], [], ['companies']));
    new FakeFieldVisibilityResolver;
    AccessContext::unrestrictedRowAccess();
    AccessContext::grant('companies.view', 'companies.create', 'companies.update', 'companies.delete');
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    AccessContext::forgetTenant();
});

it('refuses to rotate a token the service user does not own', function (): void {
    $connection = ($this->answering)([]);

    expect(fn (): NewAccessToken => app(RotateApiTokenAction::class)->execute($this->actor, $this->serviceUser, $this->tokenId))
        ->toThrow(ModelNotFoundException::class);

    expect($connection->queries[0]['bindings'])->toContain((string) $this->serviceUser->getKey())
        ->and($connection->writtenStatements)->toBe([]);
});

it('carries the name, the abilities and the deadline of the replaced token over', function (): void {
    ($this->answering)([$this->tokenRow]);

    $rotated = app(RotateApiTokenAction::class)->execute($this->actor, $this->serviceUser, $this->tokenId);

    expect($rotated->accessToken->name)->toBe('nightly-import')
        ->and($rotated->accessToken->abilities)->toBe(['companies:read', 'companies:write'])
        ->and($rotated->accessToken->expires_at->toDateTimeString())->toBe($this->expiresAt->toDateTimeString());
});

it('withdraws the replaced token before it mints the successor', function (): void {
    $connection = ($this->answering)([$this->tokenRow]);

    $rotated = app(RotateApiTokenAction::class)->execute($this->actor, $this->serviceUser, $this->tokenId);

    expect($connection->writtenStatements[0]['sql'])->toContain('delete from "personal_access_tokens"')
        ->and($connection->writtenStatements[0]['bindings'])->toContain(42)
        ->and($rotated->plainTextToken)->not->toContain($this->tokenRow['token']);
});

it('refuses to reissue a token whose abilities exceed the permissions of the rotating actor', function (array $granted): void {
    $connection = ($this->answering)([$this->tokenRow]);
    AccessContext::grant(...$granted);

    try {
        app(RotateApiTokenAction::class)->execute($this->actor, $this->serviceUser, $this->tokenId);
    } catch (ValidationException $exception) {
        expect($exception->errors())->not->toBe([])
            ->and($connection->writtenStatements)->toBe([]);

        return;
    }

    $this->fail('the rotation handed out a secret beyond the rights of the rotating actor');
})->with([
    'no rights at all' => [[]],
    'read only' => [['companies.view']],
    'write without read' => [['companies.create', 'companies.update', 'companies.delete']],
]);

it('refuses to reissue a token on an object type whose fields or rows are restricted for the rotating actor', function (Closure $restrict): void {
    $connection = ($this->answering)([$this->tokenRow]);
    $restrict();

    expect(fn (): NewAccessToken => app(RotateApiTokenAction::class)->execute($this->actor, $this->serviceUser, $this->tokenId))
        ->toThrow(ValidationException::class)
        ->and($connection->writtenStatements)->toBe([]);
})->with([
    'hidden field' => [fn (): FakeFieldVisibilityResolver => new FakeFieldVisibilityResolver(['salary'])],
    'read-only field' => [fn (): FakeFieldVisibilityResolver => new FakeFieldVisibilityResolver([], ['salary'])],
    'team row rule' => [fn (): mixed => AccessContext::rowAccessRules([
        ModelStub::ulid('object-type-companies') => [[['field' => 'region', 'operator' => 'equals', 'value' => 'north']]],
    ])],
]);

it('binds the successor of a service token to the actor who rotated it', function (): void {
    $connection = ($this->answering)([$this->tokenRow]);

    app(RotateApiTokenAction::class)->execute($this->actor, $this->serviceUser, $this->tokenId);

    $binding = collect($connection->writtenStatements)
        ->first(static fn (array $statement): bool => str_starts_with($statement['sql'], 'update "personal_access_tokens"'));

    expect($binding)->not->toBeNull()
        ->and($binding['sql'])->toContain('"creator_id" = ?')
        ->and($binding['bindings'])->toBe([(string) $this->actor->getKey(), $binding['bindings'][1], 43]);
});
