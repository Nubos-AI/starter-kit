<?php

declare(strict_types=1);

use App\Actions\Api\RotateApiTokenAction;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laravel\Sanctum\NewAccessToken;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->serviceUser = AccessContext::user($this->tenant, [], 'service-user');
    $this->tokenId = '42';
    $this->expiresAt = now()->addMonth()->startOfSecond();

    $this->tokenRow = [
        'id' => 42,
        'tokenable_type' => $this->serviceUser->getMorphClass(),
        'tokenable_id' => (string) $this->serviceUser->getKey(),
        'name' => 'nightly-import',
        'token' => str_repeat('c', 64),
        'abilities' => '["records:read","records:write"]',
        'expires_at' => $this->expiresAt->toDateTimeString(),
    ];

    /** @var callable(list<array<string, mixed>>):StaticQueryConnection */
    $this->answering = static fn (array $rows): StaticQueryConnection => StaticQueryConnection::install(
        static fn (string $sql): array => str_contains($sql, 'returning') ? [['id' => 43]] : $rows,
        static fn (): int => 1,
    );
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    AccessContext::forgetTenant();
});

it('refuses to rotate a token the service user does not own', function (): void {
    $connection = ($this->answering)([]);

    expect(fn (): NewAccessToken => app(RotateApiTokenAction::class)->execute($this->serviceUser, $this->tokenId))
        ->toThrow(ModelNotFoundException::class);

    expect($connection->queries[0]['bindings'])->toContain((string) $this->serviceUser->getKey())
        ->and($connection->writtenStatements)->toBe([]);
});

it('carries the name, the abilities and the deadline of the replaced token over', function (): void {
    ($this->answering)([$this->tokenRow]);

    $rotated = app(RotateApiTokenAction::class)->execute($this->serviceUser, $this->tokenId);

    expect($rotated->accessToken->name)->toBe('nightly-import')
        ->and($rotated->accessToken->abilities)->toBe(['records:read', 'records:write'])
        ->and($rotated->accessToken->expires_at->toDateTimeString())->toBe($this->expiresAt->toDateTimeString());
});

it('withdraws the replaced token before it mints the successor', function (): void {
    $connection = ($this->answering)([$this->tokenRow]);

    $rotated = app(RotateApiTokenAction::class)->execute($this->serviceUser, $this->tokenId);

    expect($connection->writtenStatements[0]['sql'])->toContain('delete from "personal_access_tokens"')
        ->and($connection->writtenStatements[0]['bindings'])->toContain(42)
        ->and($rotated->plainTextToken)->not->toContain($this->tokenRow['token']);
});
