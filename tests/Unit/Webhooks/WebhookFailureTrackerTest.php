<?php

declare(strict_types=1);

use App\Enums\Users\UserStatus;
use App\Enums\Webhooks\WebhookSubscriptionStatus;
use App\Support\Webhooks\WebhookFailureTracker;
use Illuminate\Support\Facades\Notification;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    config(['webhooks.auto_disable.consecutive_failure_threshold' => 3]);
    Notification::fake();

    $this->tenantId = ModelStub::ulid('webhook-tenant');
    $this->subscriptionId = ModelStub::ulid('webhook-subscription');
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
});

it('looks the administrators of the subscription tenant up although the delivery worker has no tenant bound', function (): void {
    AccessContext::forgetTenant();

    $tenantId = $this->tenantId;
    $subscriptionId = $this->subscriptionId;
    $connection = StaticQueryConnection::install(
        static fn (string $sql): array => str_contains($sql, 'from "webhook_subscriptions"') ? [[
            'id' => $subscriptionId,
            'tenant_id' => $tenantId,
            'target_url' => 'https://hooks.example.com/in',
            'status' => WebhookSubscriptionStatus::Active->value,
            'consecutive_failures' => 3,
            'service_user_id' => null,
        ]] : [],
        static fn (): int => 1,
    );

    expect((new WebhookFailureTracker)->recordFailure($subscriptionId, $tenantId))->toBeTrue();

    $lookup = collect($connection->queries)->first(static fn (array $query): bool => str_contains($query['sql'], 'from "role_assignments"'));
    $shape = new QueryShape((string) ($lookup['sql'] ?? ''), $lookup['bindings'] ?? []);

    expect($lookup)->not->toBeNull()
        ->and($shape->blocksEveryRow())->toBeFalse()
        ->and($shape->isScopedToTenant('roles', $tenantId))->toBeTrue();
});

it('disables an active subscription at once and tells the administrators, whatever its failure count', function (): void {
    AccessContext::forgetTenant();

    $tenantId = $this->tenantId;
    $subscriptionId = $this->subscriptionId;
    $connection = StaticQueryConnection::install(
        static fn (string $sql): array => str_contains($sql, 'from "webhook_subscriptions"') ? [[
            'id' => $subscriptionId,
            'tenant_id' => $tenantId,
            'target_url' => 'https://hooks.example.com/in',
            'status' => WebhookSubscriptionStatus::Active->value,
            'consecutive_failures' => 0,
            'service_user_id' => null,
        ]] : [],
        static fn (): int => 1,
    );

    expect((new WebhookFailureTracker)->disablePermanently($subscriptionId, $tenantId))->toBeTrue();

    $disable = collect($connection->writtenStatements)->first(static fn (array $write): bool => str_contains($write['sql'], '"status" = ?'));

    expect($disable)->not->toBeNull()
        ->and($disable['bindings'])->toContain(WebhookSubscriptionStatus::Disabled->value)
        ->and($disable['bindings'])->toContain(WebhookSubscriptionStatus::Active->value)
        ->and($disable['bindings'])->toContain($tenantId);
});

it('reports nothing disabled when the subscription was no longer active', function (): void {
    StaticQueryConnection::install(static fn (): array => [], static fn (): int => 0);

    expect((new WebhookFailureTracker)->disablePermanently($this->subscriptionId, $this->tenantId))->toBeFalse();
});

it('tells only active accounts about a disabled subscription and never a blocked administrator', function (): void {
    AccessContext::forgetTenant();

    $tenantId = $this->tenantId;
    $subscriptionId = $this->subscriptionId;
    $connection = StaticQueryConnection::install(
        static fn (string $sql): array => str_contains($sql, 'from "webhook_subscriptions"') ? [[
            'id' => $subscriptionId,
            'tenant_id' => $tenantId,
            'target_url' => 'https://hooks.example.com/in',
            'status' => WebhookSubscriptionStatus::Active->value,
            'consecutive_failures' => 0,
            'service_user_id' => ModelStub::ulid('webhook-service-user'),
        ]] : [],
        static fn (): int => 1,
    );

    (new WebhookFailureTracker)->disablePermanently($subscriptionId, $tenantId);

    $lookup = collect($connection->queries)->first(static fn (array $query): bool => str_contains($query['sql'], 'from "users"'));

    expect($lookup)->not->toBeNull()
        ->and($lookup['sql'])->toContain('"users"."status" = ?')
        ->and($lookup['bindings'])->toContain(UserStatus::Accepted->value, $tenantId);
});
