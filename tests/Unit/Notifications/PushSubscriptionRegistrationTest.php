<?php

declare(strict_types=1);

use App\Actions\Notifications\RegisterPushSubscriptionAction;
use App\Support\Webhooks\SsrfGuard;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant('push-tenant');
    $this->user = AccessContext::actAs(AccessContext::user($this->tenant, [], 'push-user'));
    $this->foreignRows = [];
    $this->connection = StaticQueryConnection::install(
        fn (string $sql): array => str_starts_with($sql, 'select "id", "public_key", "auth_token" from "push_subscriptions"') ? $this->foreignRows : [],
        static fn (): int => 1,
    );

    $this->browserKeys = [
        'public_key' => rtrim(strtr(base64_encode("\x04".str_repeat('k', 64)), '+/', '-_'), '='),
        'auth_token' => rtrim(strtr(base64_encode(str_repeat('a', 16)), '+/', '-_'), '='),
    ];

    $this->foreignOwner = function (array $keys): void {
        $this->foreignRows = [['id' => ModelStub::ulid('foreign-push-subscription'), ...$keys]];
    };

    $guard = Mockery::mock(SsrfGuard::class);
    $guard->shouldReceive('assertWebhookTargetAllowed');
    $this->action = new RegisterPushSubscriptionAction($guard);
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    AccessContext::forgetTenant();
    Mockery::close();
});

it('hands the endpoint of the same browser over from its previous owner in any tenant before it registers it for the user', function (): void {
    ($this->foreignOwner)($this->browserKeys);

    $this->action->execute($this->user, ['endpoint' => 'https://push.example/browser-1', ...$this->browserKeys]);

    $statements = $this->connection->writtenStatements;
    $lookup = collect($this->connection->queries)->first(static fn (array $query): bool => str_contains($query['sql'], 'from "push_subscriptions"'));
    $removal = collect($statements)->search(static fn (array $statement): bool => str_starts_with($statement['sql'], 'delete from "push_subscriptions"'));
    $insert = collect($statements)->search(static fn (array $statement): bool => str_starts_with($statement['sql'], 'insert into "push_subscriptions"'));

    expect($lookup['sql'])->toBe('select "id", "public_key", "auth_token" from "push_subscriptions" where "endpoint" = ? and not ("subscribable_type" = ? and "subscribable_id" = ?)')
        ->and($lookup['bindings'])->toBe(['https://push.example/browser-1', $this->user->getMorphClass(), (string) $this->user->getKey()])
        ->and($removal)->not->toBeFalse()
        ->and($insert)->not->toBeFalse()
        ->and($removal)->toBeLessThan($insert)
        ->and($statements[$removal]['sql'])->toBe('delete from "push_subscriptions" where "push_subscriptions"."id" in (?)')
        ->and($statements[$removal]['bindings'])->toBe([ModelStub::ulid('foreign-push-subscription')])
        ->and($this->connection->openedTransactions)->toBeGreaterThanOrEqual(1);
});

it('refuses to take over an endpoint another user registered with different browser keys and leaves that subscription alone', function (): void {
    ($this->foreignOwner)(['public_key' => $this->browserKeys['public_key'], 'auth_token' => rtrim(base64_encode(str_repeat('z', 16)), '=')]);

    expect(fn () => $this->action->execute($this->user, ['endpoint' => 'https://push.example/browser-1', ...$this->browserKeys]))
        ->toThrow(ValidationException::class);

    $kept = collect($this->connection->writtenStatements)
        ->reject(fn (array $statement): bool => in_array($statement, $this->connection->rolledBackStatements, true))
        ->filter(static fn (array $statement): bool => str_contains($statement['sql'], '"push_subscriptions"'));

    expect($kept)->toBeEmpty();
});

it('refuses to take over a foreign endpoint when the registration carries no browser keys at all', function (): void {
    ($this->foreignOwner)(['public_key' => null, 'auth_token' => null]);

    expect(fn () => $this->action->execute($this->user, ['endpoint' => 'https://push.example/browser-1']))
        ->toThrow(ValidationException::class);
});
