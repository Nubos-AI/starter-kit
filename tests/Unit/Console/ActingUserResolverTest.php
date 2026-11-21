<?php

declare(strict_types=1);

use App\Exceptions\ConfigBundle\MissingActingUserException;
use App\Support\Console\ActingUserResolver;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

it('refuses a missing or malformed acting user in german without ever reaching the database', function (?string $identifier, string $expectedReason, string $expectedMessage): void {
    $caught = null;

    $shape = QueryShape::attemptedBy(function () use ($identifier, &$caught): void {
        try {
            app(ActingUserResolver::class)->resolve($identifier);
        } catch (MissingActingUserException $exception) {
            $caught = $exception;
        }
    });

    expect($caught)->toBeInstanceOf(MissingActingUserException::class)
        ->and($caught?->reason)->toBe($expectedReason)
        ->and($caught?->getMessage())->toBe($expectedMessage)
        ->and($shape)->toBeNull();
})->with([
    'no identifier at all' => [null, 'acting-user-missing', 'The --as-user option is required.'],
    'an empty identifier' => ['', 'acting-user-missing', 'The --as-user option is required.'],
    'a malformed identifier' => ['not-an-email-address', 'acting-user-malformed', 'The --as-user option expects an e-mail address or a ULID.'],
    'an identifier that is neither a ulid nor an e-mail address' => ['not-a-ulid-and-not-an-email', 'acting-user-malformed', 'The --as-user option expects an e-mail address or a ULID.'],
]);

it('refuses an acting user the users table does not carry and names him unknown in german', function (): void {
    $connection = StaticQueryConnection::install(static fn (): array => []);

    $caught = null;

    try {
        app(ActingUserResolver::class)->resolve('nobody@example.test');
    } catch (MissingActingUserException $exception) {
        $caught = $exception;
    }

    StaticQueryConnection::uninstall();

    expect($caught)->toBeInstanceOf(MissingActingUserException::class)
        ->and($caught?->reason)->toBe('acting-user-unknown')
        ->and($caught?->getMessage())->toBe('The acting user given in --as-user is unknown.')
        ->and($connection->queries)->toHaveCount(1)
        ->and($connection->queries[0]['sql'])->toContain('"users"')
        ->and($connection->queries[0]['bindings'])->toContain('nobody@example.test');
});
