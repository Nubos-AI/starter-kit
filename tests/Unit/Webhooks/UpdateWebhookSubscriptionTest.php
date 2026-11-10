<?php

declare(strict_types=1);

use App\Actions\Webhooks\ActivateSubscriptionViaChallengeAction;
use App\Actions\Webhooks\UpdateWebhookSubscriptionAction;
use App\Enums\Webhooks\WebhookSubscriptionStatus;
use App\Models\WebhookSubscription;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    app()->setLocale('de');
    $this->tenant = AccessContext::tenant();
    $this->connection = StaticQueryConnection::install(static fn (): array => [], static fn (): int => 1);

    $this->activate = Mockery::mock(ActivateSubscriptionViaChallengeAction::class);
    $this->activate->shouldReceive('execute')->byDefault()->andReturn(WebhookSubscriptionStatus::Pending);
    $this->action = new UpdateWebhookSubscriptionAction($this->activate);

    $this->subscription = fn (): WebhookSubscription => ModelStub::make(WebhookSubscription::class, [
        'id' => ModelStub::ulid('subscription'),
        'tenant_id' => (string) $this->tenant->getKey(),
        'name' => 'Buchhaltung',
        'target_url' => 'https://hooks.example.test/in',
        'auth_username' => 'hook-user',
        'auth_password' => Crypt::encryptString('stored-basic-password'),
        'secret' => Crypt::encryptString('whsec_stored'),
        'status' => WebhookSubscriptionStatus::Active->value,
        'event_types' => '["record.created"]',
        'consecutive_failures' => 0,
    ]);

    $this->input = static fn (array $overrides = []): array => [
        'name' => 'Buchhaltung',
        'target_url' => 'https://hooks.example.test/in',
        'auth_username' => 'hook-user',
        'auth_password' => '',
        'event_types' => ['record.created'],
        ...$overrides,
    ];

    config()->set('webhooks.events', ['record.created']);
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    AccessContext::forgetTenant();
    Mockery::close();
});

it('refuses to carry the stored basic auth password over to a new target host', function (string $targetUrl): void {
    $subscription = ($this->subscription)();
    $this->activate->shouldReceive('execute')->never();

    try {
        $this->action->execute($subscription, ($this->input)(['target_url' => $targetUrl]));
    } catch (ValidationException $exception) {
        expect(array_keys($exception->errors()))->toBe(['auth_password'])
            ->and($exception->errors()['auth_password'][0])->not->toStartWith('i18n.')
            ->and($this->connection->writtenStatements)->toBe([]);

        return;
    }

    throw new RuntimeException('The stored password was carried over to a new host.');
})->with([
    'another host' => ['https://attacker.example.org/in'],
    'another port' => ['https://hooks.example.test:8443/in'],
    'another scheme' => ['http://hooks.example.test/in'],
]);

it('takes a newly entered password along to a new target host', function (): void {
    $subscription = ($this->subscription)();

    $this->action->execute($subscription, ($this->input)(['target_url' => 'https://other.example.org/in', 'auth_password' => 'fresh-password']));

    expect($subscription->auth_password)->toBe('fresh-password')
        ->and($subscription->target_url)->toBe('https://other.example.org/in');
});

it('drops the stored credentials when the new target host comes without a user name', function (): void {
    $subscription = ($this->subscription)();

    $this->action->execute($subscription, ($this->input)(['target_url' => 'https://other.example.org/in', 'auth_username' => '']));

    expect($subscription->auth_username)->toBeNull()
        ->and($subscription->auth_password)->toBeNull();
});

it('keeps the stored password while the target stays on the same host', function (string $targetUrl): void {
    $subscription = ($this->subscription)();

    $this->action->execute($subscription, ($this->input)(['target_url' => $targetUrl]));

    expect($subscription->auth_password)->toBe('stored-basic-password');
})->with([
    'another path' => ['https://hooks.example.test/other-path'],
    'the default port spelled out' => ['https://HOOKS.example.test:443/other-path'],
]);
