<?php

declare(strict_types=1);

use App\Enums\Webhooks\WebhookEventType;
use App\Models\WebhookSubscription;
use App\Support\Tenancy\TenantBinder;
use App\Support\Webhooks\WebhookChangeDispatcher;
use App\Support\Webhooks\WebhookDeliveryStarter;
use App\Support\Webhooks\WebhookSubscriptionMatcher;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->tenantId = (string) $this->tenant->getKey();

    $tenants = Mockery::mock(TenantBinder::class);
    $tenants->shouldReceive('runIfKnown')->andReturnUsing(static fn (string $tenantId, Closure $work): mixed => $work());
    app()->instance(TenantBinder::class, $tenants);

    $this->subscription = fn (string $seed): WebhookSubscription => ModelStub::make(WebhookSubscription::class, [
        'id' => ModelStub::ulid($seed),
        'tenant_id' => $this->tenantId,
        'object_type_id' => ModelStub::ulid('deals'),
    ]);

    $this->matcher = Mockery::mock(WebhookSubscriptionMatcher::class);
    $this->matcher->shouldReceive('match')->andReturn(new EloquentCollection([
        ($this->subscription)('failing-subscription'),
        ($this->subscription)('healthy-subscription'),
    ]));

    $this->starter = Mockery::mock(WebhookDeliveryStarter::class);

    $tenantId = $this->tenantId;
    $this->connection = StaticQueryConnection::install(
        static fn (string $sql): array => match (true) {
            str_contains($sql, 'from "outbox_events"') => [[
                'id' => ModelStub::ulid('outbox'),
                'tenant_id' => $tenantId,
                'sequence' => 7,
                'created_at' => '2026-09-01 08:00:00',
            ]],
            str_contains($sql, 'from "custom_records"') => [[
                'id' => ModelStub::ulid('deal'),
                'tenant_id' => $tenantId,
                'object_type_id' => ModelStub::ulid('deals'),
                'version' => 2,
            ]],
            default => [],
        },
    );
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    AccessContext::forgetTenant();
});

it('still starts the sibling subscriptions and then reports a failed start so the change is retried', function (): void {
    $started = [];
    $outage = new RuntimeException('temporal unreachable');

    $this->starter->shouldReceive('start')
        ->twice()
        ->andReturnUsing(function (WebhookSubscription $subscription) use (&$started, $outage): void {
            $started[] = (string) $subscription->getKey();

            if ($subscription->getKey() === ModelStub::ulid('failing-subscription')) {
                throw $outage;
            }
        });

    $dispatcher = new WebhookChangeDispatcher($this->matcher, $this->starter);

    expect(fn () => $dispatcher->dispatch($this->tenantId, ModelStub::ulid('deals'), ModelStub::ulid('deal'), 2, 7, ['title']))
        ->toThrow(RuntimeException::class, 'temporal unreachable')
        ->and($started)->toBe([ModelStub::ulid('failing-subscription'), ModelStub::ulid('healthy-subscription')]);
});

it('finishes quietly once every subscription has been started', function (): void {
    $this->starter->shouldReceive('start')
        ->twice()
        ->withArgs(static fn (WebhookSubscription $subscription, mixed $record, WebhookEventType $type): bool => $type === WebhookEventType::RecordUpdated);

    $dispatcher = new WebhookChangeDispatcher($this->matcher, $this->starter);

    $dispatcher->dispatch($this->tenantId, ModelStub::ulid('deals'), ModelStub::ulid('deal'), 2, 7, ['title']);
});
