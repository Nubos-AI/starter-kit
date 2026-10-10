<?php

declare(strict_types=1);

use App\Enums\Webhooks\WebhookEventType;
use App\Enums\Webhooks\WebhookSubscriptionStatus;
use App\Support\Webhooks\WebhookSubscriptionMatcher;
use Illuminate\Database\Eloquent\Collection;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenantId = ModelStub::ulid('tenant');
    $this->objectTypeId = ModelStub::ulid('companies');
    $this->matcher = new WebhookSubscriptionMatcher;

    /** @var callable(string, WebhookEventType):QueryShape */
    $this->shapeFor = fn (string $slug, WebhookEventType $type): QueryShape => QueryShape::of(
        $this->matcher->query($this->tenantId, $this->objectTypeId, $slug, $type),
    );
});

it('resolves the object type slug inside the tenant before it looks for any subscription', function (): void {
    $shape = QueryShape::attemptedBy(fn (): Collection => $this->matcher->match(
        $this->tenantId,
        $this->objectTypeId,
        WebhookEventType::RecordUpdated,
    ));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('object_types'))->toBeTrue()
        ->and($shape->sql)->toContain('"tenant_id" = ?')
        ->and($shape->sql)->toContain('"object_types"."id" = ?')
        ->and($shape->bindings)->toBe([$this->tenantId, $this->objectTypeId]);
});

it('requires the role of a subscription to hold the view permission of the object type', function (): void {
    $shape = ($this->shapeFor)('companies', WebhookEventType::RecordUpdated);

    expect($shape->sql)->toContain('exists (select * from "roles" where "webhook_subscriptions"."role_id" = "roles"."id"')
        ->and($shape->sql)->toContain('from "permissions" inner join "role_permission"')
        ->and($shape->bindings)->toContain('companies.view');
});

it('matches only active subscriptions of the named tenant for the requested event type', function (): void {
    $shape = ($this->shapeFor)('companies', WebhookEventType::RecordUpdated);

    expect($shape->targets('webhook_subscriptions'))->toBeTrue()
        ->and($shape->sql)->toContain('where "tenant_id" = ? and "status" = ?')
        ->and($shape->bindings[0])->toBe($this->tenantId)
        ->and($shape->bindings[1])->toBe(WebhookSubscriptionStatus::Active->value)
        ->and($shape->bindings)->toContain(json_encode(WebhookEventType::RecordUpdated->value));
});

it('matches a subscription bound to the object type and one bound to every type, and nothing else', function (): void {
    $shape = ($this->shapeFor)('companies', WebhookEventType::RecordUpdated);

    expect($shape->sql)->toContain('("object_type_id" = ? or "object_type_id" is null)')
        ->and($shape->bindings)->toContain($this->objectTypeId);
});

it('follows the object type slug and the event type it was called with', function (): void {
    $shape = ($this->shapeFor)('contacts', WebhookEventType::RecordDeleted);

    expect($shape->bindings)->toContain('contacts.view')
        ->and($shape->bindings)->not->toContain('companies.view')
        ->and($shape->bindings)->toContain(json_encode(WebhookEventType::RecordDeleted->value))
        ->and($shape->bindings)->not->toContain(json_encode(WebhookEventType::RecordUpdated->value));
});
