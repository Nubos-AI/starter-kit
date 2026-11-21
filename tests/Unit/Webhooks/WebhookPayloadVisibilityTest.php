<?php

declare(strict_types=1);

use App\Enums\Webhooks\WebhookEventType;
use App\Models\CustomRecord;
use App\Models\ObjectType;
use App\Models\Role;
use App\Models\User;
use App\Models\WebhookSubscription;
use App\Support\CustomFields\EncryptedFieldKeys;
use App\Support\Webhooks\WebhookPayloadBuilder;
use Carbon\CarbonImmutable;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->objectTypeId = ModelStub::ulid('companies');
    $this->occurredAt = CarbonImmutable::createFromTimestamp(1700000000, 'UTC');

    $this->objectType = ModelStub::make(ObjectType::class, [
        'id' => $this->objectTypeId,
        'tenant_id' => $this->tenant->getKey(),
        'slug' => 'companies',
    ]);

    $this->role = ModelStub::make(Role::class, [
        'id' => ModelStub::ulid('subscription-role'),
        'tenant_id' => $this->tenant->getKey(),
        'name' => 'Webhook',
    ]);

    $this->serviceUser = ModelStub::make(User::class, [
        'id' => ModelStub::ulid('webhook-service-user'),
        'tenant_id' => $this->tenant->getKey(),
    ]);

    /** @var callable(array<string, mixed>, array<string, mixed>):WebhookSubscription */
    $this->subscription = fn (array $attributes = [], array $relations = []): WebhookSubscription => ModelStub::make(
        WebhookSubscription::class,
        [
            'id' => ModelStub::ulid('subscription'),
            'tenant_id' => $this->tenant->getKey(),
            'service_user_id' => $this->serviceUser->getKey(),
            'role_id' => $this->role->getKey(),
            'object_type_id' => $this->objectTypeId,
            ...$attributes,
        ],
        [
            'serviceUser' => $this->serviceUser,
            'role' => $this->role,
            ...$relations,
        ],
    );

    /** @var callable(array<string, mixed>, ?string):CustomRecord */
    $this->record = fn (array $data, ?string $tenantId = null): CustomRecord => ModelStub::make(
        CustomRecord::class,
        [
            'id' => ModelStub::ulid('company-record'),
            'tenant_id' => $tenantId ?? $this->tenant->getKey(),
            'object_type_id' => $this->objectTypeId,
            'version' => 4,
            'data' => $data,
        ],
        ['objectType' => $this->objectType],
    );

    /** @var callable(list<array{key: string, read: bool}>, ?Role):WebhookPayloadBuilder */
    $this->builderGranting = function (array $fields, ?Role $expectedRole = null): WebhookPayloadBuilder {
        $objectTypeId = $this->objectTypeId;

        return new WebhookPayloadBuilder(
            new class extends EncryptedFieldKeys
            {
                /**
                 * @return list<string>
                 */
                public function forObjectType(string $objectTypeId): array
                {
                    return ['secret'];
                }
            },
            function (Role $role) use ($fields, $objectTypeId, $expectedRole): array {
                if ($expectedRole !== null && $role->getKey() !== $expectedRole->getKey()) {
                    return [];
                }

                return [[
                    'objectType' => ['id' => $objectTypeId, 'key' => 'companies', 'slug' => 'companies', 'name' => 'Companies'],
                    'fields' => array_map(
                        static fn (array $field): array => [
                            'id' => ModelStub::ulid($field['key']),
                            'key' => $field['key'],
                            'read' => $field['read'],
                            'write' => false,
                        ],
                        $fields,
                    ),
                ]];
            },
        );
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('carries the full event envelope next to the visible record data', function (): void {
    $builder = ($this->builderGranting)([['key' => 'name', 'read' => true]]);

    $payload = $builder->build(
        WebhookEventType::RecordCreated,
        ($this->record)(['name' => 'Meier GmbH']),
        ($this->subscription)(),
        17,
        $this->occurredAt,
    );

    expect($payload['event'])->toBe([
        'type' => WebhookEventType::RecordCreated->value,
        'occurredAt' => $this->occurredAt->toISOString(),
        'objectType' => 'companies',
        'recordId' => ModelStub::ulid('company-record'),
        'sequence' => 17,
        'version' => 4,
    ])->and($payload['data'])->toBe(['name' => 'Meier GmbH']);
});

it('delivers only the fields the role of the subscription may read', function (): void {
    $builder = ($this->builderGranting)([
        ['key' => 'name', 'read' => true],
        ['key' => 'salary', 'read' => false],
        ['key' => 'city', 'read' => true],
    ]);

    $payload = $builder->build(
        WebhookEventType::RecordUpdated,
        ($this->record)(['name' => 'Meier GmbH', 'salary' => 90000, 'city' => 'Karlsruhe']),
        ($this->subscription)(),
        1,
        $this->occurredAt,
    );

    expect($payload['data'])->toBe(['name' => 'Meier GmbH', 'city' => 'Karlsruhe']);
});

it('asks the field matrix for the role bound to the subscription and for no other', function (): void {
    $foreignRole = ModelStub::make(Role::class, [
        'id' => ModelStub::ulid('foreign-role'),
        'tenant_id' => $this->tenant->getKey(),
        'name' => 'Sales',
    ]);

    $builder = ($this->builderGranting)(
        [['key' => 'name', 'read' => true]],
        $this->role,
    );

    $ownRole = $builder->build(
        WebhookEventType::RecordUpdated,
        ($this->record)(['name' => 'Meier GmbH']),
        ($this->subscription)(),
        1,
        $this->occurredAt,
    );

    $otherRole = $builder->build(
        WebhookEventType::RecordUpdated,
        ($this->record)(['name' => 'Meier GmbH']),
        ($this->subscription)(['role_id' => $foreignRole->getKey()], ['role' => $foreignRole]),
        1,
        $this->occurredAt,
    );

    expect($ownRole['data'])->toBe(['name' => 'Meier GmbH'])
        ->and($otherRole['data'])->toBe([]);
});

it('never delivers an encrypted field even when no field permission forbids it', function (): void {
    $builder = ($this->builderGranting)([
        ['key' => 'name', 'read' => true],
        ['key' => 'secret', 'read' => true],
    ]);

    $payload = $builder->build(
        WebhookEventType::RecordUpdated,
        ($this->record)(['name' => 'Meier GmbH', 'secret' => 'plaintext']),
        ($this->subscription)(),
        1,
        $this->occurredAt,
    );

    expect($payload['data'])->toBe(['name' => 'Meier GmbH']);
});

it('delivers an empty data object when the record carries no data at all', function (): void {
    $builder = ($this->builderGranting)([['key' => 'name', 'read' => true]]);

    $payload = $builder->build(
        WebhookEventType::RecordDeleted,
        ($this->record)([]),
        ($this->subscription)(),
        1,
        $this->occurredAt,
    );

    expect($payload['data'])->toBe([]);
});

it('refuses to build a payload for a record of another tenant', function (): void {
    $builder = ($this->builderGranting)([['key' => 'name', 'read' => true]]);
    $foreign = ($this->record)(['name' => 'Meier GmbH'], ModelStub::ulid('other-tenant'));

    expect(fn (): array => $builder->build(WebhookEventType::RecordUpdated, $foreign, ($this->subscription)(), 1, $this->occurredAt))
        ->toThrow(RuntimeException::class);
});

it('refuses to build a payload for a subscription without a service identity', function (array $attributes, array $relations): void {
    $builder = ($this->builderGranting)([['key' => 'name', 'read' => true]]);
    $subscription = ($this->subscription)($attributes, $relations);

    expect(fn (): array => $builder->build(
        WebhookEventType::RecordUpdated,
        ($this->record)(['name' => 'Meier GmbH']),
        $subscription,
        1,
        $this->occurredAt,
    ))->toThrow(RuntimeException::class);
})->with([
    'no service user id' => [['service_user_id' => null], []],
    'service user gone' => [[], ['serviceUser' => null]],
    'no role' => [['role_id' => null], ['role' => null]],
]);

it('refuses to build a payload when the object type of the record does not resolve', function (): void {
    $builder = ($this->builderGranting)([['key' => 'name', 'read' => true]]);

    $record = ModelStub::make(CustomRecord::class, [
        'id' => ModelStub::ulid('company-record'),
        'tenant_id' => $this->tenant->getKey(),
        'object_type_id' => $this->objectTypeId,
        'version' => 4,
        'data' => ['name' => 'Meier GmbH'],
    ], ['objectType' => null]);

    expect(fn (): array => $builder->build(WebhookEventType::RecordUpdated, $record, ($this->subscription)(), 1, $this->occurredAt))
        ->toThrow(RuntimeException::class);
});

it('never lets a secret of the subscription reach the payload', function (): void {
    $builder = ($this->builderGranting)([['key' => 'name', 'read' => true]]);

    $payload = $builder->build(
        WebhookEventType::RecordUpdated,
        ($this->record)(['name' => 'Meier GmbH']),
        ($this->subscription)(['secret' => 'whsec-live', 'secret_previous' => 'whsec-old']),
        1,
        $this->occurredAt,
    );

    expect(json_encode($payload))->not->toContain('whsec-live')
        ->and(json_encode($payload))->not->toContain('whsec-old');
});

it('reads the encrypted field keys of the object type of the record and of no other', function (): void {
    $builder = new WebhookPayloadBuilder(
        new EncryptedFieldKeys,
        static fn (Role $role): array => [],
    );

    $shape = QueryShape::attemptedBy(fn (): array => $builder->build(
        WebhookEventType::RecordUpdated,
        ($this->record)(['name' => 'Meier GmbH']),
        ($this->subscription)(),
        1,
        $this->occurredAt,
    ));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('field_definitions'))->toBeTrue()
        ->and($shape->bindings)->toContain($this->objectTypeId)
        ->and($shape->sql)->toContain('"is_encrypted" = ?');
});
