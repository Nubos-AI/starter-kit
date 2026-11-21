<?php

declare(strict_types=1);

use App\Actions\Users\RestoreUserAction;
use App\Enums\Users\UserStatus;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->connection = StaticQueryConnection::install(static fn (): array => [], static fn (): int => 1);

    $this->removed = AccessContext::user($this->tenant, [
        'status' => UserStatus::Deleted->value,
        'deleted_at' => '2026-10-20 08:00:00',
    ], 'removed-user');

    $this->writes = fn (): array => collect($this->connection->writtenStatements)
        ->map(static fn (array $statement): string => $statement['sql'])
        ->all();
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    AccessContext::forgetTenant();
});

it('takes a restored user out of every team it belonged to before it was removed', function (): void {
    app(RestoreUserAction::class)->execute($this->removed, UserStatus::Invited);

    $teamWrite = collect($this->connection->writtenStatements)
        ->first(static fn (array $statement): bool => str_starts_with($statement['sql'], 'delete from "team_user"'));

    expect($teamWrite)->not->toBeNull()
        ->and($teamWrite['sql'])->toContain('"team_user"."user_id" = ?')
        ->and($teamWrite['bindings'])->toContain(ModelStub::ulid('removed-user'));
});

it('clears the roles and permission overrides of a restored user as well', function (): void {
    app(RestoreUserAction::class)->execute($this->removed, UserStatus::Accepted);

    $sql = implode(' ', ($this->writes)());

    expect($sql)->toContain('delete from "role_assignments"')
        ->and($sql)->toContain('delete from "permission_overrides"');
});

it('wipes every credential a removed user left behind before the account comes back', function (): void {
    $this->removed->setRawAttributes([
        ...$this->removed->getAttributes(),
        'password' => 'hashed-old-password',
        'two_factor_secret' => 'old-secret',
        'two_factor_recovery_codes' => 'old-codes',
        'two_factor_confirmed_at' => '2026-10-01 08:00:00',
        'remember_token' => 'remembered',
    ]);

    app(RestoreUserAction::class)->execute($this->removed, UserStatus::Invited);

    expect($this->connection->writtenSqlOf('passkeys'))->toBe(['delete from "passkeys" where "passkeys"."user_id" = ? and "passkeys"."user_id" is not null'])
        ->and($this->connection->writtenSqlOf('user_identities'))->toHaveCount(1)
        ->and($this->connection->writtenSqlOf('user_identities')[0])->toStartWith('update "user_identities" set "deleted_at" = ?')
        ->and($this->connection->writtenSqlOf('push_subscriptions'))->toHaveCount(1)
        ->and($this->connection->writtenSqlOf('push_subscriptions')[0])->toStartWith('delete from "push_subscriptions"')
        ->and($this->connection->writtenSqlOf('personal_access_tokens'))->not->toBe([])
        ->and($this->connection->writtenSqlOf('sessions'))->toBe(['delete from "sessions" where "user_id" = ?'])
        ->and($this->removed->password)->toBeNull()
        ->and($this->removed->two_factor_secret)->toBeNull()
        ->and($this->removed->two_factor_recovery_codes)->toBeNull()
        ->and($this->removed->two_factor_confirmed_at)->toBeNull()
        ->and($this->removed->remember_token)->toBeNull()
        ->and($this->removed->status)->toBe(UserStatus::Invited);
});

it('removes only the single sign-on identities of the restored user, across tenants but never those of another user', function (): void {
    app(RestoreUserAction::class)->execute($this->removed, UserStatus::Invited);

    $identityWrite = collect($this->connection->writtenStatements)
        ->first(static fn (array $statement): bool => str_starts_with($statement['sql'], 'update "user_identities"'));

    expect($identityWrite)->not->toBeNull()
        ->and($identityWrite['sql'])->toEndWith('where "user_id" = ? and "user_identities"."deleted_at" is null')
        ->and(end($identityWrite['bindings']))->toBe(ModelStub::ulid('removed-user'));
});

it('rolls the credential wipe back together with the restore when the account cannot be saved', function (): void {
    StaticQueryConnection::uninstall();

    $connection = StaticQueryConnection::install(
        static fn (): array => [],
        static function (string $sql): int {
            if (str_starts_with($sql, 'update "users"') && str_contains($sql, '"status"')) {
                throw new RuntimeException('users row is locked');
            }

            return 1;
        },
    );

    expect(fn () => app(RestoreUserAction::class)->execute($this->removed, UserStatus::Invited))
        ->toThrow(RuntimeException::class, 'users row is locked');

    $rolledBack = collect($connection->rolledBackStatements)
        ->map(static fn (array $statement): string => $statement['sql'])
        ->implode(' ');

    expect($connection->openedTransactions)->toBe(1)
        ->and($rolledBack)->toContain('delete from "sessions"')
        ->and($rolledBack)->toContain('delete from "personal_access_tokens"')
        ->and($rolledBack)->toContain('delete from "passkeys"')
        ->and($rolledBack)->toContain('delete from "push_subscriptions"')
        ->and($rolledBack)->toContain('update "user_identities"');
});
