<?php

declare(strict_types=1);

use App\Actions\Webhooks\ActivateSubscriptionViaChallengeAction;
use App\Enums\Webhooks\WebhookSubscriptionStatus;
use App\Models\WebhookSubscription;
use App\Support\Webhooks\WebhookEgressClient;
use App\Support\Webhooks\WebhookSignature;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->connection = StaticQueryConnection::install(static fn (): array => [], static fn (): int => 1);

    $this->egress = Mockery::mock(WebhookEgressClient::class);
    $this->action = new ActivateSubscriptionViaChallengeAction($this->egress, new WebhookSignature);

    $this->subscription = ModelStub::make(WebhookSubscription::class, [
        'id' => ModelStub::ulid('challenged-subscription'),
        'tenant_id' => (string) $this->tenant->getKey(),
        'name' => 'Buchhaltung',
        'target_url' => 'https://hooks.example.test/in',
        'secret' => Crypt::encryptString('whsec_challenge'),
        'status' => WebhookSubscriptionStatus::Pending->value,
        'event_types' => '["record.created"]',
        'consecutive_failures' => 0,
    ]);
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    AccessContext::forgetTenant();
    Mockery::close();
});

it('sends no challenge while the surrounding transaction is still open and defers it to the commit', function (): void {
    $this->egress->shouldNotReceive('post');

    $status = DB::transaction(fn (): WebhookSubscriptionStatus => $this->action->execute($this->subscription));

    expect($status)->toBe(WebhookSubscriptionStatus::Pending)
        ->and($this->connection->deferredAfterCommit)->toHaveCount(1);
});

it('runs the deferred challenge against the target after the commit', function (): void {
    $this->egress->shouldReceive('post')->once()->with('https://hooks.example.test/in', Mockery::type('string'), Mockery::type('array'), null)
        ->andThrow(new ConnectionException('unreachable'));

    DB::transaction(fn (): WebhookSubscriptionStatus => $this->action->execute($this->subscription));

    foreach ($this->connection->deferredAfterCommit as $callback) {
        $callback();
    }

    expect($this->subscription->status)->toBe(WebhookSubscriptionStatus::Pending)
        ->and($this->subscription->last_error)->not->toBeNull();
});

it('sends the challenge right away when no transaction is open', function (): void {
    $this->egress->shouldReceive('post')->once()->andThrow(new ConnectionException('unreachable'));

    expect($this->action->execute($this->subscription))->toBe(WebhookSubscriptionStatus::Pending)
        ->and($this->connection->deferredAfterCommit)->toBe([]);
});
