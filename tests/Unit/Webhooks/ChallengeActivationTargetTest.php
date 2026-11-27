<?php

declare(strict_types=1);

use App\Actions\Webhooks\ActivateSubscriptionViaChallengeAction;
use App\Models\WebhookSubscription;
use App\Support\Webhooks\WebhookEgressClient;
use App\Support\Webhooks\WebhookSignature;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\Client\Response;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();

    $encrypter = new Encrypter(str_repeat('w', 32), 'aes-256-cbc');
    WebhookSubscription::encryptUsing($encrypter);

    $this->subscription = ModelStub::make(WebhookSubscription::class, [
        'id' => ModelStub::ulid('subscription'),
        'tenant_id' => $this->tenant->getKey(),
        'target_url' => 'https://checked.example.test/inbox',
        'secret' => $encrypter->encryptString('signing-secret'),
    ]);
});

afterEach(function (): void {
    WebhookSubscription::encryptUsing(null);
    AccessContext::forgetTenant();
    Mockery::close();
});

it('activates a subscription only while it still points at the target that answered the challenge', function (): void {
    $egress = Mockery::mock(WebhookEgressClient::class);
    $egress->shouldReceive('post')
        ->with('https://checked.example.test/inbox', Mockery::type('string'), Mockery::type('array'), null)
        ->andReturnUsing(fn (string $url, string $rawBody): Response => new Response(new PsrResponse(200, ['Content-Type' => 'application/json'], $rawBody)));

    $signature = Mockery::mock(WebhookSignature::class);
    $signature->shouldReceive('sign')->andReturn('signature');

    $action = new ActivateSubscriptionViaChallengeAction($egress, $signature);

    $activation = QueryShape::attemptedBy(fn () => $action->execute($this->subscription));

    expect($activation)->not->toBeNull()
        ->and($activation?->sql)->toStartWith('update "webhook_subscriptions" set "status" = ?')
        ->and($activation?->sql)->toContain(' and "target_url" = ?')
        ->and($activation?->hasBinding('https://checked.example.test/inbox'))->toBeTrue()
        ->and($activation?->isScopedToTenant('webhook_subscriptions', (string) $this->tenant->getKey()))->toBeTrue();
});
