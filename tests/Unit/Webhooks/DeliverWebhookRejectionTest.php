<?php

declare(strict_types=1);

use App\Activities\Webhooks\DeliverWebhookActivity;
use App\DTOs\Webhooks\WebhookDeliveryData;
use App\Enums\Webhooks\DeliveryOutcome;
use App\Enums\Webhooks\WebhookSubscriptionStatus;
use App\Support\Tenancy\TenantBinder;
use App\Support\Webhooks\WebhookEgressClient;
use App\Support\Webhooks\WebhookFailureTracker;
use App\Support\Webhooks\WebhookSignature;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant('webhook-rejection-tenant');
    $this->subscriptionId = ModelStub::ulid('webhook-rejection-subscription');
    $tenantId = (string) $this->tenant->getKey();
    $subscriptionId = $this->subscriptionId;

    StaticQueryConnection::install(static fn (string $sql): array => str_contains($sql, 'from "webhook_subscriptions"') ? [[
        'id' => $subscriptionId,
        'tenant_id' => $tenantId,
        'target_url' => 'https://hooks.example.com/in',
        'status' => WebhookSubscriptionStatus::Active->value,
        'secret' => encrypt('webhook-test-signing-value', false),
        'consecutive_failures' => 0,
    ]] : []);

    $this->tracker = new class extends WebhookFailureTracker
    {
        /**
         * @var list<string>
         */
        public array $calls = [];

        public function reset(string $subscriptionId, string $tenantId): void
        {
            $this->calls[] = 'reset';
        }

        public function recordFailure(string $subscriptionId, string $tenantId): bool
        {
            $this->calls[] = 'failure';

            return false;
        }

        public function disablePermanently(string $subscriptionId, string $tenantId): bool
        {
            $this->calls[] = 'disable';

            return true;
        }
    };

    $binder = Mockery::mock(TenantBinder::class);
    $binder->shouldReceive('runIfKnown')->andReturnUsing(fn (string $id, callable $work): mixed => $work($this->tenant));

    $signature = Mockery::mock(WebhookSignature::class);
    $signature->shouldReceive('sign')->andReturn('signed');

    $this->activityAnswering = function (int $status) use ($binder, $signature): DeliverWebhookActivity {
        $egress = Mockery::mock(WebhookEgressClient::class);
        $egress->shouldReceive('post')->andReturn(new Response(new PsrResponse($status)));

        return new DeliverWebhookActivity($egress, $signature, $this->tracker, $binder);
    };

    $this->input = new WebhookDeliveryData($this->subscriptionId, $tenantId, ModelStub::ulid('webhook-rejection-event'), 'record.updated', 1, '{}');
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    AccessContext::forgetTenant();
});

it('counts a rejected delivery as a failure so a target that keeps refusing is disabled', function (int $status): void {
    $outcome = ($this->activityAnswering)($status)->deliverWebhook($this->input);

    expect($outcome)->toBe(DeliveryOutcome::Parked)
        ->and($this->tracker->calls)->toBe(['failure']);
})->with([
    'bad request' => [400],
    'unauthorized' => [401],
    'not found' => [404],
    'unprocessable' => [422],
]);

it('disables the subscription at once when the target reports that it is gone for good', function (): void {
    $outcome = ($this->activityAnswering)(410)->deliverWebhook($this->input);

    expect($outcome)->toBe(DeliveryOutcome::Parked)
        ->and($this->tracker->calls)->toBe(['disable']);
});

it('resets the failure count after a delivery succeeded', function (): void {
    expect(($this->activityAnswering)(204)->deliverWebhook($this->input))->toBe(DeliveryOutcome::Delivered)
        ->and($this->tracker->calls)->toBe(['reset']);
});

it('logs every terminal outcome of a delivery with the workflow run and without the target or the payload', function (int $status, string $outcome): void {
    Log::spy();

    ($this->activityAnswering)($status)->deliverWebhook($this->input);

    Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context) use ($outcome): bool {
        return $message === 'Webhook delivery finished.'
            && $context['outcome'] === $outcome
            && $context['subscription_id'] === $this->subscriptionId
            && array_key_exists('workflow_id', $context)
            && array_key_exists('run_id', $context)
            && !str_contains((string) json_encode($context), 'hooks.example.com')
            && !str_contains((string) json_encode($context), 'signed');
    })->once();
})->with([
    'delivered' => [204, 'delivered'],
    'parked' => [422, 'parked'],
]);

it('logs a delivery the target failed with a server error before it hands the failure to the retry', function (): void {
    Log::spy();

    expect(fn () => ($this->activityAnswering)(503)->deliverWebhook($this->input))->toThrow(RuntimeException::class);

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => $message === 'Webhook delivery failed.'
        && $context['subscription_id'] === $this->subscriptionId
        && $context['status'] === 503
        && array_key_exists('workflow_id', $context))->once();
});

it('logs a delivery skipped because the subscription is no longer active', function (): void {
    StaticQueryConnection::uninstall();
    StaticQueryConnection::install(static fn (): array => []);
    Log::spy();

    expect(($this->activityAnswering)(204)->deliverWebhook($this->input))->toBe(DeliveryOutcome::Skipped);

    Log::shouldHaveReceived('info')->withArgs(static fn (string $message, array $context): bool => $message === 'Webhook delivery finished.'
        && $context['outcome'] === 'skipped')->once();
});
